<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterUserAction
{
    public function execute(array $data): User
    {
        return DB::transaction(function () use ($data) {
            // Buat user baru
            $user = User::create([
                'email'    => $data['email'],
                'password' => Hash::make($data['password']),
                'role'     => $data['role'] ?? 'user',
            ]);

            // Buat profil kosong
            $user->profile()->create([
                'user_id'              => $user->id,
                'first_name'           => '',
                'last_name'            => '',
                'date_of_birth'        => null,
                'sex'                  => null,
                'country_of_residence' => '',
                'language'           => 'id',
            ]);

            return $user;
        });
    }
}
