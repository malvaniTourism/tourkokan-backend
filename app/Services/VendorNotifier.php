<?php

namespace App\Services;

use App\Models\AdminMessage;
use App\Models\Product;
use App\Models\ProductLead;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Tells a vendor something happened.
 *
 * Writes to the existing `admin_messages` inbox — which the app already reads through
 * myMessages / unreadMessageCount — and additionally pushes if the device has a token.
 * The inbox row is the durable record; push is a best-effort nudge on top. A vendor with no
 * device registered still sees everything next time they open the app, which matters
 * because push tokens are sparse.
 *
 * **Nothing here may break its caller.** A notification failing must never fail an approval
 * or lose a lead, so every path is wrapped and logged rather than thrown.
 */
class VendorNotifier
{
    public function __construct(private FirebaseService $firebase)
    {
    }

    /**
     * A customer reached out about a listing.
     *
     * The one notification that actually earns money: the platform's promise is that it
     * sends leads, and an enquiry nobody hears about is a lost booking. Fired on every
     * recordProductLead.
     */
    public function leadReceived(Product $product, ProductLead $lead): void
    {
        $owner = $product->site?->user_id;

        if (!$owner) {
            return;
        }

        $channel = match ($lead->lead_type) {
            'call'       => 'wants to call you about',
            'whatsapp'   => 'messaged you on WhatsApp about',
            'directions' => 'looked up directions to',
            default      => 'sent an enquiry about',
        };

        $this->send(
            $owner,
            'lead',
            'New enquiry',
            "Someone {$channel} \"{$product->name}\"."
                . ($lead->message ? " They said: \"{$lead->message}\"" : ''),
            ['product_id' => $product->id, 'lead_id' => $lead->id, 'lead_type' => $lead->lead_type]
        );
    }

    public function productApproved(Product $product): void
    {
        $this->send(
            $product->site?->user_id,
            'product_approved',
            'Listing approved',
            "\"{$product->name}\" is now live and visible to customers.",
            ['product_id' => $product->id]
        );
    }

    public function productRejected(Product $product, string $reason): void
    {
        $this->send(
            $product->site?->user_id,
            'product_rejected',
            'Listing needs changes',
            "\"{$product->name}\" was not approved. Reason: {$reason}",
            ['product_id' => $product->id]
        );
    }

    public function siteApproved(Site $site): void
    {
        $this->send(
            $site->user_id,
            'site_approved',
            'Business approved',
            "\"{$site->name}\" is approved. You can now publish products against it.",
            ['site_id' => $site->id]
        );
    }

    public function siteRejected(Site $site, string $reason): void
    {
        $this->send(
            $site->user_id,
            'site_rejected',
            'Business needs changes',
            "\"{$site->name}\" was not approved. Reason: {$reason}",
            ['site_id' => $site->id]
        );
    }

    public function vendorRoleGranted(User $user): void
    {
        $this->send(
            $user->id,
            'vendor_approved',
            'You are now a vendor',
            'Your vendor request was approved. Register your business to start listing.',
            []
        );
    }

    /**
     * Inbox row first, push second — the record must survive a push failure.
     */
    private function send(?int $userId, string $type, string $subject, string $body, array $data): void
    {
        if (!$userId) {
            return;
        }

        try {
            AdminMessage::create([
                'user_id'   => $userId,
                'admin_id'  => null,          // system-generated
                'type'      => $type,
                'subject'   => $subject,
                'message'   => $body,
                'meta_data' => $data,
                'is_read'   => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('VendorNotifier: inbox write failed', ['user' => $userId, 'type' => $type, 'error' => $e->getMessage()]);
        }

        try {
            $this->firebase->sendToUser(
                $userId,
                ['title' => $subject, 'body' => $body],
                ['type' => $type] + array_map('strval', $data)
            );
        } catch (\Throwable $e) {
            // No device token, Firebase misconfigured, network down — all survivable.
            Log::info('VendorNotifier: push skipped', ['user' => $userId, 'type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
