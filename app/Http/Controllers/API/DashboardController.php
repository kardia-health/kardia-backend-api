<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardResource;
use App\Repositories\RiskAssessmentRepository; // <-- Impor Repository
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    // Inject repository melalui konstruktor
    public function __construct(private RiskAssessmentRepository $assessmentRepository) {}

    public function getDashboardData(Request $request): JsonResponse
    {
        // 1. Delegasikan tugas pengambilan data ke Repository.
        //    Controller tidak tahu menahu tentang database atau cache.
        $assessments = $this->assessmentRepository->getAssessmentsForDashboard($request->user());

        // 2. Jika tidak ada riwayat, kembalikan respons kosong yang informatif.
        if ($assessments->isEmpty()) {
            return response()->json(['data' => null, 'message' => 'No assessment history found.']);
        }

        // 3. Bungkus dengan Resource untuk transformasi data.
        //    (Pastikan Anda sudah memiliki DashboardResource dari langkah kita sebelumnya)
        $formattedData = (new DashboardResource($assessments))->resolve();

        return response()->json(['data' => $formattedData]);
    }
}
