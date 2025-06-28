<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoachingWeekResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Ambil tanggal mulai program dari relasi untuk dasar perhitungan
        $programStartDate = Carbon::parse($this->program->start_date);

        // Hitung tanggal mulai dan akhir untuk minggu ini secara dinamis
        $startDateOfWeek = $programStartDate->copy()->addWeeks($this->week_number - 1);
        $endDateOfWeek = $startDateOfWeek->copy()->addDays(6);

        // Hitung status minggu ini secara dinamis
        $now = Carbon::now();
        $status = 'locked';
        if ($now->isAfter($endDateOfWeek)) {
            $status = 'completed';
        } elseif ($now->isBetween($startDateOfWeek, $endDateOfWeek)) {
            $status = 'active';
        }

        // Hitung persentase penyelesaian tugas di minggu ini
        $totalTasks = $this->tasks->count();
        $completedTasks = $this->tasks->where('is_completed', true)->count();
        $completionPercentage = ($totalTasks > 0) ? round(($completedTasks / $totalTasks) * 100) : 0;

        return [
            'week_number'           => $this->week_number,
            'title'                 => $this->title,
            'description'           => $this->description,

            // [DATA DINAMIS BARU]
            'start_date'            => $startDateOfWeek->isoFormat('D MMMM YYYY'),
            'end_date'              => $endDateOfWeek->isoFormat('D MMMM YYYY'),
            'status'                => $status,
            'completion_percentage' => $completionPercentage,

            // Gunakan 'CoachingTaskResource' untuk memformat setiap tugas
            'tasks'                 => CoachingTaskResource::collection($this->whenLoaded('tasks')),
        ];
    }
}
