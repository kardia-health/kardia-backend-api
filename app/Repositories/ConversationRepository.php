<?php

namespace App\Repositories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ConversationRepository
{
  /**
   * Mengambil daftar percakapan pengguna, dengan caching.
   */
  public function getUserConversations(User $user): Collection
  {
    $cacheKey = "user:{$user->id}:conversations_list";

    // Ambil dari cache. Jika tidak ada, jalankan fungsi dan simpan hasilnya selama 10 menit.
    return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($user) {
      return $user->profile->conversations()
        ->with(['chatMessages' => function ($query) {
          $query->latest(); // Eager load pesan terakhir untuk snippet
        }])
        ->latest('updated_at')
        ->get();
    });
  }

  /**
   * Menemukan satu percakapan detail, dengan caching.
   */
  public function findBySlug(string $slug): ?Conversation
  {
    $cacheKey = "conversation:{$slug}:details";

    return Cache::remember($cacheKey, now()->addHours(1), function () use ($slug) {
      // Muat relasi pesan agar ikut ter-cache
      return Conversation::where('slug', $slug)->with('chatMessages')->first();
    });
  }

  /**
   * Menghapus cache daftar percakapan milik seorang pengguna.
   * Dipanggil setiap kali ada perubahan (chat baru, judul diubah, chat dihapus).
   */
  public static function forgetUserConversationsCache(User $user): void
  {
    Cache::forget("user:{$user->id}:conversations_list");
  }

  /**
   * Menghapus cache detail dari satu percakapan spesifik.
   * Dipanggil setiap kali ada pesan baru atau judul diubah.
   */
  public static function forgetConversationDetailCache(Conversation $conversation): void
  {
    Cache::forget("conversation:{$conversation->slug}:details");
  }
}
