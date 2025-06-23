<?php

namespace App\Repositories;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatMessageRepository
{
  /**
   * Mengambil 10 pesan terakhir dari sebuah percakapan, dengan caching.
   */
  public function getLatestMessages(Conversation $conversation, int $limit = 10): Collection
  {
    $cacheKey = "conversation:{$conversation->slug}:messages";
    // Cache riwayat pesan selama 1 jam
    return Cache::remember($cacheKey, now()->addHours(1), function () use ($conversation, $limit) {
      Log::info("CACHE MISS: Mengambil pesan untuk percakapan slug: {$conversation->slug}");
      return $conversation->chatMessages()->latest()->take($limit)->get()->reverse();
    });
  }

  /**
   * Membuat pesan baru dan menghapus cache yang relevan.
   */
  public function createMessage(Conversation $conversation, string $role, string $content): void
  {
    $conversation->chatMessages()->create([
      'role' => $role,
      'content' => $content,
    ]);

    // Hapus cache detail percakapan ini karena ada pesan baru
    $this->forgetConversationCache($conversation);
  }

  /**
   * Helper untuk menghapus cache detail percakapan.
   */
  public function forgetConversationCache(Conversation $conversation): void
  {
    $cacheKey = "conversation:{$conversation->slug}:messages";
    Cache::forget($cacheKey);
    // Juga hapus cache daftar percakapan karena 'updated_at' berubah
    Cache::forget("user:{$conversation->userProfile->user->id}:conversations_list");
  }
}
