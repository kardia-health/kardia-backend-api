<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateDailyGuideRequest;
use App\Http\Requests\StoreCulinaryPreferencesRequest;
use App\Http\Resources\CulinaryPreferenceResource;
use App\Http\Resources\DailyMealGuideResource;
use App\Repositories\CulinaryPreferenceRepository;
use App\Repositories\DailyMealGuideRepository;
use App\Services\CulinaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CulinaryController extends Controller
{
    /**
     * Inject semua dependensi yang dibutuhkan oleh controller ini.
     */
    public function __construct(
        private CulinaryPreferenceRepository $preferenceRepository,
        private DailyMealGuideRepository $guideRepository,
        private CulinaryService $culinaryService
    ) {}

    /**
     * [BARU & MENGGANTIKAN YANG LAMA] [GET /culinary/hub-data]
     * Mengambil semua data yang dibutuhkan untuk Halaman Culinary Hub.
     */
    public function getHubData(Request $request): JsonResponse
    {
        // 1. Ambil data preferensi dari repository
        $preferences = $this->preferenceRepository->get($request->user());

        // 2. Ambil riwayat panduan menu dari repository
        $guidesHistory = $this->guideRepository->listForUser($request->user());

        // 3. Kembalikan semuanya dalam satu respons terstruktur
        return response()->json([
            'data' => [
                'preferences' => new CulinaryPreferenceResource($preferences),
                'history' => DailyMealGuideResource::collection($guidesHistory),
            ]
        ]);
    }

    /**
     * [PUT /culinary/preferences]
     * Menyimpan atau memperbarui preferensi kuliner pengguna.
     */
    public function updatePreferences(StoreCulinaryPreferencesRequest $request): JsonResponse
    {
        // Validasi ditangani otomatis oleh StoreCulinaryPreferencesRequest
        $this->preferenceRepository->update(
            $request->user(),
            $request->validated()
        );

        return response()->json(['message' => 'Preferensi kuliner berhasil diperbarui.']);
    }

    /**
     * [POST /culinary/daily-guides]
     * Endpoint utama untuk men-generate rencana menu harian.
     */
    public function generateDailyGuide(GenerateDailyGuideRequest $request): JsonResponse
    {
        // Validasi input harian ditangani oleh GenerateDailyGuideRequest
        $dailyInputs = $request->validated();

        // Delegasikan semua pekerjaan berat ke service
        $guide = $this->culinaryService->generateTodaysGuide($request->user(), $dailyInputs);

        // Kembalikan hasil dari AI langsung ke frontend
        return response()->json([
            'message' => 'Rencana menu harian berhasil dibuat.',
            'data' => $guide
        ]);
    }
}
