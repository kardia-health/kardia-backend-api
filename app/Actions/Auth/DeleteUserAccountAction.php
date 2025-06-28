<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Repositories\DashboardRepository; // Impor semua repo yang cache-nya perlu dihapus
use App\Repositories\UserRepository;

class DeleteUserAccountAction
{
    // Inject semua repository yang cache-nya perlu kita bersihkan
    public function __construct(
        private DashboardRepository $dashboardRepo,
        private UserRepository $userRepository
        ) {}

    public function execute(User $user): void
    {
        // [BEST PRACTICE] Hapus semua cache yang terkait dengan user ini SEBELUM menghapus datanya.
        $this->dashboardRepo->forgetDashboardCache($user);
        $this->userRepository->forgetUserCache($user);

        // $this->conversationRepo->forgetUserConversationsCache($user); // Jika ada
        // $this->coachingRepo->forgetActiveProgramCache($user); // Jika ada

        // Hapus semua token otentikasi
        $user->tokens()->delete();

        // Hapus pengguna secara permanen.
        // Migrasi kita dengan onDelete('cascade') akan menghapus profile, assessment, dll.
        $user->forceDelete();
    }
}
