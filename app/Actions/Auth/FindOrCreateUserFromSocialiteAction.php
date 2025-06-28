<?php

namespace App\Actions\Auth;

use App\Events\UserDashboardShouldUpdate;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class FindOrCreateUserFromSocialiteAction
{
    public function execute(string $provider, SocialiteUser $socialiteUser): User
    {
        // Use updateOrCreate to find a user by email or create a new one.
        // This is more efficient than checking for existence first.
        $user = User::updateOrCreate(
            [
                'email' => $socialiteUser->getEmail(),
            ],
            [
                'password' => Hash::make(Str::random(32)), // Create a secure random password
                $provider . '_id' => $socialiteUser->getId(),
                'email_verified_at' => now(), // User is verified via social provider
            ]
        );

        // If the user already existed but didn't have the provider_id, set it.
        // This handles cases where a user registered normally first, then uses social login.
        if (!$user->wasRecentlyCreated && !$user->{$provider . '_id'}) {
            $user->forceFill([
                $provider . '_id' => $socialiteUser->getId(),
            ])->save();
        }

        UserDashboardShouldUpdate::dispatch($user);

        // Ensure any previous sessions/tokens are invalidated for security.
        $user->tokens()->delete();

        return $user;
    }
}
