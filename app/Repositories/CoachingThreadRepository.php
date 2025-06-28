<?php

namespace App\Repositories;

use App\Models\CoachingProgram;
use App\Models\CoachingThread;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CoachingThreadRepository
{
  /**
   * Membuat thread baru dan menghapus cache daftar thread.
   */
  public function createThread(CoachingProgram $program, array $data): CoachingThread
  {
    $thread = $program->threads()->create([
      'title' => $data['title'],
      'slug' => Str::ulid(),
    ]);

    $this->forgetThreadsCache($program);
    return $thread;
  }

  /**
   * [BARU] Mengambil detail satu thread spesifik beserta semua pesannya.
   */
  public function findWithMessagesBySlug(string $slug): ?CoachingThread
  {
    $cacheKey = "thread_detail:{$slug}";
    // Cache selama 2 menit, cukup untuk percakapan
    return Cache::remember($cacheKey, now()->addMinutes(2), function () use ($slug) {
      Log::info("CACHE MISS: Mengambil detail thread & pesan untuk slug: {$slug}");
      return CoachingThread::where('slug', $slug)
        ->with('messages') // Eager load semua pesan
        ->first();
    });
  }

  /**
   * [BARU] Memperbarui judul sebuah thread dan menghapus cache yang relevan.
   */
  public function updateTitle(CoachingThread $thread, string $newTitle): bool
  {
    $result = $thread->update(['title' => $newTitle]);

    // Hapus cache daftar thread karena judulnya berubah
    $this->forgetThreadsCache($thread->program);
    $this->forgetThreadDetailCache($thread);


    return $result;
  }

  /**
   * [BARU] Menghapus sebuah thread dan menghapus cache yang relevan.
   */
  public function deleteThread(CoachingThread $thread): bool
  {
    // Simpan referensi ke program induk sebelum thread dihapus
    $program = $thread->program;

    $result = $thread->delete();

    // Hapus cache daftar thread karena salah satu itemnya hilang
    if ($result) {
      $this->forgetThreadsCache($program);
      $this->forgetThreadDetailCache($thread);
    }

    return $result;
  }

  /**
   * Helper untuk menghapus cache daftar thread.
   */
  public function forgetThreadsCache(CoachingProgram $program): void
  {
    // Bangun ulang URL persis seperti yang diakses oleh frontend
    $path = "api/v1/coaching/programs/{$program->slug}/threads"; // Sesuaikan jika prefix Anda berbeda

    // Bangun ulang kunci cache persis seperti yang dibuat oleh Middleware
    $cacheKey = "http_response:user:{$program->userProfile->user->id}:{$path}";

    Cache::forget($cacheKey);
    Log::info("HTTP CACHE FORGET: Cache dihapus untuk URL: {$path}");
  }

  /**
   * [BARU] Helper untuk menghapus cache detail dari satu thread spesifik.
   */
  public function forgetThreadDetailCache(CoachingThread $thread): void
  {
    $cacheKey = "thread_detail:{$thread->slug}";
    Cache::forget($cacheKey);
    Log::info("CACHE FORGET: Cache detail dihapus untuk thread: {$thread->slug}");
  }
}
