<?php

use App\Http\Controllers\API\Auth\AuthController;
use App\Http\Controllers\API\Auth\SocialiteController;
use App\Http\Controllers\API\ChatController;
use App\Http\Controllers\API\CoachingController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\KardiaController;
use App\Http\Controllers\API\UserProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
  // Public routes

  // Authentication routes
  Route::post('register', [AuthController::class, 'register'])->name('api.register');
  Route::post('login', [AuthController::class, 'login'])->name('api.login');
  Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirectToProvider'])->name('api.auth.provider.redirect');
  Route::get('/auth/{provider}/callback', [SocialiteController::class, 'handleProviderCallback'])->name('api.auth.provider.callback');

  // Password reset routes
  Route::patch('/reset-password', [AuthController::class, 'resetPassword']);

  // Logout account 
  Route::post('logout', [AuthController::class, 'logout'])->name('api.logout');

  // Delete account
  Route::delete('/delete-account', [AuthController::class, 'deleteAccount'])->name('api.delete-account');

  // Admin routes
  Route::prefix('admin')->name('admin.')->group(function () {});

  // Protected routes
  Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [UserProfileController::class, 'show']);
    Route::patch('/profile', [UserProfileController::class, 'update']);

    // Endpoint untuk memulai analisis & mendapatkan skor numerik CEPAT
    Route::post('/risk-assessments', [KardiaController::class, 'startAssessment']);
    // Endpoint untuk mendapatkan laporan personalisasi AI yang lebih LAMBAT
    Route::patch('/risk-assessments/{assessment:slug}/personalize', [KardiaController::class, 'generatePersonalizedReport']);

    // Endpoint untuk mendapatkan detail lengkap dari satu analisis
    Route::get('/dashboard', [DashboardController::class, 'getDashboardData']);


    Route::prefix('chat')->controller(ChatController::class)->group(function () {
      // Mengambil daftar semua percakapan milik pengguna (untuk sidebar).
      Route::get('/conversations', 'index');

      // Membuat sesi percakapan BARU yang masih kosong.
      // Mengembalikan slug unik untuk digunakan di langkah selanjutnya.
      Route::post('/conversations', 'store');

      // Mengambil seluruh riwayat pesan dari SATU percakapan spesifik.
      Route::get('/conversations/{conversation:slug}', 'show');

      // Memperbarui judul percakapan yang sudah ada.
      Route::patch('/conversations/{conversation:slug}', 'update');

      // Mengirim pesan baru ke percakapan dan mendapatkan balasan.
      // Ini adalah endpoint kerja utama untuk semua interaksi chat.
      Route::post('/conversations/{conversation:slug}/messages', 'sendMessage');

      // Menghapus sebuah percakapan.
      Route::delete('/conversations/{conversation:slug}', 'destroy');
    });

    // ==========================================================
    // GRUP RUTE BARU & LENGKAP UNTUK FITUR KARDIA COACH
    // ==========================================================
    Route::prefix('coaching')->controller(CoachingController::class)->middleware('auth:sanctum')->group(function () {

      // ==========================================================
      // RUTE LEVEL PROGRAM COACHING
      // ==========================================================

      /**
       * Memulai program coaching baru dari sebuah hasil analisis risiko.
       * [POST] /api/coaching/programs
       */
      Route::post('/programs', 'startProgram');

      /**
       * Mengambil detail lengkap sebuah program (termasuk kurikulumnya).
       * [GET] /api/coaching/programs/{program:slug}
       */
      Route::get('/programs/{program:slug}', 'showProgram');

      /**
       * [BARU] Menjeda (pause) atau Melanjutkan kembali (resume) sebuah program.
       * [PATCH] /api/coaching/programs/{program:slug}/toggle-program-status
       */
      Route::patch('/programs/{program:slug}/toggle-program-status', 'toggleProgramStatus');

      /**
       * [BARU] Membatalkan sebuah program secara permanen.
       * [DELETE] /api/coaching/programs/{program:slug}
       */
      Route::delete('/programs/{program:slug}', 'destroyProgram');


      // ==========================================================
      // RUTE LEVEL THREAD PERCAKAPAN (di dalam sebuah Program)
      // ==========================================================

      /**
       * [BARU] Membuat thread percakapan BARU yang masih kosong di dalam sebuah program.
       * [POST] /api/coaching/programs/{program:slug}/threads
       */
      Route::post('/programs/{program:slug}/threads', 'startNewThread');

      /**
       * [BARU] Mengirim pesan baru ke sebuah thread dan mendapatkan balasan AI.
       * [POST] /api/coaching/threads/{thread:slug}/messages
       */
      Route::post('/threads/{thread:slug}/messages', 'sendMessageToThread');

      /**
       * [BARU] Mengambil seluruh riwayat pesan dari SATU thread spesifik.
       * [GET] /api/coaching/threads/{thread:slug}
       */
      Route::get('/threads/{thread:slug}', 'showThread');

      /**
       * [BARU] Memperbarui judul sebuah thread percakapan.
       * [PATCH] /api/coaching/threads/{thread:slug}
       */
      Route::patch('/threads/{thread:slug}', 'updateThread');

      /**
       * [BARU] Menghapus sebuah thread percakapan.
       * [DELETE] /api/coaching/threads/{thread:slug}
       */
      Route::delete('/threads/{thread:slug}', 'destroyThread');


      // ==========================================================
      // RUTE LEVEL TUGAS HARIAN (di dalam sebuah Program)
      // ==========================================================

      /**
       * Menandai sebuah tugas harian sebagai selesai atau tidak selesai.
       * [PATCH] /api/coaching/tasks/{task:id}
       */
      Route::patch('/tasks/{task}/toggle-task-status', 'toggleTaskStatus');

      /**
       * [BARU] Mengambil Laporan Kelulusan yang sudah di-generate untuk sebuah program.
       * [GET] /api/coaching/programs/{program:slug}/graduation-report
       */
      Route::get('/programs/{program:slug}/graduation-report', 'showGraduationReport');
    });
  });
});
