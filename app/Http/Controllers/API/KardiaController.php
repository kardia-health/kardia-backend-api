<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\KardiaRiskRequest;
use App\Models\RiskAssessment;
use App\Models\UserProfile;
use App\Repositories\RiskAssessmentRepository;
use App\Services\ClinicalRiskService;
use App\Services\GeminiReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KardiaController extends Controller
{
    // Inject semua dependensi yang kita butuhkan
    public function __construct(
        private ClinicalRiskService $riskCalculator,
        private GeminiReportService $reportGenerator,
        private RiskAssessmentRepository $assessmentRepository // <-- Inject Repository
    ) {}

    /**
     * TAHAP 1: Memulai analisis.
     */
    public function startAssessment(KardiaRiskRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            $user = $request->user();

            $profile = UserProfile::findAndCache($user->profile->id);


            if (!$profile) {
                return response()->json(['error' => 'User profile not found.'], 404);
            }

            // 1. Dapatkan hasil kalkulasi dari Service
            $result = $this->riskCalculator->processRiskCalculation($validatedData, $user->profile);

            // 2. Delegasikan pembuatan data & invalidasi cache ke Repository
            $assessment = $this->assessmentRepository->createInitialAssessment(
                $user->profile,
                $result,
                $validatedData
            );

            // 3. Kembalikan respons CEPAT ke frontend
            return response()->json([
                'message' => 'Initial assessment complete. Personalization is being generated.',
                'assessment_slug' => $assessment->slug, // Ambil slug dari record yang baru dibuat
                'numerical_result' => $result
            ], 202);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'An error occurred during assessment.'], 500);
        }
    }

    /**
     * TAHAP 2: Membuat laporan personalisasi.
     */
    public function generatePersonalizedReport(Request $request, RiskAssessment $assessment): JsonResponse
    {
        $user = $request->user();

        // Otorisasi: Pastikan pengguna hanya bisa mengakses analisis miliknya
        if ($user->profile->id !== $assessment->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }
        $profile = UserProfile::findAndCache($user->profile->id);


        // 1. Dapatkan laporan dari Service Gemini
        $fullReport = $this->reportGenerator->getFullReport($profile, $assessment);

        // 2. Delegasikan update data & invalidasi cache ke Repository
        $this->assessmentRepository->updateWithGeminiReport($assessment, $fullReport);

        // 3. Kembalikan laporan lengkap ke frontend
        return response()->json([
            'message' => 'Personalized report generated successfully.',
            'data' => $fullReport
        ]);
    }
}
