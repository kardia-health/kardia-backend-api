<?php
namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DashboardRepository
{
    // Inject repository lain yang dibutuhkan
    public function __construct(
        private RiskAssessmentRepository $assessmentRepository,
        private CoachingRepository $coachingRepository
    ) {}

    /**
     * Mengambil semua data yang dibutuhkan untuk dasbor dalam satu paket.
     * Logika caching utama ada di sini.
     */
    public function getDashboardData(User $user): array
    {
        $cacheKey = "dashboard:user:{$user->id}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($user) {
            Log::info("CACHE MISS: Membangun data dasbor dari DB untuk user ID: {$user->id}");

            $assessments = $this->assessmentRepository->getAssessmentsForDashboard($user);
            $dashboardProgram = $this->coachingRepository->findDashboardProgramForUser($user);

            return [
                'assessments' => $assessments,
                'program'     => $dashboardProgram,
            ];
        });
    }

    /**
     * Metode publik untuk menghapus cache dasbor milik seorang pengguna.
     */
    public function forgetDashboardCache(User $user): void
    {
        $cacheKey = "dashboard:user:{$user->id}";
        Cache::forget($cacheKey);
        Log::info("CACHE FORGET: Cache dasbor dihapus untuk user ID: {$user->id}");
    }
}