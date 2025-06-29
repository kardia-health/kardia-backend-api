<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CulinaryPreferenceRepository
{
  /**
   * Mengambil data preferensi kuliner.
   * Prioritas utama dari Cache, jika tidak ada baru dari Database.
   */
  public function get(User $user): array
  {
    // Definisikan kunci cache yang unik dan tag untuk pengguna ini.
    $cacheKey = $this->getCacheKey($user);

    // Gunakan ->tags() agar mudah dihapus nanti.
    // Cache disimpan selama 1 minggu, karena preferensi ini jarang berubah.
    return Cache::remember($cacheKey, now()->addWeek(), function () use ($user) {
      Log::info("CACHE MISS: Mengambil Culinary Preferences dari DB untuk user ID: {$user->id}");
      // Memberikan nilai default array kosong jika belum pernah diatur.
      return $user->profile->culinary_preferences ?? [];
    });
  }

  /**
   * Memperbarui data preferensi kuliner milik pengguna.
   * Setelah update, cache HARUS dihapus.
   */
  public function update(User $user, array $preferences): bool
  {
    $updated = $user->profile->update([
      'culinary_preferences' => $preferences
    ]);

    // Jika update berhasil, hapus cache yang relevan.
    if ($updated) {
      $this->forgetCache($user);
    }

    return $updated;
  }

  /**
   * Helper untuk menghapus cache yang berhubungan dengan preferensi pengguna ini.
   * Menggunakan tag agar lebih efisien.
   */
  public function forgetCache(User $user): void
  {
    $cacheKey = $this->getCacheKey($user);
    Cache::forget($cacheKey); // Langsung hapus kunci spesifik, bukan via tag.
    Log::info("CACHE FORGOTTEN: Cache preferensi kuliner dihapus untuk user ID: {$user->id} (Key: {$cacheKey})");
  }

  private function getCacheKey(User $user): string
  {
    return "user:{$user->id}:culinary_preferences";
  }
}
