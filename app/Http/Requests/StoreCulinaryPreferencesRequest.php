<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCulinaryPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Izinkan semua pengguna yang terotentikasi.
    }

    /**
     * Mendapatkan aturan validasi untuk menyimpan preferensi kuliner jangka panjang.
     */
    public function rules(): array
    {
        return [
            'allergies'           => 'nullable|string|max:1000',
            'budget_level'        => ['sometimes', 'string', Rule::in(['Hemat', 'Standar', 'Fleksibel'])],
            'cooking_style'       => ['sometimes', 'string', Rule::in(['Masak Cepat Setiap Saat', 'Suka Masak Porsi Besar (Meal Prep)'])],
            'taste_profiles'      => 'sometimes|array',
            'taste_profiles.*'    => 'string|max:50',
            'kitchen_equipment'   => 'sometimes|array',
            'kitchen_equipment.*' => 'string|max:50',
        ];
    }
}
