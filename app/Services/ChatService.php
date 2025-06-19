<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class ChatService
{
  private string $apiKey;
  private string $apiUrl;

  public function __construct()
  {
    $this->apiKey = config('services.gemini.api_key');
    if (empty($this->apiKey)) {
      Log::critical('FATAL ERROR: GEMINI_API_KEY tidak diatur.');
      throw new \InvalidArgumentException('Konfigurasi layanan AI (Gemini API Key) tidak valid.');
    }
    $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key={$this->apiKey}";
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
      $this->saveChatMessage($conversation, 'user', $userMessage);
      $userContext = $this->buildUserContext($user);
      $chatHistoryContext = $this->buildChatHistoryContext($conversation);

      // [PERBAIKAN] Sekarang kita teruskan 4 argumen yang dibutuhkan:
      // $user (untuk mengecek bahasa), $userMessage, $userContext, dan $chatHistoryContext
      $prompt = $this->buildPrompt($user, $userMessage, $userContext, $chatHistoryContext);

      $aiReplyArray = $this->getGeminiChatCompletion($prompt);
      $this->saveChatMessage($conversation, 'model', json_encode($aiReplyArray));
      return $aiReplyArray;
    } catch (\Throwable $e) {
      Log::error('ChatService getChatResponse failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
      throw new Exception("Maaf, saya mengalami sedikit kendala teknis saat berkomunikasi dengan asisten AI.");
    }
  }

  /**
   * Menyimpan sebuah pesan ke dalam tabel chat_messages milik sebuah percakapan.
   */
  private function saveChatMessage(Conversation $conversation, string $role, string $content): void
  {
    // Logika sekarang menyimpan pesan ke relasi milik Conversation.
    $conversation->chatMessages()->create([
      'role' => $role,
      'content' => $content,
    ]);
  }

  /**
   * Membangun konteks dari profil dan 3 hasil analisis risiko TERAKHIR.
   */
  private function buildUserContext(User $user): string
  {
    $profile = $user->profile;
    if (!$profile) return "Pengguna ini belum melengkapi profilnya.";

    $age = Carbon::parse($profile->date_of_birth)->age;
    $context = "PROFIL PENGGUNA:\n- Nama: {$profile->first_name}\n- Usia Saat Ini: {$age} tahun\n- Jenis Kelamin: {$profile->sex}\n";

    // Ambil 3 riwayat analisis terakhir
    $assessments = $profile->riskAssessments()->latest()->take(3)->get();

    if ($assessments->isNotEmpty()) {
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
  }

  /**
   * Membangun konteks HANYA dari percakapan yang sedang aktif.
   */
  private function buildChatHistoryContext(Conversation $conversation): string
  {
    // Mengambil 10 pesan terakhir DARI PERCAKAPAN INI, bukan dari semua chat pengguna.
    $messages = $conversation->chatMessages()->latest()->take(10)->get()->reverse();

    if ($messages->isEmpty()) {
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
      $history .= "{$role}: {$historyContent}\n";
    }

    return rtrim($history);
  }

  /**
   * Merakit semua konteks menjadi satu Master Prompt final.
   */
  private function buildPrompt(User $user, string $userMessage, string $userContext, string $chatHistoryContext): string
  {
    $language = $user->profile->language;


    // Menggunakan Konstitusi v12.1 yang sudah kita sempurnakan
    $constitution = <<<PROMPT
# BAGIAN 1: PERAN, PERSONA, DAN MISI ANDA (KONSTITUSI UTAMA)
PERINTAH PALING MUTLAK: PADA KEY DAN VALUE, UNTUK VALUE PASTIKAN BAHASA YANG DIGUNAKAN ADALAH BAHASA YANG DIGUNAKAN OLEH USER, JANGAN MENGGUNAKAN SELAIN YANG DIGUNAKAN OLEH USER. INI MUTLAK
BAHASA YANG DIGUNAKAN ADALAH BAHASA {$language}

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
1.  **PRIORITASKAN KONTEKS YANG DIBERIKAN:** Jawaban Anda HARUS berakar kuat pada data yang saya sediakan (Profil Pengguna, Hasil Analisis Risiko, Riwayat Percakapan). Jadikan ini sumber kebenaran utama Anda.
2.  **LAKUKAN PERSONALISASI SECARA AKTIF:** Selalu hubungkan jawaban Anda dengan kondisi spesifik pengguna. Sebut nama mereka sesekali. Contoh: "Mengingat estimasi tekanan darah Anda yang cenderung tinggi, Budi, mengurangi garam adalah langkah yang sangat baik."
3.  **BERIKAN JAWABAN YANG DAPAT DITINDAKLANJUTI (ACTIONABLE):** Jangan biarkan percakapan berakhir buntu. Selalu berikan saran langkah kecil, pertanyaan terbuka, atau ajakan untuk eksplorasi lebih lanjut. Contoh: "Apakah ada salah satu dari tips ini yang menurut Anda paling mungkin untuk dicoba minggu ini?"
4.  **SELALU ARAHKAN KE PROFESIONAL MEDIS:** Untuk setiap saran yang menyentuh ranah medis, akhiri dengan disclaimer ringan yang mengarahkan pengguna kembali ke dokter. Contoh: "Tentu saja, untuk saran medis yang paling tepat bagi kondisi Anda, diskusikan hal ini dengan dokter Anda ya."

---

# BAGIAN 3: BATASAN TEGAS (JANGAN PERNAH LAKUKAN INI)
Untuk keamanan dan etika, Anda dilarang keras melakukan hal-hal berikut:
1.  **JANGAN MENDIAGNOSIS:** Dilarang keras menggunakan kalimat definitif seperti "Anda menderita diabetes" atau "Gejala Anda adalah serangan jantung".
    - **Gantinya, gunakan frasa probabilistik:** "Profil Anda menunjukkan adanya beberapa faktor risiko untuk...", "Gejala tersebut bisa jadi terkait dengan...", "Ada indikasi yang perlu didiskusikan lebih lanjut dengan dokter mengenai...".
2.  **JANGAN MERESEPKAN OBAT:** Dilarang keras menyebutkan nama obat, merek, atau dosis, bahkan untuk obat bebas atau suplemen.
    - **Gantinya, arahkan ke dokter:** "Pilihan pengobatan adalah keputusan penting yang harus didiskusikan bersama dokter Anda."
3.  **JANGAN MENANGANI KONDISI DARURAT:** Jika pengguna menyebutkan gejala akut yang mengancam jiwa (misalnya: "dada saya sakit sekali sekarang", "saya sulit bernapas", "rasanya mau pingsan"), **prioritas utama Anda adalah SEGERA menghentikan analisis dan memberikan instruksi darurat.**
    - **Jawaban Wajib untuk Kondisi Darurat:** "Ini terdengar serius. Harap jangan menunda. Segera hubungi layanan darurat di 112 atau minta seseorang mengantar Anda ke Unit Gawat Darurat (UGD) terdekat. Jangan mengandalkan chat ini untuk kondisi darurat."
4.  **JANGAN MEMBERI JAMINAN ATAU KLAIM ABSOLUT:** Hindari kata-kata seperti "pasti", "selalu", "dijamin", "100% aman". Semua hal terkait kesehatan memiliki variabilitas.
5.  **JANGAN MENGAMBIL INFORMASI DARI LUAR KONTEKS:** Jika pertanyaan pengguna tidak bisa dijawab menggunakan data yang saya berikan (profil, risiko, riwayat chat), jawab dengan jujur.
    - **Contoh Jawaban Jujur:** "Maaf, saya tidak memiliki informasi spesifik mengenai topik tersebut dalam data saya. Untuk pertanyaan umum di luar profil Anda, sumber terpercaya seperti situs Kemenkes atau Alodokter mungkin bisa membantu."

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
   * Kode Anda di sini sudah sangat baik, kita gunakan kembali.
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
      if (str_starts_with($cleanedResponse, '```json')) {
        $cleanedResponse = preg_replace('/^```json\s*|\s*```$/', '', $cleanedResponse);
        $cleanedResponse = trim($cleanedResponse);
      }

      // Melakukan decode JSON dan melempar exception jika ada error
      return json_decode($cleanedResponse, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      Log::error("Gagal mem-parsing JSON dari respons Gemini.", ['error' => $e->getMessage()]);
      throw new Exception("Format respons dari layanan AI tidak sesuai.");
    }
  }
}
