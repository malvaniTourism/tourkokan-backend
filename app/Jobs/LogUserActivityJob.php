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

    private function resolveEventType(string $route): string
    {
        $map = [
            'v2/auth/login'          => 'login',
            'v2/auth/logout'         => 'logout',
            'v2/auth/register'       => 'register',
            'v2/auth/sendOtp'        => 'otp_send',
            'v2/auth/verifyOtp'      => 'otp_verify',
            'v2/getSite'             => 'site_view',
            'v2/listCities'          => 'site_list',
            'v2/addSite'             => 'site_submit',
            'v2/updateMySubmission'  => 'site_update',
            'v2/favourite'           => 'favourite_toggle',
            'v2/addComment'          => 'comment_add',
            'v2/addUpdateRating'     => 'rating_add',
            'v2/getEvent'            => 'event_view',
            'v2/listEvents'          => 'event_list',
            'v2/createEvent'         => 'event_create',
            'v2/updateEvent'         => 'event_update',
            'v2/cancelEvent'         => 'event_cancel',
            'v2/eventInteraction'    => 'event_interaction',
            'v2/routes'              => 'route_search',
            'v2/listroutes'          => 'route_list',
            'v2/getRouteStops'       => 'route_stops_view',
            'v2/getCategory'         => 'category_view',
            'v2/listCategories'      => 'category_list',
            'v2/landingpage'         => 'landing_page',
            'v2/updateProfile'       => 'profile_update',
            'v2/requestRole'         => 'role_request',
            'v2/uploadSiteGallery'   => 'gallery_upload',
            'v2/uploadEventGallery'  => 'gallery_upload',
            'v2/banners'             => 'banner_fetch',
            'v2/addQuery'            => 'contact_query',
            'v2/myMessages'          => 'message_view',
        ];

        foreach ($map as $segment => $type) {
            if (str_ends_with(ltrim($route, '/'), $segment)) {
                return $type;
            }
        }

        return 'api_call';
    }
}
