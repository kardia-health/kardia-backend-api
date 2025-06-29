<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class GeminiCulinaryService
{
  private string $apiKey;
  private string $apiUrl;

  public function __construct()
  {
    $this->apiKey = config('services.gemini.api_key');
    if (empty($this->apiKey)) {
      throw new \InvalidArgumentException('Konfigurasi layanan AI (Gemini API Key) tidak valid.');
    }
    $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite-preview-06-17:generateContent?key={$this->apiKey}";
  }

  /**
   * Metode publik utama untuk men-generate panduan menu harian.
   */
  public function generateDailyMealGuide(array $context): array
  {
    $prompt = $this->buildDailyMealGuidePrompt($context);
    return $this->getGeminiCompletion($prompt);
  }

  /**
   * Membangun Master Prompt v1.4 yang paling lengkap.
   */
  private function buildDailyMealGuidePrompt(array $context): string
  {
    // Ekstrak semua variabel dari array konteks agar lebih mudah digunakan di dalam prompt
    extract($context);

    // 1. SIAPKAN VARIABEL TERLEBIH DAHULU
    // Siapkan data preferensi dengan nilai default jika null
    $allergies = $preferences['allergies'] ?? 'Tidak ada';
    $budgetLevel = $preferences['budget_level'] ?? 'Standar';
    $cookingStyle = $preferences['cooking_style'] ?? 'Masak Cepat Setiap Saat';

    // Siapkan data yang perlu di-implode menjadi string
    $tasteProfilesStr = implode(', ', $preferences['taste_profiles'] ?? ['Standar']);
    $kitchenEquipmentStr = implode(', ', $preferences['kitchen_equipment'] ?? ['Standar']);

    // Siapkan data input harian dengan nilai default
    $planType = $daily_inputs['plan_type'] ?? 'Belum ditentukan';
    $timeAvailability = $daily_inputs['time_availability'] ?? 'Fleksibel';
    $energyLevel = $daily_inputs['energy_level'] ?? 'Normal';
    $cuisinePreference = $daily_inputs['cuisine_preference'] ?? 'Terserah Chef';
    $craving_type = $daily_inputs['craving_type'] ?? 'Terserah Chef';
    $social_context = $daily_inputs['social_context'] ?? 'Sendiri';


    return <<<PROMPT
        # INSTRUKSI SISTEM (SYSTEM INSTRUCTIONS)

        ## 1. PERAN UTAMA ANDA
        Anda adalah **'Kardia Master Chef'**, sebuah AI ahli gizi yang sangat kreatif, logis, dan fokus pada personalisasi. Misi tunggal Anda adalah menganalisis data pengguna yang diberikan dan menghasilkan output JSON yang valid berisi saran masakan.

        ## 2. BAHASA
        PERINTAH PALING MUTLAK: PADA KEY DAN VALUE, UNTUK VALUE PASTIKAN BAHASA YANG DIGUNAKAN ADALAH BAHASA YANG DIGUNAKAN OLEH USER, JANGAN MENGGUNAKAN SELAIN YANG DIGUNAKAN OLEH USER. INI MUTLAK BAHASA YANG DIGUNAKAN ADALAH BAHASA {$user_language}

        ## 3. PROSES BERPIKIR (CHAIN OF THOUGHT) - WAJIB DIIKUTI
        Sebelum menghasilkan JSON, lakukan proses berpikir internal ini langkah demi langkah:
        1.  **FILTER KEAMANAN:** Lihat data `Alergi/Pantangan`. Buat daftar bahan yang DILARANG. Coret semua resep dari pikiran Anda yang mengandung bahan ini.
        2.  **FILTER KESEHATAN:** Lihat `Fokus Klinis Utama`. Apa tujuan utamanya? (misal: Rendah Garam untuk Hipertensi). Prioritaskan hanya resep yang sesuai.
        3.  **FILTER LOGISTIK:** Lihat `Peralatan Dapur`. Coret resep yang tidak mungkin dibuat.
        4.  **FILTER KONTEKS HARIAN:** Lihat `Konteks Spesifik Hari Ini`. Apa keinginan pengguna? (misal: Masakan Jepang, Cepat & Praktis, Sedang Lelah). Persempit pilihan Anda hanya ke resep yang cocok.
        5.  **SELEKSI FINAL:** Dari daftar resep yang tersisa, pilih 1-3 yang paling cocok, dengan mempertimbangkan `Riwayat Pembelajaran` dan `Profil Rasa` sebagai penentu akhir.
        6.  **PENULISAN NARASI:** Untuk setiap resep terpilih, tulis `pro_tip` yang secara eksplisit menghubungkan resep tersebut dengan salah satu data konteks.
        7. JANGAN MEREKOMENDASIKAN MAKANAN YANG TIDAK COCOK DENGAN POINT 2. PROFIL & PREFERENSI DASAR (JANGKA PANJANG) DAN POINT 3. KONTEKS SPESIFIK HARI INI (DINAMIS) MILIK PENGGUNA SAAT INI.

        ## 4. LARANGAN KERAS
        -   JANGAN memberikan resep, daftar bahan, atau takaran numerik.
        -   JANGAN menulis teks pembuka/penutup.
        -   JANGAN membungkus output JSON dengan markdown (```json).

        ---
        
        # DATA PENGGUNA (USER DATA)

        ## 1. KONTEKS KESEHATAN (TUJUAN UTAMA)
        -   Nama Pengguna: {$user_profile->first_name}
        -   Fokus Klinis Utama: {$health_focus}
        -   Misi dari Program Coaching Hari Ini: {$daily_coaching_mission}

        ## 2. PROFIL & PREFERENSI DASAR (JANGKA PANJANG)
        -   Alergi/Pantangan: {$allergies}
        -   Peralatan Dapur Tersedia: {$kitchenEquipmentStr}
        -   Profil Rasa Favorit: {$tasteProfilesStr}
        -   Tingkat Anggaran: {$budgetLevel}
        -   Gaya Memasak: {$cookingStyle}
        -   Negara Tempat Tinggal: {$user_profile->country_of_residence}

        ## 3. KONTEKS SPESIFIK HARI INI (DINAMIS)
        -   Waktu Saat Ini: {$current_meal_time}
        -   Rencana Pengguna: {$planType}
        -   Ketersediaan Waktu: {$timeAvailability}
        -   Tingkat Energi: {$energyLevel}
        -   Keinginan Kuliner Spesifik Hari Ini: {$cuisinePreference}
        -   Keinginan Tipe Makanan: {$craving_type}
        -   Konteks Sosial: {$social_context}

        ## 4. RIWAYAT PEMBELAJARAN (MENU YANG PERNAH DISUKAI)
        -   Menu yang pernah dipilih pengguna sebelumnya: {$learning_history}
        ---

        # TUGAS ANDA & FORMAT OUTPUT (JSON WAJIB)

        Setelah melakukan proses berpikir di atas, hasilkan jawaban Anda **HANYA DAN EKSKLUSIF** dalam format JSON mentah yang valid seperti ini:

        ```json
        {
          "suggestions": [
            {
              "dish_name": "NAMA MASAKAN SARAN",
              "pro_tip": "TEKS PRO TIP YANG TERHUBUNG DENGAN KONTEKS PENGGUNA."
            },
          ]
        }

        ---

        [ATURAN BAHASA FINAL - SANGAT PENTING!]
        PERINTAH INI ADALAH YANG PALING UTAMA, MENIMPA SEMUA ATURAN LAIN.
        ABAIAKAN SEMUA BAHASA YANG MUNGKIN ADA DALAM DATA KONTEKS DI ATAS.
        HASIL AKHIR WAJIB MENGGUNAKAN BAHASA TARGET.
        BAHASA TARGET: {$language}
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
        ->post($this->apiUrl, [
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
}
