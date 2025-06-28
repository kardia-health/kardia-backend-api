<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardResource;
use App\Repositories\DashboardRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardRepository $dashboardRepository
    ) {}

    public function getDashboardData(Request $request): JsonResponse
    {
        // 1. Ambil semua data mentah dari Repository
        $dashboardData = $this->dashboardRepository->getDashboardData($request->user());

        // 2. Cek apakah assessments kosong (program bisa null/kosong, itu OK)
        $hasAssessments = !empty($dashboardData['assessments']) &&
            (is_countable($dashboardData['assessments']) ?
                count($dashboardData['assessments']) > 0 :
                !$dashboardData['assessments']->isEmpty());

        // 3. Jika tidak ada assessment sama sekali, kembalikan pesan selamat datang
        if (!$hasAssessments) {
            return response()->json([
                'data' => null,
                'message' => 'Selamat datang! Mulai perjalanan Anda dengan mengambil analisis risiko pertama.'
            ]);
        }

        // 4. Kirim data ke Resource (program bisa null, Resource akan handle)
        return (new DashboardResource($dashboardData))->response();
    }
}
