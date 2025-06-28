<?php

namespace App\Services;

use App\Models\RiskAssessment;
use App\Models\User;
use App\Repositories\CoachingRepository;

class CoachingService
{
  public function __construct(
    private GeminiCoachService $geminiService,
    private CoachingRepository $coachingRepository
  ) {}

  /**
   * Logika utama untuk memulai program coaching baru.
   */
  public function initiateProgram(
    User $user,
    RiskAssessment $assessment,
    string $difficulty,
  ): \App\Models\CoachingProgram {

    // 1. Batalkan program aktif lainnya untuk memastikan hanya ada satu program yang berjalan.
    $activeProgram = $this->coachingRepository->findActiveProgramForUser($user);
    if ($activeProgram) {
      $this->coachingRepository->cancelProgram($activeProgram); // 'cancelProgram' akan mengubah status dan menghapus cache.
    }

    // 2. Tentukan tanggal mulai SEKARANG, sebelum memanggil Gemini.
    $programStartDate = now()->startOfDay();

    // 3. Panggil Gemini untuk merancang kurikulum lengkap.
    $curriculum = $this->geminiService->generateCoachingCurriculum($assessment, $user, $difficulty, $programStartDate);
    $newProgram = $this->coachingRepository->createFullProgram($user, $assessment, $curriculum, $difficulty);

    // 5. Hapus cache program aktif sekali lagi untuk memastikan data baru yang diambil.
    $this->coachingRepository->forgetActiveProgramCache($user);

    return $newProgram;
  }
}
