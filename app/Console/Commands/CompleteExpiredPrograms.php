<?php

namespace App\Console\Commands;

use App\Models\CoachingProgram;
use App\Repositories\CoachingRepository;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CompleteExpiredPrograms extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'programs:complete-expired 
                            {--dry-run : Show what would be completed without actually completing}
                            {--force : Force complete even if already processed today}';

    /**
     * The console command description.
     */
    protected $description = 'Complete coaching programs that have reached 28 days from creation';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $isForce = $this->option('force');

        $this->info('🚀 Starting expired programs completion process...');
        $this->info('📅 Current time: ' . Carbon::now()->format('Y-m-d H:i:s'));

        try {
            // Ambil program yang sudah expired (28 hari dari created_at)
            $expiredPrograms = $this->getExpiredPrograms();

            if ($expiredPrograms->isEmpty()) {
                $this->info('✅ No expired programs found.');
                return Command::SUCCESS;
            }

            $this->info("📋 Found {$expiredPrograms->count()} expired program(s):");

            // Tampilkan list program yang akan di-complete
            $this->table(
                ['ID', 'Slug', 'User', 'Created At', 'Days Elapsed', 'Should Complete'],
                $expiredPrograms->map(function ($program) {
                    $daysElapsed = $this->calculateDaysElapsed($program->created_at);
                    return [
                        $program->id,
                        $program->slug,
                        $program->userProfile->user->name ?? 'N/A',
                        $program->created_at->format('Y-m-d H:i:s'),
                        $daysElapsed,
                        $daysElapsed >= 28 ? 'YES' : 'NO'
                    ];
                })
            );

            if ($isDryRun) {
                $this->warn('DRY RUN MODE - No programs will be actually completed.');
                return Command::SUCCESS;
            }

            // Konfirmasi jika tidak dalam mode force
            if (!$isForce && !$this->confirm('Do you want to complete these programs?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }

            // Process each expired program
            $completed = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($expiredPrograms as $program) {
                try {
                    $this->info("⏳ Processing program: {$program->slug}");

                    // Double check apakah program benar-benar sudah 28 hari
                    $daysElapsed = $this->calculateDaysElapsed($program->created_at);
                    if ($daysElapsed < 28) {
                        $this->warn("⚠️  Skipping {$program->slug}: Only {$daysElapsed} days elapsed");
                        $skipped++;
                        continue;
                    }

                    $result = $this->completeProgram($program);

                    if ($result) {
                        $this->info("✅ Successfully completed: {$program->slug} ({$daysElapsed} days)");
                        $completed++;
                    } else {
                        $this->error("❌ Failed to complete: {$program->slug}");
                        $failed++;
                    }
                } catch (Exception $e) {
                    $this->error("💥 Error completing {$program->slug}: " . $e->getMessage());
                    Log::error("Error completing program {$program->slug}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $failed++;
                }
            }

            // Summary
            $this->info('');
            $this->info('📊 COMPLETION SUMMARY:');
            $this->info("✅ Successfully completed: {$completed}");
            $this->info("❌ Failed: {$failed}");
            $this->info("⚠️  Skipped: {$skipped}");
            $this->info("📋 Total processed: " . ($completed + $failed + $skipped));

            Log::info('Expired programs completion finished', [
                'completed' => $completed,
                'failed' => $failed,
                'skipped' => $skipped,
                'total' => $completed + $failed + $skipped
            ]);

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error('💥 Fatal error: ' . $e->getMessage());
            Log::error('Fatal error in CompleteExpiredPrograms command', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Get expired programs that need to be completed
     */
    private function getExpiredPrograms()
    {
        // Program yang dibuat >= 28 hari yang lalu
        // Menggunakan startOfDay() untuk konsistensi perhitungan harian
        $cutoffDate = Carbon::now()->startOfDay()->subDays(27); // 27 karena hari ini adalah hari ke-28

        return CoachingProgram::with(['userProfile.user', 'weeks.tasks'])
            ->where('status', 'active')
            ->where('created_at', '<=', $cutoffDate)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Calculate days elapsed since program creation
     * Menggunakan metode yang sama dengan repository untuk konsistensi
     */
    private function calculateDaysElapsed($createdAt): int
    {
        return $createdAt->startOfDay()->diffInDays(Carbon::now()->startOfDay()) + 1;
    }

    /**
     * Complete a single program
     */
    private function completeProgram(CoachingProgram $program): bool
    {
        $coachingRepository = app(CoachingRepository::class);
        return $coachingRepository->completeProgram($program);
    }
}
