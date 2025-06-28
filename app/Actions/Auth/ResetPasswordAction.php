<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ResetPasswordAction
{
    // UserRepository bisa di-inject jika Anda ingin menghapus cache resource user
    public function __construct(
        private UserRepository $userRepository
    ) {}

    public function execute(array $data): bool
    {
        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            // Melempar exception adalah cara yang lebih baik untuk menangani error di Actions/Services
            throw ValidationException::withMessages([
                'email' => ['We can\'t find a user with that email address.'],
            ]);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        // Jika Anda meng-cache UserResource, hapus di sini
        $this->userRepository->forgetUserCache($user);

        return true;
    }
}
