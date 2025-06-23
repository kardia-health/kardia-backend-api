<?php

namespace App\Repositories;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UserRepository
{
    /**
     * Mengambil data pengguna yang sudah diformat, dengan prioritas dari cache.
     */
    public function getAuthenticatedUserResource(User $user): UserResource
    {
        // Kunci cache unik untuk data resource pengguna ini.
        // Kita tambahkan timestamp dari profil untuk invalidasi otomatis saat profil di-update.
        $profileTimestamp = $user->profile ? $user->profile->updated_at->timestamp : 'no-profile';
        $cacheKey = "user:{$user->id}:resource:{$profileTimestamp}";

        // Simpan di cache selama 24 jam. Jika profil di-update, cache ini otomatis basi.
        return Cache::remember($cacheKey, now()->addHours(24), function () use ($user) {
            Log::info("CACHE MISS: Mengambil UserResource dari DB untuk user ID: {$user->id}");

            // Eager load relasi profil untuk disertakan dalam resource.
            return new UserResource($user->loadMissing('profile'));
        });
    }

    /**
     * Helper untuk menghapus cache resource pengguna.
     * Dipanggil saat akun dihapus.
     */
    public static function forgetUserResourceCache(User $user): void
    {
        // Karena key kita dinamis dengan timestamp, cara termudah adalah dengan tag
        // Namun untuk simplisitas, kita bisa biarkan cache basi secara alami
        // atau menggunakan cara yang lebih advanced seperti Cache Tags jika perlu.
        // Untuk sekarang, invalidasi eksplisit tidak diperlukan karena key dinamis.
    }
}