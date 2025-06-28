<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskAssessmentSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Pengecekan keamanan untuk memastikan resource tidak null
        if (is_null($this->resource)) {
            return [];
        }
        return [
            'slug'            => $this->slug,
            'risk_percentage' => $this->final_risk_percentage,
            // Ambil judul kategori risiko dari dalam kolom JSON 'result_details'
            'risk_category'   => $this->result_details['riskSummary']['riskCategory']['title'] ?? 'N/A',
            // Format tanggal agar mudah dibaca
            'analysis_date'   => Carbon::parse($this->created_at)->isoFormat('D MMMM YYYY'),
            // Menambahkan status program coaching yang terhubung
            'coaching_status' => $this->coachingProgram->status ?? null,
        ];
    }
}
