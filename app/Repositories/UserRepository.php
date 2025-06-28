<?php

namespace App\Repositories;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UserRepository
{
    public function getAuthenticatedUserResource(User $user): UserResource
    {
        // Kunci cache sekarang bisa lebih sederhana
        $cacheKey = "user:{$user->id}:resource";

        // Gunakan cache biasa tanpa tags
        return Cache::remember($cacheKey, now()->addHours(24), function () use ($user) {
            Log::info("CACHE MISS: Mengambil UserResource dari DB untuk user ID: {$user->id}");

            // Eager load relasi profil untuk disertakan dalam resource.
            return new UserResource($user->loadMissing('profile'));
        });
    }

    /**
     * [DIREFAKTOR] Helper untuk menghapus cache yang berhubungan dengan pengguna ini.
     */
    public function forgetUserCache(User $user): void
    {
        // Hapus cache satu per satu berdasarkan key
        $cacheKeys = [
            "user:{$user->id}:resource",
            // Tambahkan key lain yang berhubungan dengan user ini jika ada
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
            Log::info("CACHE FORGOTTEN: Cache key '{$key}' telah dihapus.");
        }
    }
}
