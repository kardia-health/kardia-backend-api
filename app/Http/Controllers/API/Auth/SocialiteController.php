<?php

namespace App\Http\Controllers\API\Auth;

use App\Actions\Auth\FindOrCreateUserFromSocialiteAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable; // Import Throwable untuk menangani semua jenis error/exception

/**
 * Class SocialiteController
 *
 * Mengelola alur otentikasi pengguna melalui layanan pihak ketiga (Socialite)
 * seperti Google, Facebook, dll.
 */
class SocialiteController extends Controller
{
    /**
     * Mengarahkan pengguna ke halaman otentikasi penyedia layanan sosial.
     *
     * @param string $provider Nama penyedia layanan sosial (e.g., 'google', 'facebook').
     * @return RedirectResponse Respon pengalihan ke URL otentikasi penyedia.
     */
    public function redirectToProvider(string $provider): RedirectResponse
    {
        try {
            // Menggunakan driver Socialite yang sesuai dengan penyedia dan mengaturnya tanpa stateful
            // untuk API stateless. Menetapkan cakupan (scopes) yang diperlukan untuk mendapatkan
            // informasi profil dan email pengguna.
            return Socialite::driver($provider)
                ->stateless()
                ->scopes(['openid', 'profile', 'email'])
                ->redirect();
        } catch (Throwable $e) {
            // Log error jika terjadi masalah saat mengarahkan ke penyedia.
            // Gunakan Throwable untuk menangkap semua jenis error dan exception.
            Log::error("Failed to redirect to socialite provider '{$provider}'.", [
                'error_message' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString(),
                'provider' => $provider,
            ]);
            // Mengarahkan kembali ke frontend dengan pesan error yang sesuai.
            return $this->redirectWithError(
                'socialite_redirect_failed',
                "Could not initiate authentication with {$provider}. Please try again."
            );
        }
    }

    /**
     * Menangani panggilan balik (callback) dari penyedia layanan sosial
     * setelah pengguna berhasil otentikasi.
     *
     * @param string $provider Nama penyedia layanan sosial.
     * @param FindOrCreateUserFromSocialiteAction $action Sebuah Action class untuk mencari atau membuat pengguna.
     * @return RedirectResponse Respon pengalihan ke frontend dengan token otentikasi atau pesan error.
     */
    public function handleProviderCallback(string $provider, FindOrCreateUserFromSocialiteAction $action): RedirectResponse
    {
        try {
            // Mengambil informasi pengguna dari penyedia layanan sosial.
            $socialiteUser = Socialite::driver($provider)->stateless()->user();

            // Menggunakan Action class untuk mencari atau membuat pengguna berdasarkan
            // informasi dari Socialite dan mengaitkannya.
            $user = $action->execute($provider, $socialiteUser);

            // Membuat token API Personal Access Token untuk pengguna yang berhasil otentikasi.
            $token = $user->createToken('socialite_auth_token')->plainTextToken;

            // Membangun URL pengalihan ke frontend dengan token sebagai query parameter.
            // URL callback frontend diambil dari konfigurasi aplikasi.
            $frontendCallbackUrl = config('app.frontend_url') . '/auth/callback';

            $queryParams = http_build_query([
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);

            return redirect()->away("{$frontendCallbackUrl}?{$queryParams}");
        } catch (Throwable $e) {
            // Log error jika terjadi masalah selama proses callback atau pembuatan/pencarian pengguna.
            Log::error("Socialite callback for '{$provider}' failed.", [
                'error_message' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString(),
                'provider' => $provider,
            ]);

            // Mengarahkan kembali ke frontend dengan pesan error yang sesuai.
            return $this->redirectWithError(
                'socialite_callback_failed',
                'An error occurred during social authentication. Please try again.'
            );
        }
    }

    /**
     * Mengarahkan pengguna kembali ke URL login frontend dengan pesan error yang diformat.
     * Metode ini bersifat private karena hanya digunakan di dalam controller ini.
     *
     * @param string $errorCode Kode error unik untuk diidentifikasi di sisi frontend.
     * @param string $message Pesan error yang dapat dibaca pengguna.
     * @return RedirectResponse Respon pengalihan ke URL login frontend.
     */
    private function redirectWithError(string $errorCode, string $message): RedirectResponse
    {
        // Mengambil URL login frontend dari konfigurasi aplikasi.
        $frontendLoginUrl = config('app.frontend_url') . '/login';

        // Membangun query parameter untuk URL error.
        $queryParams = http_build_query([
            'error_code' => $errorCode, // Mengubah 'error' menjadi 'error_code' untuk kejelasan
            'error_message' => $message, // Mengubah 'message' menjadi 'error_message'
        ]);

        // Mengarahkan ke URL login frontend dengan parameter error.
        return redirect()->away("{$frontendLoginUrl}?{$queryParams}");
    }
}
