<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UserProfileRepository
{
  /**
   * Mengambil data profil untuk seorang pengguna, dengan prioritas dari cache.
   */
  public function getProfileForUser(User $user): ?UserProfile
  {
    // Tentukan kunci cache yang unik untuk profil pengguna ini.
    $cacheKey = "user_profile:{$user->id}";

    // Ambil dari cache. Jika tidak ada, jalankan query lalu simpan hasilnya selamanya.
    // Kita menggunakan 'forever' karena data profil hanya berubah jika di-edit.
    return Cache::rememberForever($cacheKey, function () use ($user) {
      // Blok ini HANYA akan dijalankan jika cache kosong (cache miss).
      Log::info("CACHE MISS: Mengambil UserProfile dari DB untuk user ID: {$user->id}");

      // Ambil profil dan relasi user-nya untuk menghindari N+1 query.
      return $user->profile()->with('user')->first();
    });
  }

  /**
   * Membuat atau memperbarui profil pengguna dan menghapus cache yang relevan.
   */
  public function updateOrCreateForUser(User $user, array $validatedData): UserProfile
  {
    // Gunakan updateOrCreate untuk efisiensi.
    $profile = UserProfile::updateOrCreate(
      ['user_id' => $user->id],
      $validatedData
    );

    // [SANGAT PENTING] Hapus cache profil untuk pengguna ini setelah datanya diubah.
    $this->forgetProfileCache($user);

    return $profile;
  }

  /**
   * Helper untuk menghapus cache profil pengguna.
   */
  public function forgetProfileCache(User $user): void
  {
    $cacheKey = "user_profile:{$user->id}";
    Cache::forget($cacheKey);
    Log::info("CACHE FORGET: Cache UserProfile dihapus untuk user ID: {$user->id}");
  }
}
