<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use App\Models\UserProfile;
use App\Repositories\ChatMessageRepository;
use App\Repositories\RiskAssessmentRepository;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class ChatService
{
  private string $apiKey;
  private string $apiUrl;

  public function __construct(
    private ChatMessageRepository $chatMessageRepository,
    private RiskAssessmentRepository $riskAssessmentRepository
  ) {
    $this->apiKey = config('services.gemini.api_key');
    if (empty($this->apiKey)) {
      Log::critical('FATAL ERROR: GEMINI_API_KEY tidak diatur.');
      throw new \InvalidArgumentException('Konfigurasi layanan AI (Gemini API Key) tidak valid.');
    }
    $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite-preview-06-17:generateContent?key={$this->apiKey}";
  }

  /**
   * Metode utama untuk menangani pesan pengguna.
   */
  public function getChatResponse(string $userMessage, User $user, Conversation $conversation): array
  {
    try {
      if (empty(trim($userMessage))) {
        throw new Exception("Pesan tidak boleh kosong.");
      }

      if (!$user->exists || !$conversation->exists) {
        throw new Exception("Data pengguna atau percakapan tidak valid.");
      }

      // Tetap simpan pesan user ke database terlebih dahulu
      $this->chatMessageRepository->createMessage($conversation, 'user', $userMessage);

      // [PERBAIKAN] Alur utama diubah untuk memanggil metode-metode baru yang lebih terstruktur.
      // Logika tidak lagi membangun satu string raksasa, melainkan memanggil helper
      // untuk instruksi sistem dan riwayat chat secara terpisah.
      Log::info("Generating new Gemini reply for conversation ID: {$conversation->id} using Multi-turn approach.");

      // 1. Bangun instruksi sistem yang berisi aturan dan profil pengguna.
      $systemInstruction = $this->buildSystemInstruction($user);

      // 2. Bangun array percakapan yang berisi histori dan pertanyaan baru.
      $contents = $this->buildContentsArray($conversation, $userMessage);

      // 3. Panggil API Gemini dengan struktur baru yang lebih efisien.
      $aiReplyArray = $this->getGeminiChatCompletion($systemInstruction, $contents);

      if (!is_array($aiReplyArray) || empty($aiReplyArray)) {
        throw new Exception("Format respons AI tidak valid.");
      }

      // Tetap simpan balasan model ke database
      $this->chatMessageRepository->createMessage($conversation, 'model', json_encode($aiReplyArray));

      return $aiReplyArray;
    } catch (\Throwable $e) {
      Log::error('ChatService getChatResponse failed', [
        'error_message' => $e->getMessage(),
        'user_id' => $user->id ?? 'unknown',
        'conversation_id' => $conversation->id ?? 'unknown',
        'trace' => substr($e->getTraceAsString(), 0, 2000)
      ]);
      return $this->getFallbackResponse($e);
    }
  }

  /**
   * Provide a fallback response when the main process fails
   */
  private function getFallbackResponse(\Throwable $e): array
  {
    $errorType = get_class($e);
    $errorMessage = $e->getMessage();

    // Customize fallback based on error type
    if (str_contains($errorMessage, 'timeout') || str_contains($errorMessage, 'connection')) {
      $fallbackMessage = "Maaf, koneksi ke layanan AI sedang tidak stabil. Silakan coba lagi dalam beberapa saat.";
    } elseif (str_contains($errorMessage, 'quota') || str_contains($errorMessage, 'limit')) {
      $fallbackMessage = "Layanan AI sedang mengalami beban tinggi. Silakan coba lagi nanti.";
    } elseif (str_contains($errorMessage, 'json') || str_contains($errorMessage, 'parse')) {
      $fallbackMessage = "Terjadi kesalahan dalam memproses respons AI. Tim teknis telah diberi tahu.";
    } else {
      $fallbackMessage = "Maaf, saya mengalami sedikit kendala teknis. Silakan coba lagi atau hubungi dukungan jika masalah berlanjut.";
    }

    return [
      'reply_components' => [
        [
          'type' => 'paragraph',
          'content' => $fallbackMessage
        ],
        [
          'type' => 'paragraph',
          'content' => 'Sebagai alternatif, Anda dapat mencoba mengajukan pertanyaan yang lebih sederhana atau menghubungi dokter langsung untuk konsultasi kesehatan.'
        ]
      ]
    ];
  }

  /**
   * Membangun konteks dari profil dan 3 hasil analisis risiko TERAKHIR.
   */
  private function buildUserContext(User $user): string
  {
    try {
      if (!$user->profile) {
        return "Pengguna ini belum melengkapi profilnya.";
      }

      $profile = UserProfile::findAndCache($user->profile->id);

      if (!$profile) {
        Log::warning("Profile not found for user", ['user_id' => $user->id]);
        return "Profil pengguna tidak ditemukan.";
      }

      // [PERBAIKAN] Memperkaya data profil sesuai dengan skema database
      $age = $profile->date_of_birth ? Carbon::parse($profile->date_of_birth)->age : 'Tidak diketahui';

      // Menggabungkan nama depan dan belakang, menangani jika nama belakang kosong
      $fullName = trim("{$profile->first_name} {$profile->last_name}");

      // Menggunakan null coalescing operator (??) untuk keamanan jika data kosong
      $sex = $profile->sex ?? 'Tidak diketahui';
      $country = $profile->country_of_residence ?? 'Tidak diketahui';
      $language = $profile->language ?? 'id';

      // Menyusun konteks dengan format yang lebih rapi dan informasi yang lebih lengkap
      $context = <<<CONTEXT
      PROFIL PENGGUNA:
      - Nama Lengkap: {$fullName}
      - Usia Saat Ini: {$age} tahun
      - Jenis Kelamin: {$sex}
      - Negara Tempat Tinggal: {$country}
      - Bahasa Utama: {$language}
      CONTEXT;
      // Ambil data dari repository, yang sudah di-cache
      $assessments = $this->riskAssessmentRepository->getLatestFourAssessmentsForUser($user);

      if ($assessments && $assessments->isNotEmpty()) {
        $context .= "\n## RIWAYAT 4 ANALISIS RISIKO TERAKHIR\n";
        // [PERBAIKAN UTAMA] Loop ini sekarang akan mengekstrak lebih banyak data
        foreach ($assessments as $assessment) {
          $date = Carbon::parse($assessment->created_at)->isoFormat('D MMMM YYYY');
          $details = $assessment->result_details ?? []; // Ambil root dari JSON

          // --- Mulai bagian baru yang lebih kaya ---

          $context .= "\n### Analisis pada: {$date}\n";

          // 1. Ambil Executive Summary (Ringkasan utama)
          $executiveSummary = $details['riskSummary']['executiveSummary'] ?? null;
          if ($executiveSummary) {
            $context .= "- **Ringkasan:** {$executiveSummary}\n";
          }

          // 2. Ambil Faktor Risiko Utama (Primary Contributors)
          $primaryContributors = $details['riskSummary']['primaryContributors'] ?? [];
          if (!empty($primaryContributors)) {
            $context .= "- **Faktor Risiko Utama:**\n";
            foreach ($primaryContributors as $contributor) {
              $title = $contributor['title'] ?? 'N/A';
              $severity = $contributor['severity'] ?? 'N/A';
              $context .= "  - {$title} (Tingkat: {$severity})\n";
            }
          }

          // 3. Ambil Faktor Positif
          $positiveFactors = $details['riskSummary']['positiveFactors'] ?? [];
          if (!empty($positiveFactors)) {
            $context .= "- **Faktor Positif yang Sudah Baik:**\n";
            foreach ($positiveFactors as $factor) {
              $context .= "  - {$factor}\n";
            }
          }

          // 4. Ambil Rencana Aksi Prioritas (Paling penting untuk konteks)
          $priorityActions = $details['actionPlan']['priorityLifestyleActions'] ?? [];
          if (!empty($priorityActions)) {
            $context .= "- **Rencana Aksi yang Direkomendasikan:**\n";
            foreach ($priorityActions as $action) {
              $title = $action['title'] ?? 'N/A';
              $desc = $action['description'] ?? 'N/A';
              $context .= "  - **{$title}:** {$desc}\n";
            }
          }

          // 5. Ambil Saran Konsultasi Medis
          $consultation = $details['actionPlan']['medicalConsultation']['recommendationLevel']['description'] ?? null;
          if ($consultation) {
            $context .= "- **Saran Konsultasi Medis:** {$consultation}\n";
          }
        }
      } else {
        $context .= "\nPengguna ini belum pernah melakukan analisis risiko.";
      }

      return $context;
    } catch (\Throwable $e) {
      Log::error('Error building user context', [
        'error' => $e->getMessage(),
        'user_id' => $user->id
      ]);
      return "Terjadi kesalahan saat memuat konteks pengguna.";
    }
  }


  /**
   * [PERBAIKAN] Metode baru ini menggantikan 'buildChatHistoryContext'.
   * Tujuannya adalah membangun array 'contents' yang terstruktur, bukan string.
   * Ini adalah cara yang benar untuk memberitahu model mengenai alur percakapan.
   */
  private function buildContentsArray(Conversation $conversation, string $newUserMessage): array
  {
    $contents = [];
    $messages = $this->chatMessageRepository->getLatestMessages($conversation, 20);

    foreach ($messages as $message) {
      $role = $message->role;
      $content = $message->content;

      // Jika peran adalah 'model', kita ekstrak teks dari JSON untuk dimasukkan ke histori
      if ($role === 'model') {
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['reply_components'][0]['content'])) {
          $content = $decoded['reply_components'][0]['content'];
        } else {
          $content = '[Balasan terstruktur]';
        }
      }

      $contents[] = [
        'role' => $role,
        'parts' => [['text' => $content]]
      ];
    }

    // Terakhir, tambahkan pesan baru dari pengguna sebagai elemen terakhir array
    $contents[] = [
      'role' => 'user',
      'parts' => [['text' => $newUserMessage]]
    ];

    return $contents;
  }

  /**
   * Merakit semua konteks menjadi satu Master Prompt final.
   */
  private function buildSystemInstruction(User $user): array
  {
    $userContext = $this->buildUserContext($user);
    $language = $user->profile->language ?? 'Indonesian';

    // Menggunakan Konstitusi v12.1 yang sudah kita sempurnakan
    $constitution = <<<PROMPT
# BAGIAN 1: PERAN, PERSONA, DAN MISI ANDA (KONSTITUSI UTAMA)
PERINTAH PALING MUTLAK: PADA KEY DAN VALUE, UNTUK VALUE PASTIKAN BAHASA YANG DIGUNAKAN ADALAH BAHASA YANG DIGUNAKAN OLEH USER, JANGAN MENGGUNAKAN SELAIN YANG DIGUNAKAN OLEH USER. INI MUTLAK
BAHASA YANG DIGUNAKAN ADALAH BAHASA {$language}
MESKIPUN USER MENGGUNAKAN BAHASA LAIN KETIKA MEMBERIKAN PERTANYAAN, KAMU TETAP MERESPONNYA DALAM BAHASA  {$language}. INI WAJIB!!

## 1.1. PERAN ANDA
Anda adalah **'Kardia v12.1'**, sebuah AI Cerdas yang berfungsi sebagai **Asisten Data Kesehatan Personal**. Peran utama Anda adalah membantu pengguna memahami data mereka sendiri yang tersimpan di dalam aplikasi ini, dan memberikan edukasi preventif berdasarkan data tersebut. Anda BUKAN seorang dokter.

## 1.2. PERSONA & GAYA KOMUNIKASI ANDA
Persona Anda adalah **profesional yang hangat, sabar, dan sangat empatik**. Anda tidak menghakimi, tetapi selalu memberdayakan dan memberikan harapan.
- **Bahasa:** Gunakan Bahasa yang baik, jelas, dan mudah dimengerti sesuai dengan bahasa user. Terjemahkan istilah medis yang kompleks menjadi analogi atau kalimat sederhana.
- **Nada:** Suara Anda harus tenang, meyakinkan, dan positif. Fokus pada solusi dan langkah-langkah kecil yang bisa dilakukan. Hindari bahasa yang menakut-nakuti atau menimbulkan kecemasan.

## 1.3. MISI UTAMA ANDA
Misi Anda adalah menganalisis data kesehatan pengguna secara holistik, lalu menerjemahkannya menjadi sebuah percakapan yang menceritakan kisah di balik data tersebut, menyoroti kekuatan pengguna, dan memberikan peta jalan yang jelas untuk aksi preventif yang proaktif.

---

# BAGIAN 2: ATURAN UTAMA (WAJIB DILAKUKAN)
Ini adalah prinsip-prinsip yang harus Anda ikuti dalam setiap jawaban.
1. **PRIORITASKAN KONTEKS YANG DIBERIKAN:** Jawaban Anda HARUS berakar kuat pada data yang saya sediakan (Profil Pengguna, Hasil Analisis Risiko, Riwayat Percakapan). Jadikan ini sumber kebenaran utama Anda.
2. **LAKUKAN PERSONALISASI SECARA AKTIF:** Selalu hubungkan jawaban Anda dengan kondisi spesifik pengguna. Sebut nama mereka sesekali.
3. **BERIKAN JAWABAN YANG DAPAT DITINDAKLANJUTI (ACTIONABLE):** Jangan biarkan percakapan berakhir buntu. Selalu berikan saran langkah kecil, pertanyaan terbuka, atau ajakan untuk eksplorasi lebih lanjut.
4. **SELALU ARAHKAN KE PROFESIONAL MEDIS:** Untuk setiap saran yang menyentuh ranah medis, akhiri dengan disclaimer ringan yang mengarahkan pengguna kembali ke dokter.

---

# BAGIAN 3: BATASAN TEGAS (JANGAN PERNAH LAKUKAN INI)
Untuk keamanan dan etika, Anda dilarang keras melakukan hal-hal berikut:
1. **JANGAN MENDIAGNOSIS:** Dilarang keras menggunakan kalimat definitif seperti "Anda menderita diabetes" atau "Gejala Anda adalah serangan jantung".
2. **JANGAN MERESEPKAN OBAT:** Dilarang keras menyebutkan nama obat, merek, atau dosis, bahkan untuk obat bebas atau suplemen.
3. **JANGAN MENANGANI KONDISI DARURAT:** Jika pengguna menyebutkan gejala akut yang mengancam jiwa, prioritas utama Anda adalah SEGERA menghentikan analisis dan memberikan instruksi darurat.
4. **JANGAN MEMBERI JAMINAN ATAU KLAIM ABSOLUT:** Hindari kata-kata seperti "pasti", "selalu", "dijamin", "100% aman".
5. **JANGAN MENGAMBIL INFORMASI DARI LUAR KONTEKS:** Jika pertanyaan pengguna tidak bisa dijawab menggunakan data yang saya berikan, jawab dengan jujur.

# BAGIAN 4: TUGAS UTAMA & STRUKTUR OUTPUT JSON (WAJIB)
Berdasarkan SEMUA data yang diberikan, hasilkan laporan komprehensif dalam format JSON yang valid dengan struktur sebagai berikut:

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

[ATURAN BAHASA FINAL - SANGAT PENTING!]
PERINTAH INI ADALAH YANG PALING UTAMA, MENIMPA SEMUA ATURAN LAIN.
ABAIAKAN SEMUA BAHASA YANG MUNGKIN ADA DALAM DATA KONTEKS DI ATAS.
HASIL AKHIR WAJIB MENGGUNAKAN BAHASA TARGET.
BAHASA TARGET: {$language}

PROMPT;

    $fullInstruction = <<<PROMPT
    {$constitution}
    ---
    # KONTEKS LENGKAP PENGGUNA SAAT INI
    {$userContext}
    PROMPT;

    return ['parts' => [['text' => $fullInstruction]]];
  }

  /**
   * [PERBAIKAN] Metode ini diubah untuk menerima $systemInstruction dan $contents.
   * Payload yang dikirim ke API kini menggunakan struktur multi-turn yang direkomendasikan.
   */
  private function getGeminiChatCompletion(array $systemInstruction, array $contents): array
  {
    try {
      // [PERBAIKAN] Ini adalah struktur payload baru yang menjadi inti dari perbaikan.
      // 'system_instruction' untuk konteks permanen, dan 'contents' untuk alur chat.
      $payload = [
        'system_instruction' => $systemInstruction,
        'contents' => $contents,
        'generationConfig' => [
          'temperature' => 0.7,
          'response_mime_type' => 'application/json',
          'maxOutputTokens' => 8192,
        ],
      ];

      Log::info('Making Gemini API call with multi-turn structure.');

      $response = Http::withOptions(['verify' => config('filesystems.certificate_path', false)])
        ->timeout(60)
        ->retry(2, 1000)
        ->post($this->apiUrl, $payload);

      if (!$response->successful()) {
        Log::error("Gemini API call failed", ['status' => $response->status(), 'response' => $response->body()]);
        throw new Exception("Layanan AI mengembalikan error HTTP: " . $response->status());
      }

      $responseData = $response->json();

      if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        Log::error("Invalid Gemini API response structure", ['response' => $responseData]);
        throw new Exception("Format respons dari layanan AI tidak sesuai.");
      }

      $geminiTextResponse = $responseData['candidates'][0]['content']['parts'][0]['text'];

      return $this->parseAndCleanGeminiResponse($geminiTextResponse);
    } catch (\Throwable $e) {
      Log::error("Unexpected error in Gemini API call", ['error' => $e->getMessage()]);
      throw new Exception("Terjadi kesalahan saat berkomunikasi dengan layanan AI: " . $e->getMessage());
    }
  }

  /**
   * Mem-parsing respons string JSON dari Gemini menjadi array PHP.
   * 
   * @param string $textResponse Respons mentah dari API.
   * @return array Data yang sudah diparsing.
   * @throws Exception jika parsing gagal.
   */
  private function parseAndCleanGeminiResponse(string $textResponse): array
  {
    try {
      // Membersihkan whitespace dan potensial markdown code block
      $cleanedResponse = trim($textResponse);

      // Remove markdown code blocks if present
      if (str_starts_with($cleanedResponse, '```json')) {
        $cleanedResponse = preg_replace('/^```json\s*|\s*```$/', '', $cleanedResponse);
        $cleanedResponse = trim($cleanedResponse);
      } elseif (str_starts_with($cleanedResponse, '```')) {
        $cleanedResponse = preg_replace('/^```\s*|\s*```$/', '', $cleanedResponse);
        $cleanedResponse = trim($cleanedResponse);
      }

      // Log the cleaned response for debugging
      Log::debug('Parsing Gemini response', [
        'original_length' => strlen($textResponse),
        'cleaned_length' => strlen($cleanedResponse),
        'preview' => substr($cleanedResponse, 0, 200)
      ]);

      // Attempt to decode JSON
      $decoded = json_decode($cleanedResponse, true, 512, JSON_THROW_ON_ERROR);

      // Validate the structure
      if (!is_array($decoded) || !isset($decoded['reply_components'])) {
        Log::error("Invalid JSON structure from Gemini", [
          'decoded' => $decoded
        ]);
        throw new Exception("Struktur respons JSON tidak valid.");
      }

      // Validate reply_components
      if (!is_array($decoded['reply_components'])) {
        throw new Exception("reply_components harus berupa array.");
      }

      // Validate each component
      foreach ($decoded['reply_components'] as $index => $component) {
        if (!is_array($component) || !isset($component['type'])) {
          Log::error("Invalid component structure", [
            'index' => $index,
            'component' => $component
          ]);
          throw new Exception("Komponen respons tidak valid pada indeks {$index}.");
        }
      }

      return $decoded;
    } catch (JsonException $e) {
      Log::error("JSON parsing failed", [
        'error' => $e->getMessage(),
        'response_preview' => substr($textResponse, 0, 500),
        'json_error' => json_last_error_msg()
      ]);
      throw new Exception("Format respons dari layanan AI bukan JSON yang valid: " . $e->getMessage());
    } catch (\Throwable $e) {
      Log::error("Unexpected error parsing Gemini response", [
        'error' => $e->getMessage(),
        'response_preview' => substr($textResponse, 0, 500)
      ]);
      throw new Exception("Gagal memproses respons dari layanan AI.");
    }
  }
}
