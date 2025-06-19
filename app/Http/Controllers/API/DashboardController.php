<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardResource;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function getDashboardData(Request $request)
    {
        // Ambil semua riwayat analisis milik pengguna, diurutkan dari yang terbaru
        $assessments = $request->user()->profile->riskAssessments()->latest()->get();

        // Jika tidak ada riwayat, kembalikan respons kosong yang informatif
        if ($assessments->isEmpty()) {
            return response()->json(['data' => null, 'message' => 'No assessment history found.']);
        }

        // Bungkus dengan Resource untuk transformasi data
        return new DashboardResource($assessments);
    }
}
