<?php

namespace App\Providers;

use App\Enums\SendStatus;
use App\Models\CampaignGroup;
use App\Services\Campaigns\CampaignRunner;
use App\Services\WhatsApp\FakeWhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceInterface;
use App\Support\Navigation;
use App\Support\Settings;
use App\Support\StorageUsage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per process, so a worker keeps its browser session between jobs.
        $this->app->singleton(WhatsAppServiceInterface::class, fn () => match (config('educationhub.whatsapp.driver')) {
            'fake' => new FakeWhatsAppService,
            default => throw new InvalidArgumentException('Unknown WHATSAPP_DRIVER "'.config('educationhub.whatsapp.driver').'". Use "fake" until the Playwright worker is installed.'),
        });

        $this->app->singleton(CampaignRunner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Settings::apply();

        View::composer('components.layout.sidebar', function ($view) {
            $view->with([
                'navItems' => Navigation::items(),
                'failedCount' => CampaignGroup::where('status', SendStatus::Failed)->count(),
                'storage' => StorageUsage::summary(),
            ]);
        });
    }
}
