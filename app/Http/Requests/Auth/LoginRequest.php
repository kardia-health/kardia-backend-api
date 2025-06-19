<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @OA\Schema(
 * schema="Request_Login",
 * title="Request_Login",
 * required={"email", "password"},
 * @OA\Property(property="email", type="string", format="email", example="john.doe@example.com"),
 * @OA\Property(property="password", type="string", format="password", example="password123"),
 * )
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // No authorization required to attempt login
    }

    public function rules(): array
    {
        return [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ];
    }
}
