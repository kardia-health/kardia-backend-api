<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\NewAccessToken;

/**
 * Token Service
 * 
 * Handles token creation, validation, and revocation operations.
 */
class TokenService
{
  /**
   * Create a new access token for the user
   */
  public function createTokenForUser(User $user, string $tokenName): array
  {
    $token = $user->createToken($tokenName);

    return [
      'token' => $token->plainTextToken,
      'expires_at' => $this->getTokenExpirationDate($token),
    ];
  }

  /**
   * Revoke the current access token
   */
  public function revokeCurrentToken(Authenticatable $user): bool
  {
    return $user->currentAccessToken()->delete();
  }

  /**
   * Revoke all tokens for the user
   */
  public function revokeAllTokens(User $user): int
  {
    return $user->tokens()->delete();
  }

  /**
   * Get token expiration date if configured
   */
  private function getTokenExpirationDate(NewAccessToken $token): ?Carbon
  {
    $expiration = config('sanctum.expiration');

    if (!$expiration) {
      return null;
    }

    return Carbon::now()->addMinutes($expiration);
  }
}
