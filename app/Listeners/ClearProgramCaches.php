<?php

namespace App\Listeners;

use App\Events\CoachingProgramUpdated;
use App\Repositories\CoachingRepository;

class ClearProgramCaches
{
    public function __construct(private CoachingRepository $coachingRepository) {}

    public function handle(CoachingProgramUpdated $event): void
    {
        // Panggil semua metode pembersihan cache dari repository yang relevan
        $this->coachingRepository->forgetActiveProgramCache($event->program->userProfile->user);
        $this->coachingRepository->forgetProgramDetailCache($event->program);
    }
}
