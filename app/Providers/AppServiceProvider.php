<?php

namespace App\Providers;

use App\Enums\SendStatus;
use App\Models\CampaignGroup;
use App\Support\Navigation;
use App\Support\StorageUsage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('components.layout.sidebar', function ($view) {
            $view->with([
                'navItems' => Navigation::items(),
                'failedCount' => CampaignGroup::where('status', SendStatus::Failed)->count(),
                'storage' => StorageUsage::summary(),
            ]);
        });
    }
}
