<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\CoachingRepository;
use App\Repositories\CulinaryPreferenceRepository;
use App\Repositories\DailyMealGuideRepository;
use App\Repositories\RiskAssessmentRepository;

class CulinaryService
{
  /**
   * Inject semua dependensi yang dibutuhkan oleh service ini.
   */
  public function __construct(
    private GeminiCulinaryService $geminiService,
    private CulinaryPreferenceRepository $preferenceRepo,
    private CoachingRepository $coachingRepo,
    private DailyMealGuideRepository $guideRepo,
    private RiskAssessmentRepository $assessmentRepo
  ) {}

  /**
   * Metode utama yang mengorkestrasi seluruh proses pembuatan panduan menu harian.
   */
  public function generateTodaysGuide(User $user, array $dailyInputs): array
  {
    // 1. Kumpulkan data, biarkan dalam bentuk objek atau null.
    $preferences = $this->preferenceRepo->get($user);
    $todaysMission = $this->coachingRepo->findTodaysPrimaryMissionForUser($user);
    $learningHistory = $this->guideRepo->getLatestChosenGuides($user);
    $latestAssessment = $this->assessmentRepo->getLatestFourAssessmentsForUser($user)->first();

    // 2. Rakit konteks dengan aman menggunakan nullsafe operator (?->)
    $context = [
      'user_language' => $user->profile->language ?? 'id',
      'user_profile' => $user->profile,
      // [FIX] Jika $latestAssessment null, ?-> akan berhenti & ?? akan ambil nilai default.
      'health_focus' => $latestAssessment?->result_details['riskSummary']['primaryContributors'][0]['title'] ?? 'Kesehatan Jantung Umum',
      // [FIX] Jika $todaysMission null, ?-> akan berhenti & ?? akan ambil nilai default.
      'daily_coaching_mission' => $todaysMission?->title ?? 'Menjaga pola hidup sehat secara umum.',
      'preferences' => $preferences,
      'daily_inputs' => $dailyInputs,
      'learning_history' => $learningHistory->pluck('guide_data.suggestions.*.dish_name')->flatten()->unique()->implode(', '),
      'current_meal_time' => $this->getCurrentMealTime(),
    ];

    // 3. Panggil service AI untuk mendapatkan saran menu
    $guideData = $this->geminiService->generateDailyMealGuide($context);

    // 4. Simpan hasilnya ke database. Baris ini sekarang PASTI tercapai.
    $this->guideRepo->saveGuide($user, $dailyInputs, $guideData);

    return $guideData;
  }

  /**
   * Helper privat untuk menentukan waktu makan saat ini secara dinamis.
   */
  private function getCurrentMealTime(): string
  {
    $hour = now()->hour;

    if ($hour >= 5 && $hour < 10) {
      return 'Sarapan';
    }
    if ($hour >= 10 && $hour < 15) {
      return 'Makan Siang';
    }
    if ($hour >= 15 && $hour < 18) {
      return 'Camilan Sore';
    }
    return 'Makan Malam';
  }
}
