<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserProfileRequest;
use App\Http\Resources\UserProfileResource;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    /**
     * Menampilkan profil milik pengguna yang sedang terotentikasi.
     */
    public function show(Request $request): UserProfileResource // <-- Ubah tipe return
    {
        // Mengambil profil dari pengguna yang terotentikasi.
        // `load('user')` untuk memastikan data user ikut terambil (eager loading).
        $profile = $request->user()->profile()->with('user')->firstOrFail();

        // Kembalikan profil yang dibungkus oleh Resource.
        // Laravel akan secara otomatis mengubahnya menjadi respons JSON yang benar.
        return new UserProfileResource($profile);
    }


    /**
     * Membuat profil baru atau memperbarui profil yang sudah ada
     * milik pengguna yang sedang terotentikasi.
     */
    public function patch(StoreUserProfileRequest $request): JsonResponse
    {
        // Data sudah bersih dan tervalidasi oleh StoreUserProfileRequest
        $validatedData = $request->validated();
        $user = $request->user();

        // Eloquent's updateOrCreate adalah cara paling efisien untuk ini.
        // Ia akan mencari profil dengan user_id ini. Jika ada, akan di-update.
        // Jika tidak ada, akan dibuatkan yang baru.
        $profile = UserProfile::updateOrCreate(
            ['user_id' => $user->id],
            $validatedData
        );

        return response()->json([
            'message' => 'Profile saved successfully!',
            'data' => $profile
        ], 200);
    }
}
