<?php

namespace App\Repositories;

use App\Events\UserDashboardShouldUpdate;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UserProfileRepository
{
  /**
   * Membuat atau memperbarui profil pengguna dan menghapus cache yang relevan.
   */
  public function updateOrCreateForUser(User $user, array $validatedData): UserProfile
  {
    // updateOrCreate akan memicu event 'saved' yang membersihkan cache model ini secara otomatis.
    $profile = UserProfile::updateOrCreate(
      ['user_id' => $user->id],
      $validatedData
    );

    // [BEST PRACTICE] Teriakkan pengumuman bahwa data pengguna ini berubah,
    // agar cache lain (seperti Dasbor) bisa ikut terhapus.
    UserDashboardShouldUpdate::dispatch($user);

    Log::info("Profile updated for user ID: {$user->id}, dashboard cache invalidation dispatched.");

    // Muat relasi user agar siap ditampilkan oleh resource
    return $profile->load('user');
  }
}
