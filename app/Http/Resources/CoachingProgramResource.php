<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class CoachingProgramResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // === Kalkulasi untuk Ringkasan Program Keseluruhan ===

        // Hitung sisa hari program
        $endDate = Carbon::parse($this->end_date);
        $floatDays = Carbon::now()->floatDiffInDays($endDate, false);
        $daysRemaining = floor(max(0, $floatDays));

        // Hitung persentase penyelesaian keseluruhan
        $allTasks = $this->weeks->pluck('tasks')->flatten();
        $totalTasks = $allTasks->count();
        $completedTasks = $allTasks->where('is_completed', true)->count();
        $overallCompletionPercentage = ($totalTasks > 0) ? round(($completedTasks / $totalTasks) * 100) : 0;

        // Tentukan minggu aktif saat ini
        $programStartDate = Carbon::parse($this->start_date)->startOfDay();
        $daysPassed = Carbon::now()->startOfDay()->diffInDays($programStartDate, false);
        $currentWeekNumber = floor($daysPassed / 7) + 1;

        return [
            'slug'           => $this->slug,
            'title'          => $this->title,
            'description'    => $this->description,
            'difficulty'     => $this->difficulty,
            'status'         => $this->status,
            'start_date'     => Carbon::parse($this->start_date)->isoFormat('D MMMM YYYY'),
            'end_date'       => $endDate->isoFormat('D MMMM YYYY'),

            // === Objek Ringkasan Program Keseluruhan ===
            'overall_progress' => [
                'days_remaining'                => $daysRemaining,
                'overall_completion_percentage' => $overallCompletionPercentage,
                'current_week_number'           => (int) $currentWeekNumber,
            ],

            // === Objek Konteks & Tujuan Program ===
            'program_context' => [
                // Kita gunakan 'whenLoaded' untuk efisiensi, hanya jika data assessment di-load
                'source_assessment' => new RiskAssessmentSummaryResource($this->whenLoaded('riskAssessment')),
            ],

            // === Detail Mingguan (menggunakan CoachingWeekResource) ===
            'weeks' => CoachingWeekResource::collection($this->whenLoaded('weeks')),

            // === Daftar Thread Chat (menggunakan CoachingThreadResource) ===
            'threads' => CoachingThreadResource::collection($this->whenLoaded('threads')),
        ];
    }
}
