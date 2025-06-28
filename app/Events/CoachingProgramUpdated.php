<?php

namespace App\Events;

use App\Models\CoachingProgram;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CoachingProgramUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // Event ini akan membawa objek program yang baru saja di-update
    public function __construct(public CoachingProgram $program) {}
}
