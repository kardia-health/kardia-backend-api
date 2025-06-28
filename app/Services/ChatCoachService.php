<?php

namespace App\Services;

use App\Models\CoachingThread;
use App\Models\User;
use App\Repositories\CoachingMessageRepository; // Akan kita buat/gunakan
use App\Repositories\RiskAssessmentRepository;
use Carbon\Carbon;
use Exception;
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

  /**
   * Metode utama untuk menangani pesan dalam konteks coaching.
   */
  public function getCoachReply(string $userMessage, User $user, CoachingThread $thread): array
  {
    try {
      // 1. Simpan pesan pengguna ke thread yang benar via Repository
      $this->messageRepository->createMessage($thread, 'user', $userMessage);

      // 2. Kumpulkan semua konteks relevan
      $userContext = $this->buildUserContext($user);
      $chatHistoryContext = $this->buildChatHistoryContext($thread);
      $programContext = $this->buildProgramContext($thread->program);

      // 3. Rakit prompt lengkap
      $prompt = $this->buildPrompt($user, $userMessage, $userContext, $programContext, $chatHistoryContext);

      // 4. Panggil Gemini
      $aiReplyArray = $this->getGeminiChatCompletion($prompt);

      // 5. Simpan balasan AI ke thread yang sama via Repository
      $this->messageRepository->createMessage($thread, 'ai_coach', json_encode($aiReplyArray));

      return $aiReplyArray;
    } catch (\Throwable $e) {
      Log::error('ChatCoachService failed', ['error' => $e->getMessage()]);
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

    $assessments = $this->assessmentRepository->getLatestThreeForUser($user);
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

  private function buildProgramContext(\App\Models\CoachingProgram $program): string
  {
    // Mulai dengan informasi dasar program
    $context = "KONTEKS PROGRAM COACHING SAAT INI:\n";
    $context .= "- Nama Program: {$program->title}\n";
    $context .= "- Status Program: {$program->status}\n";

    // --- Logika Cerdas untuk Menentukan Minggu dan Tugas Saat Ini ---

    // 1. Hitung minggu ke berapa saat ini secara dinamis
    $programStartDate = \Carbon\Carbon::parse($program->start_date)->startOfDay();
    $today = \Carbon\Carbon::now()->startOfDay();
    $daysPassed = $today->diffInDays($programStartDate, false);
    $currentWeekNumber = floor($daysPassed / 7) + 1;

    // 2. Ambil data untuk minggu saat ini dari database
    $currentWeek = $program->weeks()->with('tasks')->where('week_number', $currentWeekNumber)->first();

    // 3. Bangun string konteks jika kita berada dalam periode minggu yang valid
    if ($currentWeek) {
      $context .= "- Sedang di Minggu ke-{$currentWeek->week_number}: {$currentWeek->title}\n\n";
      $context .= "JADWAL LENGKAP MINGGU INI:\n";

      $todayDayOfWeek = now()->dayOfWeekIso; // 1 untuk Senin, 7 untuk Minggu

      // Loop melalui semua tugas di minggu ini untuk membuat agenda
      foreach ($currentWeek->tasks->sortBy('task_date') as $task) {
        // Gunakan Carbon untuk memformat tanggal tugas menjadi nama hari
        $taskDateCarbon = \Carbon\Carbon::parse($task->task_date);

        $dayName = $taskDateCarbon->translatedFormat('l, d M'); // "Rabu, 26 Jun"
        $marker = ($taskDateCarbon->isToday()) ? " (HARI INI)" : "";
        $status = $task->is_completed ? " [✓ Selesai]" : "";
        $type = ($task->task_type === 'main_mission') ? "Misi Utama" : "Tantangan Bonus";

        $context .= "- {$dayName}{$marker} ({$type}): {$task->title}{$status}\n";
      }
      $context .= "\n";

      // 4. Berikan AI "bocoran" tentang fokus minggu depan
      $nextWeek = $program->weeks()->where('week_number', $currentWeekNumber + 1)->first();
      if ($nextWeek) {
        $context .= "FOKUS MINGGU DEPAN (Minggu ke-{$nextWeek->week_number}): {$nextWeek->title}\n";
      } else {
        $context .= "INFO: Ini adalah minggu terakhir dari program Anda.\n";
      }
    } else {
      $context .= "INFO: Program saat ini tidak dalam periode minggu aktif, atau telah selesai.\n";
    }

    return rtrim($context);
  }

  private function buildChatHistoryContext(CoachingThread $thread): string
  {
    $messages = $this->messageRepository->getLatestMessages($thread, 10); // Ambil 6 pesan terakhir
    if ($messages->isEmpty()) return "Ini adalah awal dari diskusi di thread ini.";

    $history = "RIWAYAT PERCAKAPAN DI THREAD INI:\n";
    // ... (logika formatting chat history Anda yang sudah bagus) ...
    return $history;
  }

  private function buildPrompt(User $user, string $userMessage, string $userContext, string $programContext, string $chatHistoryContext): string
  {
    $language = $user->profile->language;
    $constitution = <<<PROMPT
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
- Hari ini adalah: {now()->translatedFormat('l, d MMMM YYYY')}.
- Jika pengguna bertanya tentang "hari ini", "besok", "lusa", atau hari lain, lihat dan sebutkan misi dari "JADWAL LENGKAP MINGGU INI".
- Jika pengguna bertanya tentang "minggu depan", jawab berdasarkan informasi dari "FOKUS MINGGU DEPAN".
- Jika tidak ada jadwal spesifik untuk hari yang ditanyakan, katakan dengan jujur. Jangan mengarang misi baru.

---

# BAGIAN 4: TUGAS UTAMA & STRUKTUR OUTPUT JSON (SANGATT WAJIB)
*(Struktur output ini tetap sama karena sangat fleksibel untuk frontend)*
Berdasarkan SEMUA data yang diberikan, hasilkan balasan dalam format JSON yang valid dengan struktur `reply_components` berikut:
```json
{
  "reply_components": [
    {
      "type": "string (paragraph/header/list/quote)",
      "content": "string, jika tipenya paragraph/header/quote",
      "items": [
        "string",
        "string"
      ]
    }
  ]
}

---

PROMPT;

    return <<<PROMPT
{$constitution}
---
# KONTEKS LENGKAP PENGGUNA SAAT INI
{$userContext} {$programContext}
---
# RIWAYAT PERCAKAPAN TERAKHIR
{$chatHistoryContext}
---
# PERTANYAAN BARU DARI PENGGUNA
"{$userMessage}"
---
# JAWABAN ANDA:
PROMPT;
  }


  private function getGeminiChatCompletion(string $prompt): array
  {
    $certificatePath = config('filesystems.certificate_path');
    // Logika ini tetap sama
    $response = Http::withOptions([
      'verify' => $certificatePath
    ]) // Ganti 'false' dengan path sertifikat di produksi
      ->timeout(300)
      ->post($this->apiUrl, [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
          'temperature' => 0.7,
          'response_mime_type' => 'application/json',
        ],
      ]);

    if ($response->successful() && isset($response->json()['candidates'][0]['content']['parts'][0]['text'])) {
      $geminiTextResponse = $response->json()['candidates'][0]['content']['parts'][0]['text'];
      return $this->parseAndCleanGeminiResponse($geminiTextResponse);
    }

    Log::error("Gemini API call failed.", ['response' => $response->body()]);
    throw new Exception("Gagal mendapatkan balasan dari layanan AI.");
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
