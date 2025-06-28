<?php

namespace App\Repositories;

use App\Events\UserDashboardShouldUpdate;
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
    return Cache::remember($cacheKey, now()->addMinutes(2), function () use ($conversation, $limit) {
      Log::info("CACHE MISS: Mengambil pesan untuk percakapan slug: {$conversation->slug}");
      return $conversation->chatMessages()->latest()->take($limit)->get()->reverse();
    });
  }

  /**
   * Membuat pesan baru dan menghapus cache yang relevan.
   */
  public function createMessage(Conversation $conversation, string $role, string|array $content): void
  {
    $contentToStore = is_array($content) ? json_encode($content) : $content;
    $conversation->chatMessages()->create(['role' => $role, 'content' => $contentToStore]);

    // Hapus cache yang menjadi tanggung jawabnya sendiri
    $this->forgetMessagesCache($conversation);

    // [BEST PRACTICE] Teriakkan pengumuman bahwa ada aktivitas baru dari pengguna ini.
    // Biarkan para Listener yang membersihkan cache lain yang relevan.
    UserDashboardShouldUpdate::dispatch($conversation->userProfile->user);
  }


  /**
   * Helper untuk menghapus cache riwayat pesan untuk SATU thread.
   */
  private function forgetMessagesCache(Conversation $conversation): void
  {
    $cacheKey = "conversation:{$conversation->slug}:messages";
    Cache::forget($cacheKey);
    Log::info("CACHE FORGET: Cache pesan dihapus untuk thread slug: {$conversation->slug}");
  }
}
