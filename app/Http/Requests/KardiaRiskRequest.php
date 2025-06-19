<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Class KardiaRiskRequest
 * Memvalidasi permintaan masuk untuk kalkulasi risiko Kardia v11.0.
 * Dirancang untuk menangani alur input granular yang sangat fleksibel,
 * di mana setiap parameter klinis bisa diisi manual atau diestimasi via proksi.
 */
class KardiaRiskRequest extends FormRequest
{
    /**
     * Menentukan apakah pengguna diizinkan untuk membuat permintaan ini.
     * @return bool
     */
    public function authorize(): bool
    {
        // Di aplikasi nyata, otorisasi akan ditangani oleh middleware (misal: Sanctum).
        // Untuk tujuan endpoint ini, kita izinkan.
        return true;
    }

    /**
     * Mendapatkan aturan validasi yang berlaku untuk permintaan ini.
     * Aturan ini sangat dinamis dan bergantung pada input pengguna.
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // --- Aturan untuk Data Inti yang Selalu Diperlukan ---
        $rules = [
            // 'age' => ['required', 'integer', 'min:40', 'max:100'],
            // 'sex' => ['required', 'string', Rule::in(['male', 'female'])],
            'has_diabetes' => ['required', 'boolean'],
            'smoking_status' => ['required', 'string', Rule::in(['Perokok aktif', 'Bukan perokok saat ini'])],
            // 'risk_region' => ['required', 'string', Rule::in(['low', 'moderate', 'high', 'very_high'])],
        ];

        // --- Aturan Validasi Granular untuk Setiap Parameter Klinis ---

        // 1. Tekanan Darah Sistolik (SBP)
        $rules['sbp_input_type'] = ['required', 'string', Rule::in(['manual', 'proxy'])];
        $rules['sbp_value'] = ['nullable', 'required_if:sbp_input_type,manual', 'numeric', 'min:50', 'max:300'];
        $rules['sbp_proxy_answers'] = ['nullable', 'required_if:sbp_input_type,proxy', 'array'];
        // (Anda bisa menambahkan validasi lebih dalam untuk setiap key di dalam array proxy jika perlu)

        // 2. Kolesterol Total (tchol)
        $rules['tchol_input_type'] = ['required', 'string', Rule::in(['manual', 'proxy'])];
        $rules['tchol_value'] = ['nullable', 'required_if:tchol_input_type,manual', 'numeric', 'min:1', 'max:20'];
        $rules['tchol_proxy_answers'] = ['nullable', 'required_if:tchol_input_type,proxy', 'array'];

        // 3. Kolesterol HDL (hdl)
        $rules['hdl_input_type'] = ['required', 'string', Rule::in(['manual', 'proxy'])];
        $rules['hdl_value'] = ['nullable', 'required_if:hdl_input_type,manual', 'numeric', 'min:0.1', 'max:5'];
        $rules['hdl_proxy_answers'] = ['nullable', 'required_if:hdl_input_type,proxy', 'array'];

        // --- Aturan Kondisional jika Pengguna Memiliki Diabetes ---
        if ($this->input('has_diabetes')) {
            $rules['age_at_diabetes_diagnosis'] = ['required', 'integer', 'min:1', 'max:' . $this->input('age')];

            // 4. HbA1c
            $rules['hba1c_input_type'] = ['required', 'string', Rule::in(['manual', 'proxy'])];
            $rules['hba1c_value'] = ['nullable', 'required_if:hba1c_input_type,manual', 'numeric', 'min:20', 'max:200'];
            $rules['hba1c_proxy_answers'] = ['nullable', 'required_if:hba1c_input_type,proxy', 'array'];

            // 5. Serum Creatinine (Scr)
            $rules['scr_input_type'] = ['required', 'string', Rule::in(['manual', 'proxy'])];
            $rules['scr_value'] = ['nullable', 'required_if:scr_input_type,manual', 'numeric', 'min:0.1', 'max:15'];
            $rules['scr_proxy_answers'] = ['nullable', 'required_if:scr_input_type,proxy', 'array'];
        }

        return $rules;
    }
}
