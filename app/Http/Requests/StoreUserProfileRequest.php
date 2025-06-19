<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Otorisasi ditangani oleh middleware
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'sex' => ['required', 'string', Rule::in(['male', 'female'])],
            'country_of_residence' => ['required', 'string', 'max:255'],
            'language' => ['required', 'string', \Illuminate\Validation\Rule::in(['id', 'en'])],

        ];
    }
}
