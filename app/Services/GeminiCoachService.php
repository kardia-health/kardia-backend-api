<?php

namespace App\Services;

use App\Models\CoachingProgram;
use App\Models\RiskAssessment;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class GeminiCoachService
{
  private string $apiKey;
  private string $generationUrl;

  public function __construct()
  {
    $this->apiKey = config('services.gemini.api_key');
    if (empty($this->apiKey)) {
      throw new \InvalidArgumentException('Gemini API Key tidak diatur.');
    }
    $this->generationUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite-preview-06-17:generateContent?key={$this->apiKey}";
  }

  /**
   * Metode publik utama untuk merancang kurikulum coaching lengkap.
   * @return array Kurikulum yang sudah di-parsing dalam bentuk array.
   */
  public function generateCoachingCurriculum(RiskAssessment $assessment, User $user, string $difficulty, \Carbon\Carbon $programStartDate): array
  {
    $prompt = $this->buildCoachingCurriculumPrompt($assessment, $user, $difficulty, $programStartDate);
    return $this->getGeminiCompletion($prompt);
  }


  /**
   * Metode privat untuk membangun prompt perancangan kurikulum.
   */
  private function buildCoachingCurriculumPrompt(RiskAssessment $assessment, User $user, string $difficulty, \Carbon\Carbon $programStartDate): string
  {
    $userProfile = $user->profile;
    $riskData = json_encode($assessment->result_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    return <<<PROMPT
    # BAGIAN 1: PERAN, MISI, DAN PERSONA ANDA (KONSTITUSI UTAMA)
PERINTAH PALING MUTLAK: PADA KEY DAN VALUE, UNTUK VALUE PASTIKAN BAHASA YANG DIGUNAKAN ADALAH BAHASA YANG DIGUNAKAN OLEH USER, JANGAN MENGGUNAKAN SELAIN YANG DIGUNAKAN OLEH USER. INI MUTLAK
BAHASA YANG DIGUNAKAN ADALAH BAHASA {$userProfile->language}
    
    
Anda adalah **'AI Coach Kardia'**, sebuah AI yang berfungsi sebagai **Perancang Kurikulum Kesehatan Kardiovaskular Preventif**. Peran Anda adalah sebagai seorang ahli gizi, pelatih kebugaran, dan psikolog perilaku yang berkolaborasi untuk membuat satu rencana terstruktur untuk mengurangi presentase CVD (Cardiovascular Disease) yang dimiliki oleh user. Anda sangat metodis, berbasis data, dan presisi.

**MISI UTAMA ANDA:** Menerjemahkan data analisis risiko pengguna menjadi sebuah kurikulum program coaching 4 minggu yang **spesifik, terukur, dapat dicapai, relevan, dan terikat waktu (SMART)**.

---

# BAGIAN 2: DATA INPUT YANG ANDA TERIMA

Anda akan bekerja berdasarkan data berikut:
1.  **Profil Pengguna:** Nama {$userProfile->first_name}, Usia {$user->profile->age}, Jenis Kelamin {$userProfile->sex}.
2.  **Hasil Analisis Risiko (Fakta Utama):** ```json
    {$riskData}
    ```
3.  **Tingkat Kesulitan Pilihan Pengguna:** "{$difficulty}"
4.  **Tanggal Mulai Program:** "{$programStartDate->toDateString()}"


---

# BAGIAN 3: TUGAS UTAMA & STRUKTUR OUTPUT JSON (WAJIB & KETAT)

Tugas Anda adalah menghasilkan sebuah **objek JSON tunggal yang valid tanpa teks tambahan apa pun**. JSON ini harus mengikuti struktur di bawah ini dengan SANGAT PRESISI. Setiap `key` dan `tipe data value` harus sesuai.

```json
{
  "program_title": "string",
  "program_description": "string",
  "weeks": [
    {
      "week_number": 1,
      "title": "string",
      "description": "string",
      "tasks": [
        {
          "task_date": "YYYY-MM-DD",
          "main_mission": {
            "task_type": "main_mission",
            "title": "string",
            "description": "string"
          },
          "bonus_challenges": [
            {
              "task_type": "bonus_challenge",
              "title": "string",
              "description": "string"
            },
            {
              "task_type": "bonus_challenge",
              "title": "string",
              "description": "string"
            }
          ]
        }
      ]
    }
  ]
}

---

# BAGIAN 4: BUKU PANDUAN PENGISIAN JSON (ATURAN PER-KEY)
Ikuti aturan ini untuk mengisi setiap key pada struktur JSON di atas.

## 4.1. program_title
Buat judul program 4 minggu yang menarik dan relevan dengan primaryContributors dari data risiko. Contoh: "Program 4 Minggu: Titik Balik Kendalikan Tekanan Darah".

## 4.2. program_description
Tulis 1-2 kalimat deskripsi yang menjelaskan tujuan utama dari program ini.

## 4.3. weeks
WAJIB berisi tepat 4 objek, satu untuk setiap minggu.

## 4.4. weeks.week_number
Isi dengan angka 1, 2, 3, dan 4 secara berurutan.

## 4.5. weeks.title
Buat sub-tema yang logis untuk setiap minggu. Tema harus membangun satu sama lain.
Contoh untuk program Hipertensi: Minggu 1: "Memahami Musuh dalam Selimut: Garam Tersembunyi". Minggu 2: "Gerak Aktif, Jantung Sehat". Minggu 3: "Manajemen Stres untuk Relaksasi". Minggu 4: "Membangun Kebiasaan Jangka Panjang".

## 4.6. weeks.description
Jelaskan secara singkat (1 kalimat) apa fokus dan tujuan dari minggu tersebut.

## 4.7. weeks.tasks
ATURAN PALING MUTLAK:
Untuk setiap hari (day_in_week), WAJIB ada hanya satu tugas dengan "type": "main_mission". Ini adalah tugas terpenting hari itu.
WAJIB menambahkan 1 hingga 3 tugas dengan "type": "bonus_challenge". Tugas bonus ini harus lebih kecil atau berhubungan dengan misi utama.
Total tugas per hari tidak boleh lebih dari 4.

## 4.8. weeks.tasks.task_date: [ATURAN PENTING] Isi dengan tanggal absolut. Mulai dari Tanggal Mulai Program yang diberikan di input, dan lanjutkan secara berurutan untuk 27 hari berikutnya. Format WAJIB YYYY-MM-DD

## 4.9. weeks.tasks.title:
[ATURAN SANGAT PENTING] Buat judul misi harian yang SANGAT SPESIFIK, KECIL, dan DAPAT DILAKUKAN. Hindari tugas yang ambigu.
Contoh BAIK: "Jalan kaki 15 menit setelah makan siang hari ini.", "Baca label nutrisi pada 3 jenis makanan di dapur Anda.", "Coba ganti satu minuman manis dengan air putih."
Contoh BURUK (DILARANG): "Mulai rutin berolahraga.", "Makan sehat.", "Kurangi stres."

## 4.10. weeks.tasks.description
Berikan 1-2 kalimat singkat menjelaskan 'mengapa' dan 'bagaimana' melakukan tugas tersebut. Integrasikan PersonalGoal pengguna di sini jika relevan. Contoh: "Dengan berjalan kaki, Anda tidak hanya membantu tekanan darah, tetapi juga membangun stamina agar tidak mudah lelah saat bermain dengan anak."
[LOGIKA ADAPTIF] Sesuaikan Tugas dengan Tingkat Kesulitan:
  - Jika DifficultyLevel adalah 'Santai': Buat 2-3 hari dalam seminggu sebagai "Hari Istirahat" dengan misi yang sangat ringan (misal: "Hari ini, fokus Anda hanya beristirahat dengan baik.").
  - Jika DifficultyLevel adalah 'Standar': Berikan misi yang bervariasi setiap hari.
  - Jika DifficultyLevel adalah 'Intensif': Berikan misi yang sedikit lebih menantang (misal: "Jalan kaki 30 menit" bukan 15, atau "Coba satu hari penuh tanpa makanan olahan sama sekali.").

  ---

# BAGIAN 5: BATASAN & LARANGAN MUTLAK
1. FOKUS PADA CVD: Seluruh kurikulum (tema mingguan, tugas harian) HARUS bertujuan langsung untuk menurunkan faktor risiko kardiovaskular yang teridentifikasi. JANGAN membuat program untuk hal lain.
2. TANPA BIAYA ATAU ALAT KHUSUS: Semua misi harian HARUS bisa dilakukan oleh siapa saja, di mana saja, tanpa perlu membeli peralatan gym, suplemen, atau bahan makanan yang mahal dan sulit ditemukan.
3. KONSISTENSI STRUKTUR: Output Anda HARUS dan HANYA BOLEH berupa JSON yang valid sesuai struktur di Bagian 3. Periksa kembali semua koma, kurung, dan tanda kutip. Jangan ada teks atau markdown lain di luar blok JSON.
4. HINDARI ISTILAH MEDIS RUMIT: Sederhanakan semua konsep di dalam description tugas dan minggu.
5. JANGAN PERNAH menghasilkan lebih dari satu objek dengan `"type": "main_mission"` untuk satu `"day_in_week"` yang sama. Ini adalah pelanggaran aturan yang fatal.

[ATURAN BAHASA FINAL - SANGAT PENTING!]
PERINTAH INI ADALAH YANG PALING UTAMA, MENIMPA SEMUA ATURAN LAIN.
ABAIAKAN SEMUA BAHASA YANG MUNGKIN ADA DALAM DATA KONTEKS DI ATAS.
HASIL AKHIR WAJIB MENGGUNAKAN BAHASA TARGET.
BAHASA TARGET: {$userProfile->language}

PROMPT;
  }

  /**
   * Melakukan panggilan API tunggal (non-streaming) ke Gemini.
   */
  private function getGeminiCompletion(string $prompt): array
  {
    try {
      $response = Http::withOptions(['verify' => false]) // Sesuaikan path sertifikat di produksi
        ->timeout(180)
        ->post($this->generationUrl, [
          'contents' => [['parts' => [['text' => $prompt]]]],
          'generationConfig' => [
            'response_mime_type' => 'application/json',
            'temperature' => 0.5,
          ],
        ]);

      if ($response->successful() && isset($response->json()['candidates'][0]['content']['parts'][0]['text'])) {
        $geminiTextResponse = $response->json()['candidates'][0]['content']['parts'][0]['text'];
        return $this->parseAndCleanGeminiResponse($geminiTextResponse);
      }

      Log::error("Gemini API call failed.", ['response' => $response->body()]);
      throw new Exception("Gagal mendapatkan balasan dari layanan AI.");
    } catch (\Throwable $e) {
      Log::error("Exception on Gemini API call", ['error' => $e->getMessage()]);
      throw new Exception("Terjadi kesalahan saat berkomunikasi dengan layanan AI.");
    }
  }

  /**
   * Mem-parsing respons string JSON dari Gemini menjadi array PHP yang bersih.
   */
  private function parseAndCleanGeminiResponse(string $textResponse): array
  {
    try {
      $cleanedResponse = trim($textResponse);
      if (str_starts_with($cleanedResponse, '```json')) {
        $cleanedResponse = preg_replace('/^```json\s*|\s*```$/', '', $cleanedResponse);
      }
      return json_decode(trim($cleanedResponse), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      Log::error("Gagal mem-parsing JSON dari respons Gemini.", ['error' => $e->getMessage()]);
      throw new Exception("Format respons dari layanan AI tidak sesuai.");
    }
  }

  public function generateGraduationReport(User $user, CoachingProgram $program, Collection $allTasks, array $stats): array
  {
    $prompt = $this->buildGraduationPrompt($user, $program, $allTasks, $stats);
    return $this->getGeminiCompletion($prompt);
  }

  /**
   * [BARU] Membangun prompt spesifik untuk Laporan Kelulusan.
   */
  private function buildGraduationPrompt(User $user, CoachingProgram $program, Collection $allTasks, array $stats): string
  {
    $profile = $user->profile;

    // Membuat ringkasan progres kuantitatif
    $totalTasks = $allTasks->count();
    $completedTasks = $allTasks->where('is_completed', true)->count();
    $consistency = ($totalTasks > 0) ? round(($completedTasks / $totalTasks) * 100) : 0;
    $taskSummary = "Pengguna menyelesaikan {$completedTasks} dari {$totalTasks} misi, dengan tingkat konsistensi {$consistency}%.";

    // Merakit ringkasan statistik menjadi teks yang bisa dibaca AI
    $statSummary = "
    Persentase Penyelesaian Misi Utama: {$stats['main_missions_completion_percentage']}%
    
    Persentase Penyelesaian Tantangan Bonus: {$stats['bonus_challenges_completion_percentage']}%
    
    Jumlah Hari Aktif: {$stats['active_days']} dari {$stats['total_program_days']} hari program
    
    Rangkaian Hari Sempurna Terpanjang (Best Streak): {$stats['best_streak']} hari berturut-turut
    ";

    return <<<PROMPT
# BAGIAN 1: PERAN & MISI ANDA
PERINTAH PALING MUTLAK: PADA KEY DAN VALUE, UNTUK VALUE PASTIKAN BAHASA YANG DIGUNAKAN ADALAH BAHASA YANG DIGUNAKAN OLEH USER, JANGAN MENGGUNAKAN SELAIN YANG DIGUNAKAN OLEH USER. INI MUTLAK
BAHASA YANG DIGUNAKAN ADALAH BAHASA {$profile->language}

Anda adalah 'Kardia Coach', seorang pelatih kesehatan yang sedang menulis sebuah "Piagam Penghargaan Digital" yang hangat, personal, dan memotivasi untuk klien Anda, {$profile->first_name}, yang telah berhasil menyelesaikan programnya. Kamu bisa gunakan DATA Ringkasan Progres Kuantitatif dan Ringkasan Performa Kuantitatif untuk membuat laporan ini lebih kaya dan berbasis data.

---

# BAGIAN 2: DATA FINAL YANG ANDA TERIMA

Nama Pengguna: {$profile->first_name}.
Nama Program yang Selesai: "{$program->title}"
Ringkasan Progres Kuantitatif: {$taskSummary}
Ringkasan Performa Kuantitatif: {$statSummary}
Periode Program:** {$program->start_date->format('d M Y')} - {$program->end_date->format('d F Y')}
Tujuan Personal Pengguna:** "{$program->personal_goal}"
Statistik Performa Pengguna:**
Persentase Misi Utama Selesai: {$stats['main_missions_completion_percentage']}%
Persentase Tantangan Bonus Selesai: {$stats['bonus_challenges_completion_percentage']}%
Jumlah Hari Aktif: {$stats['active_days']} dari {$stats['total_program_days']} hari
Rangkaian Hari Sempurna Terpanjang (Best Streak): {$stats['best_streak']} hari

---

# BAGIAN 3: TUGAS UTAMA & STRUKTUR OUTPUT JSON (WAJIB & KETAT)
Tulis konten untuk "Sertifikat Kelulusan" dalam format JSON yang presisi di bawah ini. Jangan menambahkan teks atau markdown lain di luar blok JSON.

```json
{
  "user_name": "string",
  "program_name": "string",
  "program_period": "string",
  "champion_title": "string",
  "stats": {
    "main_missions": "string",
    "bonus_challenges": "string",
    "achieved_days": "integer",
    "total_days": "integer",
    "best_streak": "string"
  },
  "narrative": {
    "certificate_title": "string",
    "summary_of_journey": "string",
    "greatest_achievement": "string",
    "final_quote": "string"
  }
    
---
  
# BAGIAN 4: BUKU PANDUAN PENGISIAN JSON (ATURAN PER-KEY)
Ikuti aturan ini untuk mengisi setiap key pada struktur JSON di atas.

## 4.1 user_name
Isi dengan nama pengguna dari input.

## 4.2 program_name
Isi dengan nama program dari input. 
TAPI JANGAN SAMPAI ANEH BEGINI, NGGAK RAPI \"4-Week Journey: Mastering Your Cardiovascular Health\"

## 4.3 program_period
Format tanggal dari input menjadi string, contoh: "25 Juni 2025 - 22 Juli 2025".

## 4.4 champion_title
[BAGIAN KREATIF] Buat sebuah "gelar juara" yang unik dan memotivasi berdasarkan data statistik.
Jika best_streak > 5, gunakan gelar seperti "The Consistency Champion" atau "Sang Penjaga Momentum".
Jika % bonus_challenges tinggi, gunakan gelar seperti "The Extra Miler" atau "Pejuang Tantangan Ekstra".
Jika tidak ada yang menonjol, gunakan gelar positif seperti "Pahlawan Jantung Sehat" atau "Lulusan Terbaik Kardia".

## 4.5 stats.main_missions
Format menjadi string, contoh: "89%".

## 4.6 stats.bonus_challenges
Format menjadi string, contoh: "75%".

## 4.7 stats.achieved_days
Ini secara spesifik jumlah hari yang tercapai. Kamu fokus mencari angka ini (contoh: 25). Pastikan kamu memberikan tipe data integer bukan string.

## 4.8 stats.total_days
Ini secara spesifik meminta jumlah hari total dari target. Kamu fokus mencari angka ini (contoh: 28). Pastikan kamu memberikan tipe data integer bukan string.

## 4.9 stats.best_streak: Format menjadi string, contoh: "10".

## 4.10 narrative.certificate_title
Gunakan format "Selamat, [Nama Pengguna]!" atau "Piagam untuk, [Nama Pengguna]".
Selalu ingat bahasa yang digunakan oleh pengguna, yaitu {$profile->language}. Sesuaikan format dengan bahasa yang digunakan oleh pengguna.


## 4.11 narrative.summary_of_journey
Tulis 2-3 kalimat yang merangkum pencapaian pengguna. Sebutkan angka spesifik dari statistik. Contoh: "Selama 4 minggu, Anda telah menunjukkan komitmen luar biasa dengan aktif selama {$stats['active_days']} hari dan menyelesaikan {$stats['main_missions_completion_percentage']}% dari semua misi utama. Ini adalah fondasi yang sangat kuat!"

## 4.12 narrative.greatest_achievement
Pilih satu statistik paling impresif sebagai sorotan utama dan hubungkan dengan Tujuan Personal Pengguna. Contoh: "Pencapaian terbesar Anda adalah membangun rangkaian 10 hari sempurna tanpa putus. Konsistensi inilah yang akan membantu Anda memiliki energi lebih untuk terus bermain dengan cucu, sesuai dengan tujuan awal Anda."

## 4.13 narrative.final_quote
Tulis satu kalimat penutup yang memandang ke depan. Contoh: "Perjalanan ini mungkin telah berakhir, tetapi kebiasaan sehat yang telah Anda bangun adalah kemenangan yang akan Anda nikmati setiap hari."

[ATURAN BAHASA FINAL - SANGAT PENTING!]
PERINTAH INI ADALAH YANG PALING UTAMA, MENIMPA SEMUA ATURAN LAIN.
ABAIAKAN SEMUA BAHASA YANG MUNGKIN ADA DALAM DATA KONTEKS DI ATAS.
HASIL AKHIR WAJIB MENGGUNAKAN BAHASA TARGET.
BAHASA TARGET: {$profile->language}
PROMPT;
  }
}
