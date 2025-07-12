<?php

namespace App\Http\Resources;

use App\Models\CoachingProgram;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class DashboardResource extends JsonResource
{
    /**
     * Data program coaching tambahan.
     *
     * @var CoachingProgram|null
     */
    protected $program;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assessments = $this->resource['assessments'];
        $program = $this->resource['program'] ?? null; // Handle null program

        // Debug: Log untuk melihat apa yang dikembalikan dari repository
        Log::info('DashboardResource Debug:', [
            'program_raw' => $program,
            'program_type' => gettype($program),
            'program_class' => is_object($program) ? get_class($program) : 'not_object',
            'resource_keys' => array_keys($this->resource)
        ]);

        // Jika tidak ada data assessment sama sekali, kembalikan struktur kosong
        if ($assessments->isEmpty()) {
            return [
                'program_overview' => $this->formatProgramOverview($program),
                'health_summary' => null,
                'risk_trend_graph' => ['labels' => [], 'values' => []],
                'assessment_history' => [],
            ];
        }

        $latest = $assessments->first();
        $previous = $assessments->skip(1)->first();

        // 1. Kalkulasi Summary
        $trend = $this->calculateTrend($latest, $previous);
        $summary = [
            'total_assessments' => $assessments->count(),
            'last_assessment_date_human' => Carbon::parse($latest->created_at)->diffForHumans(),
            'latest_status' => [
                'category_code' => $latest->result_details['riskSummary']['riskCategory']['code'] ?? 'N/A',
                'category_title' => $latest->result_details['riskSummary']['riskCategory']['title'] ?? 'N/A',
                'description' => 'Kondisi kesehatan Anda memerlukan perhatian.' // Bisa dibuat dinamis
            ],
            'health_trend' => $trend,
        ];

        // 2. Kalkulasi Data Grafik
        $graphData = $this->formatGraphData($assessments);

        // 3. Siapkan daftar riwayat
        $history = $assessments->map(function ($item) use ($program) {
            // SOLUSI 1: Gunakan program dari relasi assessment jika ada
            $assessmentProgram = $item->program ?? $item->coaching_program ?? null;

            // SOLUSI 2: Fallback ke program dari resource jika assessment tidak punya relasi program
            $programSlug = $assessmentProgram?->slug ?? $program?->slug ?? null;
            $programStatus = $assessmentProgram?->status ?? $program?->status ?? null;

            return [
                'program_slug'     => $programSlug, // Program slug jika ada
                'program_status'     => $programStatus, // Program slug jika ada
                'slug' => $item->slug,
                'date' => Carbon::parse($item->created_at)->isoFormat('D MMMM YYYY'),
                'model_used' => $item->model_used,
                'risk_percentage' => $item->final_risk_percentage,
                'input' => $item->inputs,
                'generated_value' => $item->generated_values,
                'result_details' => $item->result_details,
            ];
        });

        return [
            'program_overview' => $this->formatProgramOverview($program),
            'summary' => $summary,
            'graph_data_30_days' => $graphData,
            'latest_assessment_details' => $latest->result_details,
            'assessment_history' => $history,
        ];
    }

    private function calculateTrend($latest, $previous): array
    {
        if (!$previous) {
            return ['direction' => 'stable', 'change_value' => 0, 'text' => 'Ini adalah analisis pertama Anda.'];
        }

        $diff = $latest->final_risk_percentage - $previous->final_risk_percentage;
        $changeText = abs($diff) . '% dari analisis sebelumnya';

        if ($diff < -0.1) {
            return ['direction' => 'improving', 'change_value' => round($diff, 2), 'text' => '↙ Membaik ' . $changeText];
        } elseif ($diff > 0.1) {
            return ['direction' => 'worsening', 'change_value' => round($diff, 2), 'text' => '↗ Memburuk ' . $changeText];
        } else {
            return ['direction' => 'stable', 'change_value' => 0, 'text' => '→ Stabil dari analisis sebelumnya.'];
        }
    }

    private function formatGraphData($assessments): array
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $filtered = $assessments->where('created_at', '>=', $thirtyDaysAgo)->reverse();

        return [
            'labels' => $filtered->map(fn($item) => Carbon::parse($item->created_at)->format('d M')),
            'values' => $filtered->map(fn($item) => $item->final_risk_percentage),
        ];
    }

    private function formatProgramOverview(?CoachingProgram $program): ?array
    {
        // Debug: Log untuk melihat kondisi program
        Log::info('formatProgramOverview Debug:', [
            'program_is_null' => is_null($program),
            'program_empty_check' => !$program,
            'program_data' => $program ? $program->toArray() : 'null'
        ]);

        // Return null jika program kosong atau null
        if (!$program) {
            return null;
        }

        $today = Carbon::now()->startOfDay();
        $programStartDate = Carbon::parse($program->start_date)->startOfDay();
        $programEndDate = Carbon::parse($program->end_date)->startOfDay();
        $totalWeeks = (int) $program->weeks->count();
        $totalDays = $totalWeeks * 7;

        $currentDay = 0;
        $currentWeek = 0;

        // Logika berdasarkan status program
        if ($program->status === 'active' && $today->between($programStartDate, $programEndDate)) {
            // Program sedang aktif
            $daysPassed = $today->diffInDays($programStartDate); // Tidak perlu 'false' lagi
            $currentDay = $daysPassed + 1;
            $currentWeek = floor($daysPassed / 7) + 1;

            // Pastikan tidak melebihi total
            if ($currentDay > $totalDays) $currentDay = $totalDays;
            if ($currentWeek > $totalWeeks) $currentWeek = $totalWeeks;
        } elseif ($program->status === 'completed' || $today->isAfter($programEndDate)) {
            // Program sudah selesai
            $currentDay = $totalDays;
            $currentWeek = $totalWeeks;
        } elseif ($today->isBefore($programStartDate)) {
            // Program belum dimulai
            $currentDay = 0;
            $currentWeek = 1; // Atau 0, sesuai kebutuhan UI
        }

        return [
            'is_active'      => $program->status === 'active' && $today->between($programStartDate, $programEndDate),
            'slug'           => $program->slug,
            'title'          => $program->title,
            'description'    => $program->description,
            'status'         => $program->status,
            'start_date'     => Carbon::parse($program->start_date)->isoFormat('D MMMM YYYY'),
            'end_date'       => Carbon::parse($program->end_date)->isoFormat('D MMMM YYYY'),
            'progress'       => [
                'current_week'          => (int) $currentWeek,
                'total_weeks'            => $totalWeeks,
                'current_day_in_program' => (int) $currentDay,
                'total_days_in_program'  => $totalDays,
            ],
        ];
    }
}
