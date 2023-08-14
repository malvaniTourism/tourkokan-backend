<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use SaiAshirwadInformatia\SecureIds\Facades\SecureIds;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // SecureIds::load();
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
