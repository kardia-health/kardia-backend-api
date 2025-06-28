<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserDashboardShouldUpdate
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // Event ini akan membawa informasi tentang pengguna mana yang datanya berubah
    public function __construct(public User $user) {}
}
