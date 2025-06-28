<?php

namespace App\Repositories;

use App\Models\CoachingThread;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CoachingMessageRepository
{
  public function __construct(
    private CoachingThreadRepository $threadRepository,
    private CoachingRepository $coachingRepository,
    private DashboardRepository $dashboardRepository


  ) {}

  public function getLatestMessages(CoachingThread $thread, int $limit = 10): Collection
  {
    // Kunci cache spesifik untuk daftar pesan di thread ini
    $cacheKey = "coaching_thread:{$thread->slug}:messages";

    // Simpan cache selama 5 menit. Cukup singkat untuk percakapan.
    return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($thread, $limit) {
      Log::info("CACHE MISS: Mengambil pesan untuk thread slug: {$thread->slug}");
      return $thread->messages()->latest()->take($limit)->get()->reverse();
    });
  }


  public function createMessage(CoachingThread $thread, string $role, string|array $content): void
  {
    // [LEBIH ROBUST] Cek jika konten adalah array, otomatis encode.
    $contentToStore = is_array($content) ? json_encode($content) : $content;

    $thread->messages()->create([
      'role' => $role,
      'content' => $contentToStore,
    ]);

    $program = $thread->program;
    $user = $program->userProfile->user;

    // 1. Hapus cache detail pesan thread ini sendiri.
    $this->forgetMessagesCache($thread);

    // 2. Hapus cache daftar thread di program induknya.
    $this->threadRepository->forgetThreadsCache($program);

    // 3. Hapus cache detail program induknya.
    $this->coachingRepository->forgetProgramDetailCache($program);
    
    // 4. [TERPENTING] Hapus cache dasbor utama milik pengguna.
    $this->dashboardRepository->forgetDashboardCache($user);
  }

  public function getMessagesForThreadSince(CoachingThread $thread, \Carbon\Carbon $sinceDate): Collection
  {
    // Query ini spesifik dan tidak perlu di-cache karena hanya dijalankan oleh background job
    return $thread->messages()->where('created_at', '>=', $sinceDate)->latest()->get();
  }

  public function forgetMessagesCache(CoachingThread $thread): void
  {
    $cacheKey = "coaching_thread:{$thread->slug}:messages";
    Cache::forget($cacheKey);
    Log::info("CACHE FORGET: Cache pesan dihapus untuk thread slug: {$thread->slug}");

    // Kita juga perlu menghapus cache daftar percakapan, karena 'updated_at' dari
    // thread dan program induknya akan berubah, yang mempengaruhi urutan daftar.
    // Kita asumsikan ada CoachingProgramRepository yang di-inject jika perlu,
    // atau kita bisa memanggilnya secara statis jika metodenya statis.
    // Untuk sekarang, invalidasi cache thread sudah cukup.
  }
}
