<?php

namespace App\Services;

use App\Models\CoachingProgram;
use App\Models\CoachingThread;
use App\Models\User;
use App\Repositories\CoachingMessageRepository; // Akan kita buat/gunakan
use App\Repositories\RiskAssessmentRepository;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class ChatCoachService
{
  private string $apiKey;
  private string $apiUrl;

  // Inject semua repository yang dibutuhkan
  public function __construct(
    private CoachingMessageRepository $messageRepository,
    private RiskAssessmentRepository $assessmentRepository
  ) {
    $this->apiKey = config('services.gemini.api_key');
    if (empty($this->apiKey)) {
      throw new \InvalidArgumentException('Konfigurasi layanan AI tidak valid.');
    }
    $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite-preview-06-17:generateContent?key={$this->apiKey}";
  }

  public function getCoachReply(string $userMessage, User $user, CoachingThread $thread): array
  {
    try {
      // 1. Simpan pesan pengguna (tidak berubah)
      $this->messageRepository->createMessage($thread, 'user', $userMessage);

      // 2. Bangun instruksi sistem (konstitusi, profil, & konteks program)
      $systemInstruction = $this->buildSystemInstruction($user, $thread->program);

      // 3. Bangun array riwayat percakapan
      $contents = $this->buildContentsArray($thread, $userMessage);

      // 4. Panggil Gemini dengan struktur payload yang benar
      $aiReplyArray = $this->getGeminiChatCompletion($systemInstruction, $contents);

      // 5. Simpan balasan AI (tidak berubah)
      // [PERBAIKAN] Mengganti role dari 'ai_coach' menjadi 'model' agar konsisten dengan API
      $this->messageRepository->createMessage($thread, 'model', json_encode($aiReplyArray));

      return $aiReplyArray;
    } catch (\Throwable $e) {
      Log::error('ChatCoachService failed', [
        'error' => $e->getMessage(),
        'user_id' => $user->id,
        'thread_id' => $thread->id,
        'trace' => substr($e->getTraceAsString(), 0, 2000)
      ]);
      // Disarankan memiliki fallback response yang lebih spesifik untuk coach
      throw new Exception("Maaf, terjadi kendala pada Asisten Pelatih AI kami.");
    }
  }

  // ===================================================================
  // KUMPULAN METODE HELPER
  // ===================================================================

  private function buildUserContext(User $user): string
  {
    if (!$user->profile) return "";
    $profile = $user->profile; // Asumsi profil sudah di-load atau akan di-load oleh relasi
    $age = Carbon::parse($profile->date_of_birth)->age;
    $context = "PROFIL PENGGUNA:\n- Nama: {$profile->first_name}\n- Usia: {$age} tahun\n- Jenis Kelamin: {$profile->sex}\n";

    $assessments = $this->assessmentRepository->getLatestFourAssessmentsForUser($user);
    if ($assessments->isNotEmpty()) {
      $context .= "\nRIWAYAT 3 ANALISIS RISIKO TERAKHIR:\n";
      foreach ($assessments as $assessment) {
        $date = Carbon::parse($assessment->created_at)->isoFormat('D MMM YY');
        $riskCategory = $assessment->result_details['riskSummary']['riskCategory']['title'] ?? 'N/A';
        $context .= "- {$date}: {$assessment->final_risk_percentage}% ({$riskCategory})\n";
      }
    }
    return $context;
  }

  /**
   * [SOLUSI FINAL] Membangun konteks program dengan logika yang diperbaiki dan lebih aman.
   * - Memperbaiki urutan variabel di diffInDays.
   * - Menangani kasus program belum mulai dan sudah selesai dengan lebih eksplisit.
   * - Menambahkan log jika terjadi anomali data (minggu tidak ditemukan).
   */
  private function buildProgramContext(CoachingProgram $program): string
  {
    // --- Bagian 1: Konteks Keseluruhan Program (Tidak berubah) ---
    $totalWeeks = $program->weeks()->count();
    $endDateFormatted = Carbon::parse($program->end_date)->isoFormat('D MMMM YYYY');
    $startDateFormatted = Carbon::parse($program->start_date)->isoFormat('D MMMM YYYY');

    $context = <<<PROGRAM
KONTEKS PROGRAM COACHING SAAT INI:
- Nama Program: {$program->title}
- Tujuan Utama Program: {$program->description}
- Tingkat Kesulitan: {$program->difficulty}
- Status Program: {$program->status}
- Tanggal Mulai Program: {$startDateFormatted}
- Tanggal Selesai Program: {$endDateFormatted}

PROGRAM;

    // --- Bagian 2: Logika Cerdas yang Diperbaiki ---
    $programStartDate = Carbon::parse($program->start_date)->startOfDay();
    $today = Carbon::now()->startOfDay();

    // [GUARD CLAUSE 1] Jika program belum dimulai, hentikan proses dan berikan info.
    if ($today->isBefore($programStartDate)) {
      $context .= "INFO PENTING: Program ini baru akan dimulai pada {$startDateFormatted}. Berikan semangat pada pengguna untuk bersiap-siap.";
      return rtrim($context);
    }

    // [FIX UTAMA] Memperbaiki urutan variabel untuk mendapatkan hasil positif.
    // Format yang benar: Tanggal_Akhir->diffInDays(Tanggal_Awal)
    $daysPassed = $today->diffInDays($programStartDate); // Argumen kedua (false) tidak wajib jika hanya butuh angka absolut
    $currentWeekNumber = floor($daysPassed / 7) + 1;

    // [GUARD CLAUSE 2] Jika minggu yang dihitung sudah melebihi total minggu, program selesai.
    if ($totalWeeks > 0 && $currentWeekNumber > $totalWeeks) {
      $context .= "INFO PENTING: Program coaching ini telah selesai. Pengguna sedang dalam tahap pasca-program. Berikan ucapan selamat dan motivasi untuk menjaga kebiasaan baik.";
      return rtrim($context);
    }

    // --- Bagian 3: Bangun String Konteks jika Program Sedang Aktif ---
    $currentWeek = $program->weeks()->with('tasks')->where('week_number', $currentWeekNumber)->first();

    if ($currentWeek) {
      $context .= "\nKONTEKS MINGGU INI (Minggu {$currentWeek->week_number} dari {$totalWeeks}):\n";
      $context .= "- Fokus Minggu Ini: {$currentWeek->title}\n";
      $context .= "- Deskripsi Fokus: {$currentWeek->description}\n\n";
      $context .= "JADWAL LENGKAP MINGGU INI:\n";

      foreach ($currentWeek->tasks->sortBy('task_date') as $task) {
        $taskDateCarbon = Carbon::parse($task->task_date);
        $dayName = $taskDateCarbon->translatedFormat('l, d M');
        $marker = $taskDateCarbon->isToday() ? " (HARI INI)" : "";
        $status = $task->is_completed ? " [✓ Selesai]" : " [□ Belum]";
        $type = ($task->task_type === 'main_mission') ? "Misi Utama" : "Tantangan Bonus";

        $context .= "- {$dayName}{$marker} ({$type}): {$task->title}{$status}\n";
        $context .= "  - Deskripsi Tugas: {$task->description}\n";
      }
      $context .= "\n";

      $nextWeek = $program->weeks()->where('week_number', $currentWeekNumber + 1)->first();
      if ($nextWeek) {
        $context .= "FOKUS MINGGU DEPAN (Minggu ke-{$nextWeek->week_number}): {$nextWeek->title}\n";
      } else {
        $context .= "INFO: Ini adalah minggu terakhir dari program Anda. Mari selesaikan dengan baik!\n";
      }
    } else {
      // [LOGGING PENTING] Jika minggu tidak ditemukan padahal seharusnya ada.
      Log::warning('Data minggu tidak ditemukan untuk program aktif.', [
        'program_id' => $program->id,
        'calculated_week_number' => $currentWeekNumber,
        'total_weeks_in_db' => $totalWeeks
      ]);
      $context .= "INFO PENTING: Program sedang berjalan, tetapi data untuk minggu ke-{$currentWeekNumber} tidak ditemukan di sistem. Beritahu pengguna untuk tetap fokus pada kebiasaan umum dan tanyakan apa yang bisa dibantu.";
    }

    return rtrim($context);
  }

  private function buildSystemInstruction(User $user, CoachingProgram $program): array
  {
    $userContext = $this->buildUserContext($user); // Helper untuk profil user
    $programContext = $this->buildProgramContext($program); // Helper untuk info program coaching
    $language = $user->profile->language ?? 'id';

    $today = now()->translatedFormat('l, d MMMM YYYY');
    $language = $user->profile->language;
    $fullInstruction = <<<PROMPT
# BAGIAN 1: PERAN, PERSONA, DAN MISI ANDA (KONSTITUSI COACHING)
PERINTAH PALING MUTLAK: PADA KEY DAN VALUE, UNTUK VALUE PASTIKAN BAHASA YANG DIGUNAKAN ADALAH BAHASA YANG DIGUNAKAN OLEH USER, JANGAN MENGGUNAKAN SELAIN YANG DIGUNAKAN OLEH USER. INI MUTLAK
BAHASA YANG DIGUNAKAN ADALAH BAHASA {$language}
MESKIPUN USER MENGGUNAKAN BAHASA LAIN KETIKA MEMBERIKAN PERTANYAAN, KAMU TETAP MERESPONNYA DALAM BAHASA  {$language}. INI WAJIB!!

## 1.1. PERAN ANDA
Anda adalah **'Kardia Coach'**, seorang pelatih kesehatan AI. Peran utama Anda adalah **memandu, mendukung, dan menjaga akuntabilitas** pengguna selama mereka menjalani program coaching yang telah dirancang untuk menurunkan risiko CVD mereka. Anda adalah partner mereka dalam perjalanan ini.

## 1.2. PERSONA & GAYA KOMUNIKASI ANDA
Persona Anda adalah **profesional yang hangat, sabar, dan sangat empatik**. Anda tidak menghakimi, tetapi selalu memberdayakan dan memberikan harapan.
- **Bahasa:** Gunakan Bahasa Indonesia atau Inggris sesuai preferensi pengguna. Gunakan kalimat yang positif dan memotivasi.
- **Nada:** Suara Anda harus tenang dan meyakinkan. Fokus pada proses dan kemajuan, bukan hanya pada hasil akhir.
- **Konteks Program:** Setiap jawaban Anda harus selalu terasa seperti bagian dari program yang sedang berjalan. Gunakan frasa seperti *"Sesuai dengan fokus kita minggu ini..."* atau *"Ini adalah langkah yang bagus untuk mencapai target program kita..."* untuk selalu mengingatkan pengguna pada tujuan besar mereka.

## 1.3. MISI UTAMA ANDA
Misi Anda adalah **menjaga momentum dan motivasi** pengguna. Bantu mereka mengatasi rintangan, rayakan kemenangan sekecil apa pun, dan selalu ingatkan mereka pada tujuan akhir dari program coaching yang sedang mereka jalani.

---

# BAGIAN 2: ATURAN UTAMA (WAJIB DILAKUKAN)
Ini adalah prinsip-prinsip coaching Anda.

1.  **PRIORITASKAN KONTEKS PROGRAM:** Jawaban Anda HARUS berakar kuat pada data `KONTEKS PROGRAM COACHING` yang saya berikan (nama program, fokus minggu ini, misi harian) serta riwayat chat di thread ini.
2.  **RAYAKAN PROGRES, SEKECIL APAPUN:** Jika pengguna melaporkan keberhasilan (misal: "Saya berhasil jalan kaki 10 menit hari ini"), berikan respons yang sangat positif dan apresiatif. Contoh: *"Luar biasa, Budi! 10 menit itu adalah kemenangan besar. Saya bangga dengan usaha Anda hari ini!"*
3.  **NORMALISASI KEMUNDURAN (SETBACKS):** Jika pengguna melaporkan "kegagalan" (misal: "saya merokok lagi"), respons pertama Anda harus **validasi emosi, bukan penghakiman**. Berikan dukungan dan fokuskan kembali pada hari esok. Contoh: *"Terima kasih sudah jujur pada saya. 'Kecolongan' adalah bagian yang sangat wajar dari proses. Jangan berkecil hati. Yang penting adalah kita kembali ke jalur besok. Anda sudah sangat hebat karena tetap berkomitmen pada program ini."*
4.  **JAWABAN ACTIONABLE:** Selalu akhiri dengan langkah selanjutnya yang jelas atau pertanyaan terbuka untuk menjaga agar percakapan tetap berjalan dan produktif.
5.  **SELALU ARAHKAN KE PROFESIONAL MEDIS:** Untuk pertanyaan medis di luar lingkup program, selalu arahkan pengguna untuk berkonsultasi dengan dokter.

---

# BAGIAN 3: BATASAN TEGAS (JANGAN PERNAH LAKUKAN INI)
*(Bagian ini tetap sama persis seperti sebelumnya karena sangat penting untuk keamanan)*
1.  **JANGAN MENDIAGNOSIS.**
2.  **JANGAN MERESEPKAN OBAT.**
3.  **JANGAN MENANGANI KONDISI DARURAT** (segera arahkan ke 112/UGD).
4.  **JANGAN MEMBERI JAMINAN ATAU KLAIM ABSOLUT.**
5.  **JANGAN MENGAMBIL INFORMASI DARI LUAR KONTEKS YANG DIBERIKAN.**

---

# ATURAN TAMBAHAN TENTANG WAKTU
- Hari ini adalah: {$today}.
- Jika pengguna bertanya tentang "hari ini", "besok", "lusa", atau hari lain, lihat dan sebutkan misi dari "JADWAL LENGKAP MINGGU INI".
- Jika pengguna bertanya tentang "minggu depan", jawab berdasarkan informasi dari "FOKUS MINGGU DEPAN".
- Jika tidak ada jadwal spesifik untuk hari yang ditanyakan, katakan dengan jujur. Jangan mengarang misi baru.

---

# BAGIAN 4: TUGAS UTAMA & STRUKTUR OUTPUT JSON (WAJIB)
Berdasarkan SEMUA data yang diberikan, hasilkan laporan komprehensif dalam format JSON yang valid.
Struktur utamanya adalah objek dengan satu kunci `"reply_components"`, yang berisi sebuah **array dari objek-objek komponen**. Anda bisa menggabungkan beberapa komponen untuk membuat balasan yang kaya.

Setiap objek komponen di dalam array "reply_components" HARUS memiliki struktur berikut:

1.  **`"type"`**: WAJIB diisi dengan **SALAH SATU** dari string berikut:
    * `"header"`: Untuk judul atau sub-judul.
    * `"paragraph"`: Untuk teks biasa atau paragraf penjelasan.
    * `"list"`: Untuk daftar poin-poin (bullet points atau numbered list).
    * `"quote"`: Untuk menyorot teks penting atau kutipan.

2.  **`"content"`**: WAJIB diisi dengan teks (string). Kunci ini digunakan untuk `type` `"header"`, `"paragraph"`, dan `"quote"`. **JANGAN** gunakan kunci ini untuk `type` `"list"`.

3.  **`"items"`**: WAJIB diisi dengan sebuah **array yang berisi beberapa string**. Setiap string adalah satu poin dalam daftar. Kunci ini **HANYA** digunakan untuk `type` `"list"`.

---
## CONTOH-CONTOH KOMPONEN SPESIFIK

Berikut adalah contoh untuk setiap `type` yang valid:

**1. Contoh Header:**
{ "type": "header", "content": "Ini Contoh Judul" }

**2. Contoh Paragraph:**
{ "type": "paragraph", "content": "Ini adalah contoh isi paragraf yang menjelaskan sesuatu secara lebih rinci." }

**3. Contoh List (menggunakan `items`):**
{ "type": "list", "items": ["Poin daftar pertama.", "Poin daftar kedua.", "Poin daftar ketiga."] }

**4. Contoh Quote:**
{ "type": "quote", "content": "Ini adalah contoh kutipan yang ingin ditekankan atau sebuah pengingat penting." }

---
## CONTOH OUTPUT LENGKAP (GABUNGAN BEBERAPA KOMPONEN)

Tugas Anda adalah membuat array yang berisi kombinasi dari komponen-komponen di atas sesuai dengan balasan yang paling pas.

{
  "reply_components": [
    {
      "type": "header",
      "content": "Rencana Hebat untuk Hari Ini!"
    },
    {
      "type": "paragraph",
      "content": "Terima kasih sudah berbagi. Berdasarkan fokus kita minggu ini, mari kita coba beberapa langkah praktis berikut:"
    },
    {
      "type": "list",
      "items": [
        "Selesaikan misi utama hari ini: Jalan kaki 30 menit.",
        "Coba tantangan bonus: Ganti camilan manis dengan buah.",
        "Jangan lupa untuk mencatat kemajuan Anda."
      ]
    },
    {
        "type": "paragraph",
        "content": "Bagaimana menurut Anda? Apakah rencana ini terasa pas untuk dijalankan?"
    }
  ]
}

---
# KONTEKS PROGRAM COACHING SAAT INI
{$programContext}
---
# KONTEKS PENGGUNA YANG MENJALANI PROGRAM
{$userContext}


[ATURAN BAHASA FINAL - SANGAT PENTING!]
PERINTAH INI ADALAH YANG PALING UTAMA, MENIMPA SEMUA ATURAN LAIN.
ABAIAKAN SEMUA BAHASA YANG MUNGKIN ADA DALAM DATA KONTEKS DI ATAS.
HASIL AKHIR WAJIB MENGGUNAKAN BAHASA TARGET.
BAHASA TARGET: {$language}
PROMPT;

    return ['parts' => [['text' => $fullInstruction]]];
  }


  private function buildContentsArray(CoachingThread $thread, string $newUserMessage): array
  {
    $contents = [];
    $messages = $this->messageRepository->getLatestMessages($thread, 20);

    foreach ($messages as $message) {
      // [PERBAIKAN] Menyesuaikan 'role' agar sesuai dengan standar API ('user' atau 'model')
      $role = ($message->role === 'user') ? 'user' : 'model';

      // Logika untuk mengekstrak teks dari balasan AI yang disimpan sebagai JSON
      $content = $message->content;
      if ($role === 'model') {
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['reply_components'][0]['content'])) {
          $content = $decoded['reply_components'][0]['content'];
        } else {
          $content = '[Pesan dari Coach]';
        }
      }

      $contents[] = [
        'role' => $role,
        'parts' => [['text' => $content]]
      ];
    }

    // Menambahkan pesan baru dari pengguna di akhir array
    $contents[] = [
      'role' => 'user',
      'parts' => [['text' => $newUserMessage]]
    ];

    return $contents;
  }



  private function getGeminiChatCompletion(array $systemInstruction, array $contents): array
  {
    try {
      $payload = [
        'system_instruction' => $systemInstruction,
        'contents' => $contents,
        'generationConfig' => [
          'temperature' => 0.7,
          'response_mime_type' => 'application/json',
          'maxOutputTokens' => 8192,
        ],
      ];

      Log::info('Making Gemini API call (Coach) with multi-turn structure.');

      $response = Http::withOptions(['verify' => config('filesystems.certificate_path', false)])
        ->timeout(60)
        ->retry(2, 1000)
        ->post($this->apiUrl, $payload);

      if (!$response->successful()) {
        Log::error("Gemini API call (Coach) failed", ['status' => $response->status(), 'response' => $response->body()]);
        throw new Exception("Layanan AI Coach mengembalikan error: " . $response->status());
      }

      $responseData = $response->json();
      if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception("Format respons dari layanan AI Coach tidak sesuai.");
      }
      $geminiTextResponse = $responseData['candidates'][0]['content']['parts'][0]['text'];

      return $this->parseAndCleanGeminiResponse($geminiTextResponse);
    } catch (ConnectionException $e) {
      throw new Exception("Gagal terhubung ke layanan AI Coach.");
    } catch (\Throwable $e) {
      throw new Exception("Terjadi kesalahan pada layanan AI Coach: " . $e->getMessage());
    }
  }

  /**
   * Mem-parsing respons string JSON dari Gemini menjadi array PHP.
   * Versi yang diperbaiki untuk menangani berbagai format respons yang mungkin.
   *
   * @param string $textResponse Respons mentah dari API.
   * @return array Data yang sudah diparsing.
   * @throws Exception jika parsing gagal.
   */
  private function parseAndCleanGeminiResponse(string $textResponse): array
  {
    try {
      // Step 1: Membersihkan whitespace
      $cleanedResponse = trim($textResponse);

      // Step 2: Menghapus markdown code block jika ada
      if (str_starts_with($cleanedResponse, '```json')) {
        $cleanedResponse = preg_replace('/^```json\s*|\s*```$/m', '', $cleanedResponse);
        $cleanedResponse = trim($cleanedResponse);
      } elseif (str_starts_with($cleanedResponse, '```')) {
        $cleanedResponse = preg_replace('/^```\s*|\s*```$/m', '', $cleanedResponse);
        $cleanedResponse = trim($cleanedResponse);
      }

      // Step 3: Decode JSON pertama kali
      $decodedData = json_decode($cleanedResponse, true, 512, JSON_THROW_ON_ERROR);

      // Step 4: Cek apakah ada wrapper yang tidak diinginkan
      // Kita mencari struktur yang benar: array dengan key "reply_components"
      if (isset($decodedData['reply']['reply_components'])) {
        // Ada wrapper "reply", ambil isinya saja
        Log::info("Mendeteksi wrapper 'reply', melakukan ekstraksi otomatis.");
        return $decodedData['reply'];
      } elseif (isset($decodedData['reply_components'])) {
        // Struktur sudah benar
        return $decodedData;
      } elseif (isset($decodedData['response']['reply_components'])) {
        // Ada wrapper "response"
        Log::info("Mendeteksi wrapper 'response', melakukan ekstraksi otomatis.");
        return $decodedData['response'];
      } elseif (isset($decodedData['data']['reply_components'])) {
        // Ada wrapper "data"
        Log::info("Mendeteksi wrapper 'data', melakukan ekstraksi otomatis.");
        return $decodedData['data'];
      } else {
        // Struktur tidak dikenali, lempar exception dengan info debug
        Log::error("Struktur JSON tidak sesuai ekspektasi.", [
          'keys_found' => array_keys($decodedData),
          'sample_data' => json_encode($decodedData, JSON_PRETTY_PRINT)
        ]);
        throw new Exception("Struktur respons dari layanan AI tidak mengandung 'reply_components'.");
      }
    } catch (JsonException $e) {
      Log::error("Gagal mem-parsing JSON dari respons Gemini.", [
        'error' => $e->getMessage(),
        'raw_response' => substr($textResponse, 0, 500) // Log 500 karakter pertama untuk debugging
      ]);
      throw new Exception("Format respons dari layanan AI tidak valid (JSON error).");
    } catch (\Throwable $e) {
      Log::error("Error unexpected dalam parsing respons Gemini.", [
        'error' => $e->getMessage(),
        'class' => get_class($e)
      ]);
      throw new Exception("Terjadi kesalahan dalam memproses respons dari layanan AI.");
    }
  }
}
