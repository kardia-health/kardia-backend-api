<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use App\Models\UserProfile;
use App\Repositories\ChatMessageRepository;
use App\Repositories\RiskAssessmentRepository;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
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
   * Metode utama untuk menangani pesan pengguna dalam sebuah percakapan SPESIFIK.
   * @param string $userMessage Pesan baru dari pengguna.
   * @param User $user Pengguna yang sedang terotentikasi.
   * @param Conversation $conversation Sesi percakapan yang sedang aktif.
   * @return array Balasan AI yang sudah terstruktur.
   * @throws Exception
   */
  public function getChatResponse(string $userMessage, User $user, Conversation $conversation): array
  {
    try {
      // Validasi input parameters
      if (empty(trim($userMessage))) {
        throw new Exception("Pesan tidak boleh kosong.");
      }

      if (!$user->exists || !$conversation->exists) {
        throw new Exception("Data pengguna atau percakapan tidak valid.");
      }

      // Delegasikan penyimpanan ke repository
      $userMessageRecord = $this->chatMessageRepository->createMessage($conversation, 'user', $userMessage);

      if (!$userMessageRecord) {
        Log::error("Failed to save user message", [
          'user_id' => $user->id,
          'conversation_id' => $conversation->id,
          'message_length' => strlen($userMessage)
        ]);
        // Ganti line 63 di ChatService dengan kode ini:
        try {
          Log::info('Attempting to save user message', [
            'conversation_id' => $conversation->id,
            'message' => $userMessage
          ]);

          $userMessage = $conversation->chatMessages()->create([
            'role' => 'user',
            'content' => $userMessage
          ]);

          Log::info('User message saved successfully', [
            'message_id' => $userMessage->id
          ]);
        } catch (\Exception $e) {
          Log::error('DETAILED ERROR saving user message', [
            'error' => $e->getMessage(),
            'conversation_exists' => $conversation->exists,
            'conversation_id' => $conversation->id,
            'fillable_fields' => (new \App\Models\ChatMessage())->getFillable(),
            'sql_state' => $e->getCode()
          ]);
          throw new \Exception('Gagal menyimpan pesan pengguna: ' . $e->getMessage());
        }
      }

      // [BARU] Buat kunci cache unik berdasarkan pesan pengguna
      $cacheKey = "gemini_reply:conv:{$conversation->id}:" . md5($userMessage);

      // [BARU] Gunakan Cache::remember untuk proses yang mahal
      $aiReplyArray = Cache::remember($cacheKey, now()->addHours(1), function () use ($userMessage, $user, $conversation) {
        // --- Blok ini hanya berjalan jika jawaban tidak ada di cache ---
        Log::info("CACHE MISS: Generating new Gemini reply for conversation ID: {$conversation->id}");

        try {
          $userContext = $this->buildUserContext($user);
          $chatHistoryContext = $this->buildChatHistoryContext($conversation);
          $prompt = $this->buildPrompt($user, $userMessage, $userContext, $chatHistoryContext);

          // Validasi prompt length (Gemini has limits)
          if (strlen($prompt) > 30000) { // Adjust limit as needed
            Log::warning("Prompt too long, truncating", [
              'original_length' => strlen($prompt),
              'user_id' => $user->id,
              'conversation_id' => $conversation->id
            ]);
            // Truncate or optimize prompt here if needed
          }

          // Panggilan mahal ke Gemini
          return $this->getGeminiChatCompletion($prompt);
        } catch (\Throwable $e) {
          // Log detailed error for cache callback
          Log::error('Error in cache callback during Gemini API call', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'user_id' => $user->id,
            'conversation_id' => $conversation->id
          ]);

          // Re-throw to be caught by outer try-catch
          throw $e;
        }
      });

      // Validasi response sebelum menyimpan
      if (!is_array($aiReplyArray) || empty($aiReplyArray)) {
        Log::error("Invalid AI response structure", [
          'response' => $aiReplyArray,
          'type' => gettype($aiReplyArray)
        ]);
        throw new Exception("Format respons AI tidak valid.");
      }

      // Delegasikan penyimpanan balasan AI ke repository
      $aiMessageRecord = $this->chatMessageRepository->createMessage($conversation, 'model', json_encode($aiReplyArray));

      if (!$aiMessageRecord) {
        Log::error("Failed to save AI response", [
          'user_id' => $user->id,
          'conversation_id' => $conversation->id,
          'response_size' => strlen(json_encode($aiReplyArray))
        ]);
        // Don't throw here since we have the response, just log the error
      }

      return $aiReplyArray;
    } catch (\Throwable $e) {
      // Enhanced error logging
      Log::error('ChatService getChatResponse failed', [
        'error_message' => $e->getMessage(),
        'error_line' => $e->getLine(),
        'error_file' => $e->getFile(),
        'user_id' => $user->id ?? 'unknown',
        'conversation_id' => $conversation->id ?? 'unknown',
        'message_preview' => substr($userMessage ?? '', 0, 100),
        'trace' => $e->getTraceAsString()
      ]);



      // Return a fallback response instead of throwing
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

      $age = $profile->date_of_birth ? Carbon::parse($profile->date_of_birth)->age : 'Tidak diketahui';
      $context = "PROFIL PENGGUNA:\n- Nama: {$profile->first_name}\n- Usia Saat Ini: {$age} tahun\n- Jenis Kelamin: {$profile->sex}\n";

      // Ambil data dari repository, yang sudah di-cache
      $assessments = $this->riskAssessmentRepository->getLatestThreeForUser($user);

      if ($assessments && $assessments->isNotEmpty()) {
        $context .= "\n## RIWAYAT 3 ANALISIS RISIKO TERAKHIR\n";
        foreach ($assessments as $assessment) {
          $date = Carbon::parse($assessment->created_at)->isoFormat('D MMM YYYY');
          $riskCategory = $assessment->result_details['riskSummary']['riskCategory']['title'] ?? 'N/A';
          $context .= "\n### Analisis pada: {$date}\n";
          $context .= "- Persentase Risiko: {$assessment->final_risk_percentage}%\n";
          $context .= "- Kategori Risiko: {$riskCategory}\n";
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
   * Membangun konteks HANYA dari percakapan yang sedang aktif.
   */
  private function buildChatHistoryContext(Conversation $conversation): string
  {
    try {
      // Ambil data dari repository, yang sudah di-cache
      $messages = $this->chatMessageRepository->getLatestMessages($conversation, 10);

      if (!$messages || $messages->isEmpty()) {
        return "Ini adalah awal dari percakapan kita.";
      }

      $history = "RIWAYAT PERCAKAPAN DI THREAD INI:\n";
      foreach ($messages as $message) {
        $role = ($message->role === 'user') ? 'Pengguna' : 'Anda (Kardia)';

        // Cek jika konten adalah JSON, coba decode untuk tampilan lebih baik
        $content = json_decode($message->content, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($content['reply_components'])) {
          // Jika balasan AI tersimpan sebagai JSON, kita ambil paragraf pertama saja untuk konteks
          $historyContent = $content['reply_components'][0]['content'] ?? '[Balasan terstruktur]';
        } else {
          $historyContent = $message->content;
        }

        // Truncate very long messages for context
        if (strlen($historyContent) > 200) {
          $historyContent = substr($historyContent, 0, 200) . '...';
        }

        $history .= "{$role}: {$historyContent}\n";
      }

      return rtrim($history);
    } catch (\Throwable $e) {
      Log::error('Error building chat history context', [
        'error' => $e->getMessage(),
        'conversation_id' => $conversation->id
      ]);
      return "Terjadi kesalahan saat memuat riwayat percakapan.";
    }
  }

  /**
   * Merakit semua konteks menjadi satu Master Prompt final.
   */
  private function buildPrompt(User $user, string $userMessage, string $userContext, string $chatHistoryContext): string
  {
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
PROMPT;

    return <<<PROMPT
{$constitution}
---
# KONTEKS LENGKAP PENGGUNA SAAT INI
{$userContext}
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

  /**
   * Melakukan panggilan ke API chat Gemini.
   */
  private function getGeminiChatCompletion(string $prompt): array
  {
    try {
      $certificatePath = config('filesystems.certificate_path');

      Log::info('Making Gemini API call', [
        'prompt_length' => strlen($prompt),
        'api_url' => $this->apiUrl
      ]);

      $response = Http::withOptions([
        'verify' => $certificatePath ?: false // Use certificate path or disable verification
      ])
        ->timeout(60) // Reduced timeout for faster failure detection
        ->retry(2, 1000) // Retry twice with 1 second delay
        ->post($this->apiUrl, [
          'contents' => [['parts' => [['text' => $prompt]]]],
          'generationConfig' => [
            'temperature' => 0.7,
            'response_mime_type' => 'application/json',
            'maxOutputTokens' => 4096, // Add token limit
          ],
        ]);

      // Enhanced response validation
      if (!$response->successful()) {
        Log::error("Gemini API call failed with HTTP error", [
          'status' => $response->status(),
          'response' => $response->body(),
          'headers' => $response->headers()
        ]);
        throw new Exception("Layanan AI mengembalikan error HTTP: " . $response->status());
      }

      $responseData = $response->json();

      if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        Log::error("Invalid Gemini API response structure", [
          'response' => $responseData
        ]);
        throw new Exception("Format respons dari layanan AI tidak sesuai yang diharapkan.");
      }

      $geminiTextResponse = $responseData['candidates'][0]['content']['parts'][0]['text'];

      if (empty($geminiTextResponse)) {
        Log::error("Empty response from Gemini API");
        throw new Exception("Layanan AI mengembalikan respons kosong.");
      }

      return $this->parseAndCleanGeminiResponse($geminiTextResponse);
    } catch (\Illuminate\Http\Client\ConnectionException $e) {
      Log::error("Gemini API connection failed", [
        'error' => $e->getMessage()
      ]);
      throw new Exception("Gagal terhubung ke layanan AI. Periksa koneksi internet Anda.");
    } catch (\Illuminate\Http\Client\RequestException $e) {
      Log::error("Gemini API request failed", [
        'error' => $e->getMessage(),
        'response' => $e->response ? $e->response->body() : 'No response'
      ]);
      throw new Exception("Gagal mengirim permintaan ke layanan AI.");
    } catch (\Throwable $e) {
      Log::error("Unexpected error in Gemini API call", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
      throw new Exception("Terjadi kesalahan tidak terduga saat berkomunikasi dengan layanan AI.");
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
