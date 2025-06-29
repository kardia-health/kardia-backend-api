<?php

namespace App\Repositories;

use App\Events\UserDashboardShouldUpdate;
use App\Models\CoachingProgram;
use App\Models\CoachingTask;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\GeminiCoachService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;


class CoachingRepository
{
  public function __construct(private GeminiCoachService $geminiService) {}

  /**
   * Mengambil program coaching aktif milik pengguna, dari cache atau DB.
   */
  public function findActiveProgramForUser(User $user): ?CoachingProgram
  {
    if (!$user->profile) return null;
    $cacheKey = "user:{$user->id}:active_coaching_program";

    return Cache::remember($cacheKey, now()->addHour(), function () use ($user) {
      Log::info("CACHE MISS: Mengambil program coaching aktif dari DB untuk user ID: {$user->id}");
      // Eager load semua relasi yang dibutuhkan untuk ditampilkan di dasbor program
      return $user->profile->coachingPrograms()
        ->where('status', 'active')
        ->with(['weeks.tasks', 'threads.messages' => function ($query) {
          $query->latest()->limit(10);
        }])->first();
    });
  }

  /**
   * [BARU & PENDEKATAN TERBAIK] Mengambil detail program dari slug, dengan caching.
   */
  public function findAndCacheProgramBySlug(string $slug): ?CoachingProgram
  {
    $cacheKey = "program_detail:{$slug}";

    return Cache::remember($cacheKey, now()->addMinutes(60), function () use ($slug) {
      Log::info("CACHE MISS: Mengambil detail program {$slug} dari DB.");

      return CoachingProgram::where('slug', $slug)
        ->with(['riskAssessment', 'weeks.tasks', 'threads.messages']) // Eager load semua yang dibutuhkan
        ->firstOrFail();
    });
  }

  /**
   * Helper untuk menghapus cache program aktif.
   * Akan kita panggil saat program selesai, dijeda, atau dibatalkan.
   */
  public function forgetActiveProgramCache(User $user): void
  {
    $cacheKey = "user:{$user->id}:active_coaching_program";
    Cache::forget($cacheKey);
    Log::info("CACHE FORGET: Cache program aktif dihapus untuk user ID: {$user->id}");
  }

  /**
   * Helper untuk menghapus cache detail program spesifik.
   * Akan kita panggil saat program di-update (misal: tugasnya dicentang).
   */
  public function forgetProgramDetailCache(CoachingProgram $program): void
  {
    $cacheKey = "program_detail:{$program->slug}";
    Cache::forget($cacheKey);
    Log::info("CACHE FORGET: Cache detail program dihapus untuk slug: {$program->slug}");
  }


  /**
   * [BARU] Mengaktifkan kembali program yang dijeda.
   * Secara otomatis akan menjeda program lain yang mungkin sedang aktif.
   */
  public function resumeProgram(CoachingProgram $programToResume, User $user): ?CoachingProgram
  {
    $user = $programToResume->userProfile->user;

    if ($programToResume->status === 'completed') {
      Log::warning("Program {$programToResume->slug} sudah selesai, status: {$programToResume->status}");
      throw new Exception('Tidak bisa mengaktifkan program yang sudah selsai');
    }

    // Cek dan jeda program lain yang mungkin aktif
    $currentlyActive = $this->findActiveProgramForUser($user);
    if ($currentlyActive && $currentlyActive->id !== $programToResume->id) {
      $this->pauseProgram($currentlyActive);
    }

    // Aktifkan program yang diminta
    $programToResume->update(['status' => 'active']);

    // Hapus cache agar program yang baru diaktifkan ini yang muncul
    $this->forgetActiveProgramCache($user);
    $this->forgetProgramDetailCache($programToResume); // Hapus juga cache detailnya
    $this->invalidateAllUserCaches($user);
    UserDashboardShouldUpdate::dispatch($user);

    // Muat ulang data dari database untuk mendapatkan state terbaru
    return $programToResume->fresh();
  }
  /**
   * [DIPERBAIKI] Mengubah status program menjadi 'paused' dan mengembalikan objeknya.
   */
  public function pauseProgram(CoachingProgram $program): CoachingProgram
  {
    $user = $program->userProfile->user;

    if ($program->status === 'completed') {
      Log::warning("Program {$program->slug} sudah selesai, status: {$program->status}");
      throw new Exception('Tidak bisa mengaktifkan program yang sudah selesai');
    }


    $program->update(['status' => 'paused']);
    $this->forgetActiveProgramCache($user);
    $this->forgetProgramDetailCache($program); // Hapus juga cache detailnya
    $this->invalidateAllUserCaches($user);
    UserDashboardShouldUpdate::dispatch($user);

    return $program->fresh(); // Kembalikan objek yang sudah di-update
  }

