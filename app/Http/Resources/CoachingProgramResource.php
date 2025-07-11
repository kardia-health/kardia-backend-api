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
        $now = Carbon::now();

        // --- Kalkulasi Sisa Hari (days_remaining) ---
        $daysRemaining = 0;
        // Siapkan juga format tanggalnya, beri nilai default jika datanya NULL
        $endDateFormatted = 'Belum ditentukan';
        if ($this->end_date) {
            // Hanya parse dan kalkulasi jika data end_date ada
            $endDate = Carbon::parse($this->end_date)->endOfDay();
            $floatDays = Carbon::now()->floatDiffInDays($endDate, false);
            $daysRemaining = floor(max(0, $floatDays));
            $endDateFormatted = $endDate->isoFormat('D MMMM YYYY');
        }

        // --- Kalkulasi Minggu Saat Ini (current_week_number) ---
        $currentWeekNumber = 1; // Nilai default
        $startDateFormatted = 'Belum ditentukan';
        if ($this->start_date) {
            // Hanya parse dan kalkulasi jika data start_date ada
            $programStartDate = Carbon::parse($this->start_date)->startOfDay();
            $daysPassed = max(0, $now->startOfDay()->diffInDays($programStartDate));
            $currentWeekNumber = floor($daysPassed / 7) + 1;
            $startDateFormatted = Carbon::parse($this->start_date)->isoFormat('D MMMM YYYY');
        }

        // --- Kalkulasi lainnya ---
        $allTasks = $this->weeks->pluck('tasks')->flatten();
        $totalTasks = $allTasks->count();
        $completedTasks = $allTasks->where('is_completed', true)->count();
        $overallCompletionPercentage = ($totalTasks > 0) ? round(($completedTasks / $totalTasks) * 100) : 0;

        return [
            'slug'           => $this->slug,
            'title'          => $this->title,
            'description'    => $this->description,
            'difficulty'     => $this->difficulty,
            'status'         => $this->status,
            'start_date'     => $startDateFormatted,
            'end_date'       => $endDateFormatted,

            'overall_progress' => [
                'days_remaining'                => (int) $daysRemaining,
                'overall_completion_percentage' => $overallCompletionPercentage,
                'current_week_number'           => (int) $currentWeekNumber,
            ],

            'program_context' => [
                'source_assessment' => new RiskAssessmentSummaryResource($this->whenLoaded('riskAssessment')),
            ],

            'weeks' => CoachingWeekResource::collection($this->whenLoaded('weeks')),
            'threads' => CoachingThreadResource::collection($this->whenLoaded('threads')),
        ];
    }
}
