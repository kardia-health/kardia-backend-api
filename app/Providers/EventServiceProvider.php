<?php

namespace App\Providers;

use App\Events\CoachingProgramUpdated;
use App\Events\UserDashboardShouldUpdate;
use App\Listeners\ClearDashboardCacheOnProgramUpdate;
use App\Listeners\ClearProgramCaches;
use App\Listeners\ClearUserConversationListCache;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        // INI ADALAH MAPPING YANG DIBUAT OTOMATIS OLEH ARTISAN
        CoachingProgramUpdated::class => [
            ClearProgramCaches::class,
            ClearDashboardCacheOnProgramUpdate::class,

        ],
        UserDashboardShouldUpdate::class => [
            // ClearDashboardCacheOnProgramUpdate::class,
            ClearUserConversationListCache::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
