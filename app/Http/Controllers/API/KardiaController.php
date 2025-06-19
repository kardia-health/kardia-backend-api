<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\KardiaRiskRequest;
use App\Models\RiskAssessment;
use App\Services\ClinicalRiskService;
use App\Services\GeminiReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;



/**
 * Class KardiaController
 * Bertindak sebagai perantara antara permintaan HTTP dan logika bisnis aplikasi.
 * Tugasnya: menerima, mendelegasikan, menyimpan hasil, dan merespons.
 */
class KardiaController extends Controller
{
    // Inject kedua service yang kita butuhkan
    public function __construct(
        private ClinicalRiskService $riskCalculator,
        private GeminiReportService $reportGenerator
    ) {}

    /**
     * TAHAP 1: Memulai analisis, menjalankan kalkulasi numerik,
     * dan mengembalikan hasil awal dengan cepat.
     */
    public function startAssessment(KardiaRiskRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $user = $request->user();
        $profile = $user->profile;

        if (!$profile) {
            return response()->json(['error' => 'User profile not found.'], 404);
        }


        // 1. Jalankan service kalkulasi yang CEPAT
        $result = $this->riskCalculator->processRiskCalculation($validatedData, $profile);

        // 2. Buat slug unik
        $slug = Str::ulid();

        // 3. Simpan hasil AWAL ke database
        $assessment = $user->profile->riskAssessments()->create([
            'slug' => $slug,
            'model_used' => $result['model_used'],
            'final_risk_percentage' => $result['calibrated_10_year_risk_percent'],
            'inputs' => $validatedData,
            'generated_values' => $result['final_clinical_inputs'],
            'result_details' => null // Laporan AI masih kosong
        ]);

        // 4. Kembalikan respons CEPAT ke frontend
        return response()->json([
            'message' => 'Initial assessment complete.',
            'assessment_slug' => $slug,
            'numerical_result' => $result
        ], 202); // 202 Accepted: menandakan proses diterima dan masih ada kelanjutannya
    }

    /**
     * TAHAP 2: Mengambil hasil numerik yang sudah ada dan
     * memanggil Gemini untuk membuat laporan personalisasi.
     */
    public function generatePersonalizedReport(Request $request, RiskAssessment $assessment): JsonResponse
    {
        // Otorisasi: Pastikan pengguna hanya bisa mengakses analisis miliknya
        if ($request->user()->profile->id !== $assessment->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }

        // 1. Jalankan service Gemini yang lebih LAMBAT
        $fullReport = $this->reportGenerator->getFullReport($request->user()->profile, $assessment);

        // 2. Perbarui record di database dengan laporan dari Gemini
        $assessment->update(['result_details' => $fullReport]);

        // 3. Kembalikan laporan lengkap ke frontend
        return response()->json([
            'message' => 'Personalized report generated successfully.',
            'data' => $fullReport
        ]);
    }
}
