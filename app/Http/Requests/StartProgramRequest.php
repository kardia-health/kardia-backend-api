<?php

namespace App\Http\Requests;

use App\Models\RiskAssessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;


class StartProgramRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Ambil slug dari data yang dikirim
        $slug = $this->input('risk_assessment_slug');

        // Jika tidak ada slug, biarkan validasi 'required' yang menanganinya nanti.
        if (!$slug) {
            return true;
        }

        // Cari assessment di database berdasarkan slug yang dikirim.
        $assessment = RiskAssessment::where('slug', $slug)->first();

        // Jika assessment dengan slug tersebut tidak ada, biarkan validasi 'exists' yang menanganinya.
        if (!$assessment) {
            return true;
        }

        // =================================================================
        // INI LOGIKA KUNCI OTORISASI
        // =================================================================
        // Aksi ini diizinkan HANYA JIKA ID profil dari assessment yang ditemukan
        // sama dengan ID profil dari pengguna yang sedang membuat permintaan.
        return $this->user()->profile->id == $assessment->user_profile_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Ini adalah aturan validasi yang kita pindahkan dari controller.
        return [
            'risk_assessment_slug' => [
                'required',
                'string',
                // Memastikan slug yang dikirim ada di tabel risk_assessments
                Rule::exists('risk_assessments', 'slug')
            ],
            'difficulty' => [
                'required',
                'string',
                // Memastikan nilainya hanya salah satu dari tiga pilihan ini
                Rule::in(['Santai & Bertahap', 'Standar & Konsisten', 'Intensif & Menantang'])
            ]
        ];
    }
}
