<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assessments = $this->resource;
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
        $history = $assessments->map(function ($item) {
            return [
                'slug' => $item->slug,
                'date' => Carbon::parse($item->created_at)->isoFormat('D MMMM YYYY'),
                'risk_percentage' => $item->final_risk_percentage,
                'risk_category' => $item->result_details['riskSummary']['riskCategory']['title'] ?? 'N/A',
            ];
        });

        return [
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
}
