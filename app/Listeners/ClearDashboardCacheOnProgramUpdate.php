<?php

namespace App\Listeners;

use App\Events\CoachingProgramUpdated;
use App\Repositories\DashboardRepository;

class ClearDashboardCacheOnProgramUpdate
{
    public function __construct(private DashboardRepository $dashboardRepository) {}

    public function handle(CoachingProgramUpdated $event): void
    {
        $this->dashboardRepository->forgetDashboardCache($event->program->userProfile->user);
    }
}
