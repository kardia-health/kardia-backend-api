<?php

use App\Http\Controllers\API\Auth\AuthController;
use App\Http\Controllers\API\Auth\SocialiteController;
use App\Http\Controllers\API\ChatController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\KardiaController;
use App\Http\Controllers\API\UserProfileController;
use App\Http\Controllers\DebugController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
  // Public routes
  Route::post('register', [AuthController::class, 'register'])->name('api.register');
  Route::post('login', [AuthController::class, 'login'])->name('api.login');
  Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirectToProvider'])->name('api.auth.provider.redirect');
  Route::get('/auth/{provider}/callback', [SocialiteController::class, 'handleProviderCallback'])->name('api.auth.provider.callback');

  Route::patch('/reset-password', [AuthController::class, 'resetPassword']);

  // Protected routes
  Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [UserProfileController::class, 'show']);
    Route::patch('/profile', [UserProfileController::class, 'patch']);

    // Endpoint untuk memulai analisis & mendapatkan skor numerik CEPAT
    Route::post('/risk-assessments', [KardiaController::class, 'startAssessment']);
    // Endpoint untuk mendapatkan laporan personalisasi AI yang lebih LAMBAT
    Route::patch('/risk-assessments/{assessment:slug}/personalize', [KardiaController::class, 'generatePersonalizedReport']);

    // Endpoint untuk mendapatkan detail lengkap dari satu analisis
    Route::get('/dashboard', [DashboardController::class, 'getDashboardData']);


    Route::prefix('chat')->controller(ChatController::class)->group(function () {
    /**
       * Mengambil daftar semua percakapan milik pengguna (untuk sidebar).
       * GET /api/chat/conversations
       */
      Route::get('/conversations', 'index');

      /**
       * Membuat sesi percakapan BARU yang masih kosong.
       * Mengembalikan slug unik untuk digunakan di langkah selanjutnya.
       * POST /api/chat/conversations
       */
      Route::post('/conversations', 'store');

      /**
       * Mengambil seluruh riwayat pesan dari SATU percakapan spesifik.
       * GET /api/chat/conversations/{conversation:slug}
       */
      Route::get('/conversations/{conversation:slug}', 'show');

      /**
       * Memperbarui judul percakapan yang sudah ada.
       * PUT /api/chat/conversations/{conversation:slug}
       */
      Route::patch('/conversations/{conversation:slug}', 'update');

      /**
       * Mengirim pesan baru ke percakapan dan mendapatkan balasan.
       * Ini adalah endpoint kerja utama untuk semua interaksi chat.
       * POST /api/chat/conversations/{conversation:slug}/messages
       */
      Route::post('/conversations/{conversation:slug}/messages', 'sendMessage');

      /**
       * Menghapus sebuah percakapan.
       * DELETE /api/chat/conversations/{conversation:slug}
       */
      Route::delete('/conversations/{conversation:slug}', 'destroy');
    });


    Route::post('logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::delete('/delete-account', [AuthController::class, 'deleteAccount'])->name('api.delete-account');

    // Admin routes
    Route::prefix('admin')->name('admin.')->group(function () {});
  });
});
