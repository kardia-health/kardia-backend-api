<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Hash;

class RegisterUserAction
{
    public function execute(array $data): User
    {
        // Buat user baru
        $user = User::create([
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'role'     => $data['role'] ?? 'user',
        ]);

        // Buat profil kosong
        UserProfile::create([
            'user_id'              => $user->id,
            'first_name'           => '',
            'last_name'            => '',
            'date_of_birth'        => null,
            'sex'                  => null,
            'country_of_residence' => '',
        ]);

        return $user;
    }
}
