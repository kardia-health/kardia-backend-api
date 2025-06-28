<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserProfileRequest;
use App\Http\Resources\UserProfileResource;
use App\Models\UserProfile;
use App\Repositories\UserProfileRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    // Inject repository melalui konstruktor
    public function __construct(
        private UserProfileRepository $profileRepository,
        private UserRepository $userRepository
        
        ) {}

    /**
     * Menampilkan profil milik pengguna yang sedang terotentikasi.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->profile) {
            return response()->json(['data' => null, 'message' => 'User profile not yet created.']);
        }

        // Panggil metode statis baru kita. Ia akan otomatis cek cache dulu.
        $profile = UserProfile::findAndCache($user->profile->id);

        return (new UserProfileResource($profile))->response();
    }


    /**
     * Membuat atau memperbarui profil yang sudah ada.
     * Saya mengubah nama metodenya menjadi 'update' agar konsisten,
     * namun fungsinya tetap sama dengan 'patch' yang Anda buat.
     */
    public function update(StoreUserProfileRequest $request): JsonResponse
    {
        $profile = $this->profileRepository->updateOrCreateForUser(
            $request->user(),
            $request->validated()
        );

        $this->userRepository->forgetUserCache($request->user());

        return response()->json([
            'message' => 'Profile updated successfully!',
            'data' => new UserProfileResource($profile)
        ]);
    }
}
