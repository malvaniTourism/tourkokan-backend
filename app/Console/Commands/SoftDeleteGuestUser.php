<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Soft deletes guest accounts once they are older than the retention window.
 *
 * Guest registration (`is_guest` on /register) never stores a flag — it only relaxes
 * validation so the app can hand out a JWT for a name alone. What it leaves behind is a
 * row with no email and no mobile, which normal registration can never produce: there,
 * email and mobile are `required_if` each other, so a real account always carries at
 * least one. That absence is the marker, and it costs no extra column.
 *
 * The same rule makes conversion self-protecting — the moment a guest saves an email or
 * mobile from their profile, they drop out of this sweep permanently.
 *
 * Soft delete is the lock: the SoftDeletes global scope hides the row from
 * EncryptedUserProvider::retrieveById(), so any JWT still held by the device stops
 * authenticating on its very next request. Comments, favourites and wallet rows are left
 * intact and come back with the user if an account is ever restored.
 */
class SoftDeleteGuestUser extends Command
{
    protected $signature = 'users:soft-delete-guests
                            {--hours=48 : Soft delete guest accounts older than this}
                            {--dry-run : Report what would be deleted without touching anything}';

    protected $description = 'Soft delete guest accounts older than the retention window';

    public function handle(): int
    {
        $hours  = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);

        $stale = $this->staleGuests($cutoff);
        $total = $stale->count();

        if ($total === 0) {
            $this->info("No guest accounts older than {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Would soft delete {$total} guest account(s) created before {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $deleted = 0;

        // Chunked so a large backlog never becomes one table-wide UPDATE. Rows leave the
        // result set as they are deleted, but the id cursor only moves forward.
        $stale->select('id')->chunkById(500, function (Collection $guests) use (&$deleted) {
            $deleted += User::whereIn('id', $guests->pluck('id'))->delete();
        });

        $this->info("Soft deleted {$deleted} guest account(s) created before {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }

    /**
     * Guests past the retention window.
     *
     * The role guard is a belt-and-braces check: registration assigns `tourist`, so an
     * account holding any other role is a staff or vendor account that happens to have no
     * contact details, and is never swept.
     */
    private function staleGuests(\Illuminate\Support\Carbon $cutoff)
    {
        return User::whereNull('email')
            ->whereNull('mobile')
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('roles', fn($query) => $query->where('code', '!=', 'tourist'));
    }
}
