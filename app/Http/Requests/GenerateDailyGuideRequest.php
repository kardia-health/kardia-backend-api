<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateDailyGuideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mendapatkan aturan validasi untuk input harian dari modal box.
     */
    public function rules(): array
    {
        return [
            'plan_type' => ['required', 'string', Rule::in(['Masak di Rumah', 'Makan di Luar'])],
            'time_availability' => ['required', 'string', Rule::in(['Cepat & Praktis', 'Santai & Ada Waktu'])],
            'energy_level' => ['required', 'string', Rule::in(['Penuh Semangat', 'Biasa Saja', 'Sedang Lelah'])],
            'cuisine_preference' => ['required', 'string', 'max:100'],
            'craving_type' => [
                'nullable',
                'string',
                Rule::in(['Berkuah & Hangat', 'Bakar / Panggang', 'Segar & Ringan', 'Tumis Cepat'])
            ],
            'social_context' => [
                'nullable',
                'string',
                Rule::in(['Sendiri', 'Bersama Teman' ,'Bersama Pasangan', 'Bersama Keluarga'])
            ],
        ];
    }
}
