<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyMealGuideResource extends JsonResource
{
    /**
     * Mengubah resource menjadi sebuah array.
     */
    public function toArray(Request $request): array
    {
        // Tidak perlu lagi variabel lokal yang rumit karena datanya sudah benar.
        return [
            'id' => $this->id,
            'guide_date' => Carbon::parse($this->guide_date)->isoFormat('dddd, D MMMM YYYY'),

            // Sekarang aman untuk diakses secara langsung
            'generation_context' => $this->generation_context,
            'guide_data' => $this->guide_data,

            'created_at_human' => $this->created_at->diffForHumans(),
        ];
    }
}
