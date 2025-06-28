<?php

namespace App\Listeners;

use App\Events\UserDashboardShouldUpdate;
use App\Repositories\DashboardRepository;

// Tidak ada lagi 'implements ShouldQueue' atau 'use InteractsWithQueue'
class ClearUserDashboardCache
{
    public function __construct(private DashboardRepository $dashboardRepository) {}

    /**
     * Menangani event dan menghapus cache dasbor secara langsung.
     */
    public function handle(UserDashboardShouldUpdate $event): void
    {
        // Logika ini sekarang akan berjalan seketika saat event di-dispatch.
        $this->dashboardRepository->forgetDashboardCache($event->user);
    }
}