  /**
   * [DIPERBAIKI] Mengubah status program menjadi 'cancelled' dan mengembalikan objeknya.
   */
  public function cancelProgram(CoachingProgram $program): CoachingProgram
  {
    $user = $program->userProfile->user;

    $program->update(['status' => 'paused']);
    $this->forgetActiveProgramCache($user);
    $this->forgetProgramDetailCache($program);
    $this->invalidateAllUserCaches($user);
    UserDashboardShouldUpdate::dispatch($user);
    return $program->fresh();
  }

  /**
   * [TETAP] Menghapus program secara permanen dan mengembalikan boolean.
   */
  public function deleteProgram(CoachingProgram $program): bool
  {
    $user = $program->userProfile->user;
    $slug = $program->slug;

    $result = $program->delete();

    if ($result) {
      $this->forgetActiveProgramCache($user);
      $this->forgetProgramDetailCache($program);
      $this->invalidateAllUserCaches($user);
      // [BARU] Teriakkan pengumuman!
      UserDashboardShouldUpdate::dispatch($user);
    }
    return $result;
  }

  // Anda mungkin perlu helper ini
  public function forgetProgramDetailCacheBySlug(string $slug): void
  {
    Cache::forget("coaching_program:{$slug}");
  }

  /**
   * [VERSI SINKRON & LENGKAP]
   * Menandai sebuah program sebagai selesai DAN langsung men-generate
   * laporan kelulusannya dalam satu proses yang sinkron.
   */
  public function completeProgram(CoachingProgram $program): bool
  {
    if ($program->status !== 'active') {
      Log::warning("Program {$program->slug} sudah tidak aktif, status: {$program->status}");
      return false;
    }

    // Validasi program sudah mencapai 28 hari dengan perhitungan yang konsisten
    $daysSinceCreation = $this->calculateProgramDays($program->created_at);

    if ($daysSinceCreation < 28) {
      Log::warning("Program {$program->slug} belum mencapai 28 hari. Baru {$daysSinceCreation} hari");
      return false;
    }

    Log::info("Memulai proses penyelesaian program: {$program->slug} (hari ke-{$daysSinceCreation})");

    try {
      DB::beginTransaction();

      $user = $program->userProfile->user;

      // 1. Kumpulkan semua data tugas dari program ini untuk dianalisis oleh AI.
      $allTasks = $program->weeks()->with('tasks')->get()->pluck('tasks')->flatten();

      // 2. Panggil "Mesin Statistik" untuk menghitung semua data performa
      $stats = $this->calculateProgramStatistics($allTasks);

      // 3. Panggil GeminiService untuk membuat laporan
      $reportJson = $this->geminiService->generateGraduationReport($user, $program, $allTasks, $stats);
      Log::info("Laporan kelulusan berhasil di-generate untuk program: {$program->slug}");

      // 4. Update status program dan simpan laporan kelulusan
      $result = $program->update([
        'status' => 'completed',
        'graduation_report' => $reportJson,
        'completed_at' => Carbon::now() // Tambahkan timestamp completion
      ]);

      // 5. Hapus cache yang relevan setelah semuanya berhasil
      if ($result) {
        $this->forgetActiveProgramCache($user);
        $this->forgetProgramDetailCache($program);
        $this->invalidateAllUserCaches($user);
        UserDashboardShouldUpdate::dispatch($user);

        Log::info("Program {$program->slug} berhasil diselesaikan pada hari ke-{$daysSinceCreation}");
      }

      DB::commit();
      return $result;
    } catch (Exception $e) {
      DB::rollBack();
      Log::error("Gagal menyelesaikan program {$program->slug}", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
      throw $e;
    }
  }

  /**
   * Calculate consistent program days elapsed
   * Hari pertama program dibuat = hari ke-1
   */
  private function calculateProgramDays($createdAt): int
  {
    return $createdAt->startOfDay()->diffInDays(Carbon::now()->startOfDay()) + 1;
  }


  /**
   * [BARU] Menyimpan seluruh kurikulum ke database dalam satu transaksi.
   */
  public function createFullProgram(
    User $user,
    RiskAssessment $assessment,
    array $curriculum,
  ): CoachingProgram {
    $activeProgram = $this->findActiveProgramForUser($user);
    if ($activeProgram) {
      $this->cancelProgram($activeProgram); // cancelProgram sudah handle invalidasi
    }

    // Gunakan Database Transaction untuk menjamin integritas data
    return DB::transaction(function () use ($user, $assessment, $curriculum) {

      $program = $user->profile->coachingPrograms()->create([
        'risk_assessment_id' => $assessment->id,
        'slug'               => Str::ulid(),
        'title'              => $curriculum['program_title'] ?? 'Program Kesehatan Personal',
        'description'        => $curriculum['program_description'] ?? 'Program personal untuk Anda.',
        'status'             => 'active',
        'difficulty'         => $curriculum['difficulty'] ?? 'Standar & Konsisten',
        'start_date'         => now(),
        'end_date'           => now()->addWeeks(count($curriculum['weeks'] ?? [])),
      ]);

      foreach ($curriculum['weeks'] ?? [] as $weekData) {
        $week = $program->weeks()->create([
          'week_number' => $weekData['week_number'],
          'title'       => $weekData['title'],
          'description' => $weekData['description'],
        ]);

        $dailyTasksData = $weekData['tasks_by_day'] ?? $weekData['tasks'] ?? [];
        foreach ($dailyTasksData as $dayData) {
          // Simpan Misi Utama
          if (isset($dayData['main_mission'])) {
            $week->tasks()->create([
              'task_date' => $dayData['task_date'],
              'task_type' => 'main_mission',
              'title'     => $dayData['main_mission']['title'],
              'description' => $dayData['main_mission']['description'],
            ]);
          }
          // Simpan Tantangan Bonus
          foreach ($dayData['bonus_challenges'] ?? [] as $bonusTask) {
            $week->tasks()->create([
              'task_date'   => $dayData['task_date'],
              'task_type'   => 'bonus_challenge',
              'title'       => $bonusTask['title'],
              'description' => $bonusTask['description'],
            ]);
          }
        }
      }

      // ... (logika update database)
      if ($program) {
        // Hapus cache yang dikelola sendiri
        $this->forgetActiveProgramCache($user);
        $this->forgetProgramDetailCache($program);
        $this->invalidateAllUserCaches($user);
        UserDashboardShouldUpdate::dispatch($user);
      }

      $this->invalidateAllUserCaches($user);


      return $program;
    });
  }


  /**
   * [BARU & CERDAS] Menemukan program yang paling relevan untuk ditampilkan di dasbor.
   * Prioritas 1: Program yang sedang aktif.
   * Prioritas 2: Program terakhir yang di-update (apapun statusnya).
   * Hasilnya di-cache untuk performa.
   */
  public function findDashboardProgramForUser(User $user): ?CoachingProgram
  {
    if (!$user->profile) {
      return null;
    }

    // Kunci cache ini spesifik untuk data overview di dasbor
    $cacheKey = "user:{$user->id}:dashboard_program_overview";

    // Simpan di cache selama 5 menit. Cukup untuk sesi pengguna biasa.

    // Prioritas 1: Cari program yang sedang aktif
    $program = $user->profile->coachingPrograms()
      ->where('status', 'active')
      ->with(['weeks.tasks', 'threads', 'riskAssessment']) // Eager load semua relasi
      ->first();

    // Prioritas 2 (Fallback): Jika tidak ada yang aktif, cari program terakhir
    if (!$program) {
      $program = $user->profile->coachingPrograms()
        ->latest('updated_at') // Urutkan berdasarkan yang terakhir di-update
        ->with(['weeks.tasks', 'threads', 'riskAssessment']) // Eager load semua relasi
        ->first();
    }

    return $program;
  }


  /**
   * [MESIN STATISTIK LENGKAP]
   * Menghitung semua statistik performa dari kumpulan tugas.
   */
  private function calculateProgramStatistics(Collection $allTasks): array
  {
    if ($allTasks->isEmpty()) {
      return [
        'main_missions_completion_percentage' => 0,
        'bonus_challenges_completion_percentage' => 0,
        'active_days' => 0,
        'total_program_days' => 28,
        'best_streak' => 0,
      ];
    }

    // === 1. Kalkulasi Persentase Penyelesaian ===
    $mainMissions = $allTasks->where('task_type', 'main_mission');
    $bonusChallenges = $allTasks->where('task_type', 'bonus_challenge');
    $mainMissionPercentage = ($mainMissions->count() > 0) ? round(($mainMissions->where('is_completed', true)->count() / $mainMissions->count()) * 100) : 0;
    $bonusChallengePercentage = ($bonusChallenges->count() > 0) ? round(($bonusChallenges->where('is_completed', true)->count() / $bonusChallenges->count()) * 100) : 0;

    // === 2. Kalkulasi Hari Aktif ===
    $activeDays = $allTasks->where('is_completed', true)->pluck('task_date')->unique()->count();
    $totalDaysInProgram = $allTasks->pluck('task_date')->unique()->count();

    // === 3. Kalkulasi Streak Terbaik (Best Streak) ===
    $tasksByDate = $allTasks->groupBy(function ($task) {
      return \Carbon\Carbon::parse($task->task_date)->toDateString();
    })->sortKeys();
    $completionMap = [];
    foreach ($tasksByDate as $date => $tasksOnDate) {
      // Sebuah hari dianggap "sempurna" jika SEMUA tugas di hari itu selesai
      $completionMap[$date] = $tasksOnDate->every(fn($task) => $task->is_completed);
    }

    $bestStreak = 0;
    $currentStreak = 0;
    foreach ($completionMap as $isPerfectDay) {
      if ($isPerfectDay) {
        $currentStreak++;
      } else {
        $bestStreak = max($bestStreak, $currentStreak);
        $currentStreak = 0; // Reset streak
      }
    }
    $bestStreak = max($bestStreak, $currentStreak); // Pengecekan terakhir jika streak terjadi di akhir

    // === Kembalikan semua hasil ===
    return [
      'main_missions_completion_percentage' => $mainMissionPercentage,
      'bonus_challenges_completion_percentage' => $bonusChallengePercentage,
      'active_days' => $activeDays,
      'total_program_days' => $totalDaysInProgram,
      'best_streak' => $bestStreak,
    ];
  }

  /**
   * Helper untuk menghapus cache dasbor.
   * Perlu dipanggil setiap kali ada perubahan pada program APAPUN.
   */
  public function forgetDashboardProgramCache(User $user): void
  {
    $cacheKey = "user:{$user->id}:dashboard_program_overview";
    Cache::forget($cacheKey);
    Log::info("CACHE FORGET: Cache program dasbor dihapus untuk user ID: {$user->id}");
  }

  private function invalidateAllUserCaches(User $user): void
  {
    $activeProgramCacheKey = "user:{$user->id}:active_program_detail";
    Cache::forget($activeProgramCacheKey);
    Log::info("CACHE FORGET: Cache program aktif dihapus untuk user: {$user->id}");

    // Memicu event untuk memberitahu repository lain (seperti Dashboard)
    UserDashboardShouldUpdate::dispatch($user);
    Log::info("EVENT DISPATCH: UserDashboardShouldUpdate dikirim untuk user: {$user->id}");
  }

  /**
   * [VERSI FINAL & AMAN]
   * Menemukan satu misi utama (primary) yang relevan untuk hari ini
   * dari program coaching yang sedang aktif, dengan penanganan kasus kosong.
   */
  public function findTodaysPrimaryMissionForUser(User $user): ?CoachingTask
  {
    // Langkah 1: Gunakan kembali metode yang sudah ada untuk mencari program aktif
    $activeProgram = $this->findActiveProgramForUser($user);

    // Langkah 2: Jika tidak ada program aktif, maka tidak mungkin ada misi.
    if (!$activeProgram) {
      return null;
    }

    // Langkah 3: Ambil semua ID minggu dari program aktif
    $weekIds = $activeProgram->weeks->pluck('id');

    // [FIX UTAMA UNTUK ERROR HY093]
    // Cek apakah collection weekIds kosong. Jika ya, program ini tidak punya
    // minggu, jadi tidak mungkin punya tugas. Langsung hentikan proses di sini
    // untuk mencegah Laravel membuat query dengan parameter yang tidak valid.
    if ($weekIds->isEmpty()) {
      return null;
    }

    // Langkah 4: Jika kita sampai sini, artinya $weekIds PASTI berisi sesuatu.
    // Query sekarang 100% aman untuk dibuat.
    return CoachingTask::whereIn('coaching_week_id', $weekIds) // `whereIn` lebih eksplisit
      ->whereDate('task_date', Carbon::today())
      ->where('task_type', 'main_mission') // Gunakan tipe yang benar sesuai enum
      ->first();
  }
}
