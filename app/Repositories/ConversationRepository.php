<?php

namespace App\Repositories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;


class ConversationRepository
{
  /**
   * [BARU] Membuat percakapan baru dan langsung menghapus cache yang relevan.
   */
  public function createConversation(User $user, string $userMessage): Conversation
  {
    $conversation = $user->profile->conversations()->create([
      'role' => 'user',
      'title' => Str::limit($userMessage, 45),
      'slug' => Str::ulid(),
    ]);

    // Invalidasi terjadi secara otomatis di sini
    $this->forgetUserConversationsCache($user);

    return $conversation;
  }

  /**
   * [BARU] Mengupdate judul dan menghapus semua cache yang relevan.
   */
  public function updateTitle(Conversation $conversation, string $newTitle): bool
  {
    $result = $conversation->update(['title' => $newTitle]);
    if ($result) {
      $this->invalidateRelatedCaches($conversation);
    }
    return $result;
  }

  /**
   * [BARU] Menghapus percakapan dan menghapus semua cache yang relevan.
   */
  public function deleteConversation(Conversation $conversation): bool
  {
    // Panggil invalidasi SEBELUM menghapus, selagi kita masih punya datanya
    $this->invalidateRelatedCaches($conversation);
    return $conversation->delete();
  }

  // --- KUMPULAN HELPER INVALIDASI CACHE ---

  /**
   * [DIUBAH MENJADI PRIVATE] Helper untuk menghapus cache daftar percakapan.
   */
  public function forgetUserConversationsCache(User $user): void
  {
    $cacheKey = "user:{$user->id}:conversations_list";
    Cache::forget($cacheKey);
  }

  /**
   * [DIUBAH MENJADI PRIVATE] Helper untuk menghapus cache detail percakapan.
   */
  private function forgetConversationDetailCache(Conversation $conversation): void
  {
    $cacheKey = "conversation_detail:{$conversation->slug}"; // Sesuaikan dengan key di findBySlug
    Cache::forget($cacheKey);
  }

  /**
   * [BEST PRACTICE] Satu metode privat untuk memanggil semua invalidasi yang diperlukan.
   */
  private function invalidateRelatedCaches(Conversation $conversation): void
  {
    $this->forgetConversationDetailCache($conversation);
    $this->forgetUserConversationsCache($conversation->userProfile->user);
  }


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
}
