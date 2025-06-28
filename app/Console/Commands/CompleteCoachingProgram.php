<?php

namespace App\Console\Commands;

use App\Models\CoachingProgram;
use App\Repositories\CoachingRepository;
use Illuminate\Console\Command;

class CompleteCoachingProgram extends Command
{
    protected $signature = 'kardia:complete-program {program_slug}';
    protected $description = 'Secara manual menandai program coaching sebagai selesai untuk tujuan testing.';

    public function handle(CoachingRepository $coachingRepo): void
    {
        $slug = $this->argument('program_slug');
        $program = CoachingProgram::where('slug', $slug)->first();

        if (!$program) {
            $this->error("Program dengan slug '{$slug}' tidak ditemukan.");
            return;
        }

        // Panggil metode repository untuk mengubah status dan menghapus cache.
        $coachingRepo->completeProgram($program);

        // Di sini kita bisa juga memicu job untuk membuat "Laporan Kelulusan"
        // \App\Jobs\GenerateGraduationReportJob::dispatch($program);

        $this->info("Program '{$program->title}' telah berhasil ditandai sebagai 'completed'.");
    }
}
