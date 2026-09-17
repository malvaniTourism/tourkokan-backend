<?php

namespace App\Jobs;

use App\Models\UserActivityLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LogUserActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 10;

    // Single array — built entirely in the middleware before dispatch
    public function __construct(private readonly array $payload) {}

    public function handle(): void
    {
        UserActivityLog::create(array_merge(
            $this->payload,
            ['event_type' => $this->resolveEventType($this->payload['route'] ?? '')]
        ));
    }

    /**
     * Concrete request paths, matched on suffix. Keys must match a real route —
     * eight of these previously pointed at routes that do not exist (or differed
     * in case), so those events silently fell through to `api_call` and their
     * charts read zero.
     */
    private const EVENT_TYPES = [
        'v2/auth/login'          => 'login',
        'v2/logout'              => 'logout',
        'v2/auth/register'       => 'register',
        'v2/auth/sendOtp'        => 'otp_send',
        'v2/auth/verifyOtp'      => 'otp_verify',
        'v2/auth/googleAuth'     => 'login',
        'v2/getSite'             => 'site_view',
        'v2/sites'               => 'site_list',
        'v2/addSite'             => 'site_submit',
        'v2/updateMySubmission'  => 'site_update',
        'v2/mySubmissions'       => 'site_list',
        'v2/addDeleteFavourite'  => 'favourite_toggle',
        'v2/favourites'          => 'favourite_list',
        'v2/comment'             => 'comment_add',
        'v2/updateComment'       => 'comment_update',
        'v2/deleteComment'       => 'comment_delete',
        'v2/addUpdateRating'     => 'rating_add',
        'v2/listEvents'          => 'event_list',
        'v2/myEvents'            => 'event_list',
        'v2/createEvent'         => 'event_create',
        'v2/updateEvent'         => 'event_update',
        'v2/cancelEvent'         => 'event_cancel',
        'v2/likeEvent'           => 'event_interaction',
        'v2/goingEvent'          => 'event_interaction',
        'v2/interestedEvent'     => 'event_interaction',
        'v2/shareEvent'          => 'event_interaction',
        'v2/favouriteEvent'      => 'event_interaction',
        'v2/routes'              => 'route_search',
        'v2/listroutes'          => 'route_list',
        'v2/getRouteStops'       => 'route_stops_view',
        'v2/getCategory'         => 'category_view',
        'v2/listcategories'      => 'category_list',
        'v2/landingpage'         => 'landing_page',
        'v2/updateProfile'       => 'profile_update',
        'v2/user-profile'        => 'profile_view',
        'v2/requestRole'         => 'role_request',
        'v2/uploadSiteGallery'   => 'gallery_upload',
        'v2/uploadEventGallery'  => 'gallery_upload',
        'v2/recordBannerImpression' => 'banner_impression',
        'v2/recordBannerClick'   => 'banner_click',
        'v2/addQuery'            => 'contact_query',
        'v2/addGuestQuery'       => 'contact_query',
        'v2/myMessages'          => 'message_view',
        'v2/listProducts'        => 'product_list',
        'v2/productDetail'       => 'product_view',
    ];

    /**
     * Routes carrying a path parameter. The logger records the concrete path
     * (/api/v2/events/some-slug), so these can only be matched on prefix.
     */
    private const EVENT_TYPE_PREFIXES = [
        'v2/events/' => 'event_view',
        'v2/rating/' => 'rating_view',
    ];

    private function resolveEventType(string $route): string
    {
        $path = ltrim($route, '/');

        foreach (self::EVENT_TYPES as $segment => $type) {
            if (str_ends_with($path, $segment)) {
                return $type;
            }
        }

        foreach (self::EVENT_TYPE_PREFIXES as $prefix => $type) {
            if (str_contains($path, $prefix)) {
                return $type;
            }
        }

        return 'api_call';
    }
}
