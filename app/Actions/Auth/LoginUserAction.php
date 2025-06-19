<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class LoginUserAction
{
    public function execute(array $credentials): ?User
    {
        if (!Auth::attempt($credentials)) {
            return null;
        }

        /** @var User $user */
        $user = Auth::user();

        // Revoke all old tokens to ensure single session
        $user->tokens()->delete();

        return $user;
    }
}
