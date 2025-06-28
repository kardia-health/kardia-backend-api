<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoachingGraduationReportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Resource ini hanya mengambil data dari kolom 'graduation_report'
        // yang sudah dalam format array yang benar.
        return $this->graduation_report ?? [
            'error' => 'Laporan kelulusan belum tersedia atau sedang diproses.'
        ];
    }
}
