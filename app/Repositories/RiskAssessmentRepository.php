<?php

namespace App\Repositories;

use App\Models\RiskAssessment;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RiskAssessmentRepository
{
  /**
   * Mengambil semua data assessment untuk seorang pengguna, dengan caching.
   *
   * @param User $user
   * @return Collection
   */
  public function getAssessmentsForDashboard(User $user): Collection
  {
    if (!$user->profile) {
      return new Collection();
    }

    // Ambil profil dari cache terlebih dahulu
    $profile = UserProfile::findAndCache($user->profile->id);

    // Kunci cache dasbor sekarang bisa lebih sederhana
    $cacheKey = "user_profile:{$profile->id}:dashboard_assessments";

    return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($profile) {
      Log::info("CACHE MISS: Mengambil data dasbor dari DB untuk profil ID: {$profile->id}");
      return $profile->riskAssessments()->latest()->get();
    });
  }
  /**
   * Membuat record analisis awal dan menghapus cache dasbor.
   */
  public function createInitialAssessment(UserProfile $profile, array $calculationResult, array $validatedInputs): RiskAssessment
  {
    // Buat slug unik di sini
    $slug = Str::ulid();

    $assessment = $profile->riskAssessments()->create([
      'slug' => $slug,
      'model_used' => $calculationResult['model_used'],
      'final_risk_percentage' => $calculationResult['calibrated_10_year_risk_percent'],
      'inputs' => $validatedInputs,
      'generated_values' => $calculationResult['final_clinical_inputs'],
      'result_details' => null // Laporan AI masih kosong
    ]);

    // [PENTING] Langsung hapus cache dasbor karena ada data baru.
    $this->forgetDashboardCache($profile->user);
    Cache::forget("user:{$profile->user->id}:latest_3_assessments");

    return $assessment;
  }

  /**
   * Mengupdate record analisis dengan laporan dari Gemini dan menghapus cache.
   */
  public function updateWithGeminiReport(RiskAssessment $assessment, array $geminiReport): bool
  {
    $result = $assessment->update(['result_details' => $geminiReport]);

    // [PENTING] Hapus cache lagi karena detail analisis terakhir sudah berubah.
    $this->forgetDashboardCache($assessment->userProfile->user);

    return $result;
  }

  /**
   * Helper untuk menghapus cache dasbor.
   */
  public function forgetDashboardCache(\App\Models\User $user): void
  {
    Cache::forget("user:{$user->id}:dashboard_assessments");
  }

  /**
   * [BARU] Mengambil 3 riwayat analisis terakhir untuk seorang pengguna, dengan caching.
   * Ini akan digunakan oleh ChatService dan service lain yang butuh riwayat.
   */
  public function getLatestThreeForUser(User $user): Collection
  {
    if (!$user->profile) {
      return new Collection(); // Kembalikan koleksi kosong jika profil tidak ada
    }

    $cacheKey = "user:{$user->id}:latest_3_assessments";

    // Simpan di cache selama 1 jam
    return Cache::remember($cacheKey, now()->addHours(1), function () use ($user) {
      Log::info("CACHE MISS: Mengambil 3 assessment terakhir dari DB untuk user ID: {$user->id}");
      return $user->profile->riskAssessments()->latest()->take(3)->get();
    });
  }

  /**
   * [BARU] Helper untuk menghapus cache riwayat analisis ini.
   * Harus dipanggil setiap kali assessment baru dibuat atau diubah.
   */
  public function forgetLatestAssessmentsCache(User $user): void
  {
    Cache::forget("user:{$user->id}:latest_3_assessments");
    Log::info("CACHE FORGET: Cache 3 assessment terakhir dihapus untuk user ID: {$user->id}");
  }
}
