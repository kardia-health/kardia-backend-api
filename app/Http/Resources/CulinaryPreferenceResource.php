<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CulinaryPreferenceResource extends JsonResource
{
    /**
     * Mengubah resource menjadi sebuah array.
     */
    public function toArray(Request $request): array
    {
        // Jika resource (data preferensi) kosong atau null, kembalikan objek kosong
        if (is_null($this->resource) || empty($this->resource)) {
            return [
                'allergies' => '',
                'budget_level' => 'Standar',
                'cooking_style' => 'Masak Cepat Setiap Saat',
                'taste_profiles' => [],
                'kitchen_equipment' => [],
            ];
        }

        // Jika ada, kembalikan datanya dengan nilai default jika ada key yang hilang
        return [
            'allergies'           => $this['allergies'] ?? '',
            'budget_level'        => $this['budget_level'] ?? 'Standar',
            'cooking_style'       => $this['cooking_style'] ?? 'Masak Cepat Setiap Saat',
            'taste_profiles'      => $this['taste_profiles'] ?? [],
            'kitchen_equipment'   => $this['kitchen_equipment'] ?? [],
        ];
    }
}
