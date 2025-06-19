<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @OA\Schema(
 * schema="Request_Register",
 * title="Request_Register",
 * required={"name", "email", "password", "password_confirmation"},
 * @OA\Property(property="name", type="string", example="John Doe"),
 * @OA\Property(property="email", type="string", format="email", example="john.doe@example.com"),
 * @OA\Property(property="password", type="string", format="password", example="password123"),
 * @OA\Property(property="password_confirmation", type="string", format="password", example="password123")
 * )
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // No authorization required to register
    }

    public function rules(): array
    {
        return [
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ];
    }
}
