<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartProgramRequest;
use App\Http\Resources\CoachingGraduationReportResource;
use App\Http\Resources\CoachingProgramResource;
use App\Http\Resources\CoachingThreadDetailResource;
use App\Http\Resources\CoachingThreadResource;
use App\Models\CoachingProgram;
use App\Models\CoachingTask;
use App\Models\CoachingThread;
use App\Repositories\CoachingMessageRepository;
use App\Repositories\CoachingRepository;
use App\Repositories\CoachingTaskRepository;
use App\Repositories\CoachingThreadRepository;
use App\Repositories\DashboardRepository;
use App\Services\ChatCoachService;
use App\Services\CoachingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CoachingController extends Controller
{
    // Inject semua service dan repository yang dibutuhkan
    public function __construct(



        private CoachingService $coachingService,
        private CoachingRepository $coachingRepository,
        private ChatCoachService $chatCoachService,
        private CoachingThreadRepository $threadRepository,
        private CoachingTaskRepository $taskRepository,
        private DashboardRepository $dashboardRepository,
        private CoachingMessageRepository $coachingMessageRepository




    ) {}

    /**
     * [POST /coaching/programs] - Memulai program coaching baru.
     */
    public function startProgram(StartProgramRequest  $request): JsonResponse
    {
        $validatedData = $request->validated();

        $user = $request->user();
        $assessment = $user->profile->riskAssessments()->where('slug', $validatedData['risk_assessment_slug'])->firstOrFail();

        if ($assessment->coachingProgram()->exists()) {
            return response()->json([
                'message' => 'A coaching program based on this assessment already exists.',
                'error' => 'Tidak dapat membuat program duplikat. Hapus program yang sudah ada jika Anda ingin membuat yang baru dari analisis ini.'
            ], 409); // 409 Conflict adalah status HTTP yang paling tepat untuk kasus ini.
        }

        $program = $this->coachingService->initiateProgram($user, $assessment, $validatedData['difficulty']);

        // Muat semua relasi yang dibutuhkan oleh resource
        $program->load([
            'riskAssessment',       // <-- Muat relasi ini agar 'source_assessment' muncul
            'weeks.tasks',          // Muat relasi bersarang: weeks, dan tasks di dalam setiap week
            'threads'               // Muat semua thread chat
        ]);

        $this->dashboardRepository->forgetDashboardCache($request->user());

        return (new CoachingProgramResource($program))->response()->setStatusCode(201);
    }

    /**
     * [GET /coaching/programs/{program:slug}] - Mengambil detail lengkap sebuah program.
     * Diubah untuk menggunakan Repository agar konsisten.
     */
    public function showProgram(Request $request, string $slug): JsonResponse
    {
        // Ambil data dari Repository, yang akan cek cache terlebih dahulu
        $program = $this->coachingRepository->findAndCacheProgramBySlug($slug);

        // Jika tidak ditemukan sama sekali
        if (!$program) {
            abort(404, 'Program not found.');
        }

        // Otorisasi: Pastikan pengguna hanya bisa mengakses program miliknya
        if ($request->user()->profile->id !== $program->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }

        $program->load([
            'riskAssessment',       // <-- Muat relasi ini agar 'source_assessment' muncul
            'weeks.tasks',          // Muat relasi bersarang: weeks, dan tasks di dalam setiap week
            'threads'               // Muat semua thread chat
        ]);

        // Pastikan semua thread juga dimuat dengan pesan-pesan mereka
        $threads = $program->threads;
        $threads->load('messages');

        return (new CoachingProgramResource($program))->response();
    }

    public function toggleProgramStatus(Request $request, CoachingProgram $program): JsonResponse
    {
        // 1. Otorisasi (Kode yang sama, sekarang hanya ada di satu tempat)
        if ($request->user()->profile->id !== $program->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }

        $message = '';
        $updatedProgram = null;

        // 2. Logika Toggle berdasarkan status program saat ini
        if ($program->status === 'active') {
            // Aksi: PAUSE program
            $updatedProgram = $this->coachingRepository->pauseProgram($program);

            // Lakukan langkah spesifik untuk 'pause': hapus cache dashboard
            $this->dashboardRepository->forgetDashboardCache($request->user());

            $message = 'Program has been paused successfully.';
        } else if ($program->status === 'paused') {
            // Aksi: RESUME program
            // Perhatikan kita passing $request->user() sesuai kebutuhan metode ini
            $updatedProgram = $this->coachingRepository->resumeProgram($program, $request->user());

            $message = 'Program has been resumed successfully.';
        } else {
            // 3. Handle kondisi status lain (misal: 'completed', 'cancelled')
            return response()->json([
                'message' => 'Program is not in a state that can be paused or resumed.'
            ], 409); // 409 Conflict adalah status yang cocok untuk ini
        }

        // 4. Berikan Respon Sukses (Satu blok respon untuk semua kondisi sukses)
        return response()->json([
            'message' => $message,
            'data' => new CoachingProgramResource($updatedProgram)
        ], 200);
    }

    public function destroyProgram(Request $request, CoachingProgram $program): JsonResponse
    {
        // Otorisasi tetap sama
        if ($request->user()->profile->id !== $program->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }

        // Panggil metode repository yang baru
        $this->coachingRepository->deleteProgram($program);

        $this->dashboardRepository->forgetDashboardCache($request->user());

        return response()->json([
            'message' => 'Program has been deleted successfully.',
        ], 200);
    }


    public function toggleTaskStatus(Request $request, CoachingTask $task): JsonResponse
    {
        // Otorisasi mendalam: pastikan task ini benar-benar milik pengguna yang login
        if ($request->user()->profile->id !== $task->week->program->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($task->week->program->status !== 'active') {
            return response()->json(['message' => 'Tugas ini adalah bagian dari program yang sedang tidak aktif.'], 403);
        }
        // Cukup panggil satu metode di repository
        $this->taskRepository->toggleStatus($task);

        // Dapatkan status baru untuk pesan dinamis
        $newStatus = $task->fresh()->is_completed;
        $message = $newStatus ? 'Task marked as complete.' : 'Task marked as incomplete.';

        return response()->json([
            'message' => $message,
            'data' => $task->fresh()
        ]);
    }


    /**
     * [BARU] [GET /coaching/programs/{program:slug}/graduation-report]
     * Menampilkan laporan kelulusan yang sudah di-generate.
     */
    public function showGraduationReport(Request $request, CoachingProgram $program): CoachingGraduationReportResource
    {
        // 1. Otorisasi: Pastikan pengguna hanya bisa melihat laporannya sendiri.
        if ($request->user()->profile->id !== $program->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }

        // 2. Validasi Logika: Pastikan programnya sudah benar-benar selesai.
        if ($program->status !== 'completed' || is_null($program->graduation_report)) {
            abort(404, 'Graduation report for this program is not available yet.');
        }

        // 3. Kembalikan data menggunakan Resource yang telah kita buat.
        return new CoachingGraduationReportResource($program);
    }

    public function startNewThread(Request $request, CoachingProgram $program): JsonResponse
    {
        // 1. Otorisasi: Pastikan pengguna adalah pemilik program ini
        if ($request->user()->profile->id !== $program->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }

        // 2. [PENTING] Cek Status Program: Pastikan program sedang aktif
        if ($program->status !== 'active') {
            return response()->json(['message' => 'Anda tidak bisa memulai diskusi baru di program yang tidak aktif.'], 403);
        }

        // 3. Validasi input dari frontend
        $validatedData = $request->validate([
            'message' => 'required|string|max:2000',
            'title' => 'nullable|string|max:100',
        ]);

        $user = $request->user();
        $userMessage = $validatedData['message'];

        // 4. Buat "folder" thread baru. Judul di-generate otomatis jika tidak ada.
        //    Gunakan Repository untuk ini sesuai best practice.
        $thread = $this->threadRepository->createThread($program, [
            'title' => $validatedData['title'] ?? Str::limit($userMessage, 45)
        ]);

        // 5. Langsung delegasikan ke ChatCoachService untuk memproses pesan pertama
        $aiReply = $this->chatCoachService->getCoachReply($userMessage, $user, $thread);

        $thread->load('messages');

        $this->coachingRepository->forgetProgramDetailCache($program);

        // 6. Kembalikan paket lengkap: detail thread baru DAN balasan pertama dari AI
        return response()->json([
            'thread' => new \App\Http\Resources\CoachingThreadResource($thread),
            'reply' => $aiReply,
        ], 201); // 201 Created
    }

    /**
     * [TETAP] [POST /coaching/threads/{thread:slug}/messages]
     * Mengirim pesan lanjutan ke thread yang sudah ada.
     */
    public function sendMessageToThread(Request $request, CoachingThread $thread): JsonResponse
    {
        $program = $thread->program;
        $user = $request->user();
        $program = $thread->program;

        // Otorisasi
        if ($request->user()->profile->id !== $thread->program->user_profile_id) abort(403);

        if ($program->status !== 'active') {
            return response()->json(['message' => 'Tugas ini adalah bagian dari program yang sedang tidak aktif.'], 403);
        }

        // Validasi
        $validatedData = $request->validate(['message' => 'required|string|max:2000']);

        // Panggil service yang sama
        $aiReply = $this->chatCoachService->getCoachReply($validatedData['message'], $request->user(), $thread);

        // 1. Hapus cache daftar thread karena 'last_message_snippet' dan 'updated_at' berubah.
        $this->threadRepository->forgetThreadsCache($program);

        // 2. Hapus cache detail thread ini karena riwayat pesannya berubah.
        $this->coachingMessageRepository->forgetMessagesCache($thread); // Asumsi Anda membuat repo & metode ini

        // 3. Hapus cache halaman detail program induknya.
        $this->coachingRepository->forgetProgramDetailCache($program);

        // 4. Hapus cache dasbor utama karena mungkin program ini yang ditampilkan.
        $this->dashboardRepository->forgetDashboardCache($user);

        return response()->json(['reply' => $aiReply]);
    }

    /**
     * [BARU & MEMPERBAIKI BUG] [GET /coaching/threads/{thread:slug}]
     * Menampilkan detail dan riwayat pesan dari satu thread spesifik.
     */
    public function showThread(Request $request, string $threadSlug): JsonResponse
    {
        // Panggil repository untuk mendapatkan data (dari cache atau DB)
        $thread = $this->threadRepository->findWithMessagesBySlug($threadSlug);

        if (!$thread) {
            abort(404, "Thread percakapan tidak ditemukan.");
        }

        // Otorisasi: Pastikan pengguna hanya bisa melihat thread di program miliknya.
        if ($request->user()->profile->id !== $thread->program->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }

        // Gunakan resource detail yang baru untuk memformat respons
        return (new CoachingThreadDetailResource($thread))->response();
    }

    /**
     * [BARU] [PUT /coaching/threads/{thread:slug}] - Memperbarui judul thread.
     */
    public function updateThread(Request $request, CoachingThread $thread): JsonResponse
    {
        $program = $thread->program;

        // Otorisasi: Pastikan pengguna hanya bisa mengedit thread di program miliknya.
        if ($request->user()->profile->id !== $thread->program->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($program->status !== 'active') {
            return response()->json(['message' => 'Tugas ini adalah bagian dari program yang sedang tidak aktif.'], 403);
        }

        // Validasi
        $validatedData = $request->validate(['title' => 'required|string|max:100']);

        // Delegasikan ke repository
        $this->threadRepository->updateTitle($thread, $validatedData['title']);

        // Kembalikan resource yang sudah ter-update
        return (new CoachingThreadResource($thread->fresh()))->response();
    }

    /**
     * [BARU] [DELETE /coaching/threads/{thread:slug}] - Menghapus sebuah thread.
     */
    public function destroyThread(Request $request, CoachingThread $thread): JsonResponse
    {
        $program = $thread->program;


        // Otorisasi
        if ($request->user()->profile->id !== $thread->program->user_profile_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($program->status !== 'active') {
            return response()->json(['message' => 'Tugas ini adalah bagian dari program yang sedang tidak aktif.'], 403);
        }

        // Delegasikan ke repository
        $this->threadRepository->deleteThread($thread);

        // Standar respons untuk delete yang sukses adalah 200
        return response()->json([
            'message' => 'Thread has been deleted successfully.',
        ], 200);
    }
}
