<?php

namespace App\Services;

use App\Models\RiskAssessment;
use App\Models\UserProfile;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Class GeminiReportService
 * Bertugas mengambil hasil analisis klinis yang sudah jadi dan data pengguna,
 * lalu mengirimkannya ke Gemini untuk diolah menjadi laporan naratif yang lengkap.
 */
class GeminiReportService
{
  protected string $apiKey;
  protected string $apiUrl;

  public function __construct()
  {
    $this->apiKey = config('services.gemini.api_key');
    // Gunakan model yang kuat untuk pemahaman konteks dan generasi JSON yang kompleks.
    $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-05-20:generateContent?key={$this->apiKey}";
  }

  /**
   * Fungsi utama untuk mendapatkan laporan analisis dari Gemini.
   * @param UserProfile $userProfile Profil pengguna yang berisi data demografis.
   * @param RiskAssessment $assessment Hasil analisis yang berisi input & hasil persentase.
   * @return array Laporan lengkap dalam bentuk array.
   * @throws Exception
   */
  public function getFullReport(UserProfile $userProfile, RiskAssessment $assessment): array
  {
    if (empty($this->apiKey)) {
      Log::critical('GEMINI_API_KEY tidak diatur.');
      throw new Exception("API key untuk layanan AI tidak diatur.");
    }

    // Bangun prompt dengan data yang sudah matang
    $prompt = $this->buildPrompt($userProfile, $assessment);

    Log::info("Mengirim permintaan laporan naratif ke Gemini untuk user ID: {$userProfile->user_id}");

    // $response = Http::timeout(90)->post($this->apiUrl, [
    //   'contents' => [['parts' => [['text' => $prompt]]]],
    //   'generationConfig' => [
    //     'response_mime_type' => 'application/json',
    //     'temperature' => 0.7,
    //   ]
    // ]);
    // Path absolut ke file sertifikat Anda
    $certificatePath = config('filesystems.certificate_path');

    $response = Http::withOptions([
      'verify' => $certificatePath
    ])->timeout(300)->post($this->apiUrl, [
      'contents' => [['parts' => [['text' => $prompt]]]],
      'generationConfig' => [
        'response_mime_type' => 'application/json',
        'temperature' => 0.7,
      ]
    ]);

    if ($response->successful() && isset($response->json()['candidates'][0]['content']['parts'][0]['text'])) {
      $geminiTextResponse = $response->json()['candidates'][0]['content']['parts'][0]['text'];
      Log::info("Respons laporan naratif dari Gemini diterima untuk user ID: {$userProfile->user_id}");
      return $this->parseGeminiResponse($geminiTextResponse);
    }

    $errorBody = $response->body();
    Log::error("Panggilan API Gemini gagal untuk laporan naratif.", [
      'status' => $response->status(),
      'body' => $errorBody
    ]);
    throw new Exception("Gagal mendapatkan analisis dari layanan AI: " . ($response->json('error.message') ?? $errorBody));
  }

  /**
   * Membangun prompt yang efisien dan fokus pada tugas narasi.
   */
  private function buildPrompt(UserProfile $userProfile, RiskAssessment $assessment): string
  {
    // Konversi data array menjadi string yang mudah dibaca oleh AI
    $userProfileString = "- Nama: {$userProfile->first_name}\n- Usia: {$assessment->generated_values['age']}\n- Jenis Kelamin: {$userProfile->sex}\n- Tempat Tinggal: {$userProfile->country_of_residence}";
    $originalInputsString = json_encode($assessment->inputs, JSON_PRETTY_PRINT);

    // 1. Dapatkan preferensi bahasa dari profil pengguna
    $language = $userProfile->language ?? 'id'; // Default ke 'id' jika tidak ada

    // Ambil bagian terbaik dari prompt referensi Anda (Peran, Disclaimer, Larangan)
    $systemInstructions = <<<PROMPT
# BAGIAN 1: INSTRUKSI SISTEM 
PERINTAH PALING MUTLAK: PADA KEY DAN VALUE, UNTUK VALUE PASTIKAN BAHASA YANG DIGUNAKAN ADALAH BAHASA YANG DIGUNAKAN OLEH USER, JANGAN MENGGUNAKAN SELAIN YANG DIGUNAKAN OLEH USER. INI MUTLAK
BAHASA YANG DIGUNAKAN ADALAH BAHASA {$language}

## 1.1 PERAN ANDA
Anda adalah 'Kardia AI', seorang asisten analis kesehatan kardiovaskular AI yang berempati, berbasis data, dan komunikatif. Persona Anda adalah seorang ahli yang sangat berpengetahuan, namun juga seorang sahabat yang peduli, hangat, dan memotivasi. Anda berbicara dengan bahasa Indonesia yang baik, jelas, dan mudah dimengerti oleh orang awam.

## 1.2 MISI UTAMA:
Tugas Anda bukan sekadar menyampaikan angka. Anda adalah penyuluh digital kesehatan—yang menerjemahkan data klinis kompleks dan informasi gaya hidup menjadi laporan yang:
- Mudah dipahami, bahkan oleh orang tanpa latar belakang medis.
- Menyentuh secara personal, seolah ditulis langsung untuk pengguna.
- Memberdayakan, memberi rasa kontrol dan arah tindakan nyata.
- Membangkitkan harapan, bukan ketakutan.
- Memicu perubahan positif, dimulai dari langkah kecil yang bermakna.

## 1.3 INPUT YANG AKAN ANDA TERIMA:
1. Profil pengguna dan hasil kuesioner gaya hidup (usia, kebiasaan, riwayat keluarga, dll).
2. Hasil estimasi risiko penyakit kardiovaskular 10 tahun (dalam persen) berdasarkan algoritma klinis SCORE2.

## 1.4 ATURAN MUTLAK
1. JANGAN memberikan diagnosis definitif.
2. JANGAN menyebutkan nama atau dosis obat, suplemen, atau intervensi medis spesifik.al.
3. JANGAN memberi jaminan atau kepastian absolut.
4. PATUHI format **output JSON** yang diminta **dengan sangat ketat**.
5. Dilarang keras:
   - Mendiagnosis penyakit.
   - Meresepkan atau menyarankan obat.
   - Memberi jaminan kesembuhan, pencegahan absolut, atau hasil klinis pasti.
   - Menyisipkan opini pribadi atau spekulatif.
Ingat: Anda bukan pengganti dokter, Anda adalah asisten informasi berbasis data klinis yang bersifat edukatif dan suportif.";
PROMPT;

    // Gabungkan semua menjadi satu prompt yang kuat
    $prompt = <<<PROMPT
{$systemInstructions}
---
# BAGIAN 2: DATA PROFIL & JAWABAN KUESIONER PENGGUNA
Gunakan data ini untuk memahami konteks, kehidupan, dan perilaku pengguna secara mendalam. Ini adalah kunci untuk personalisasi.

## 2.1 DATA PENGGUNA (PROFIL & JAWABAN KUESIONER)
**Profil Pengguna:**
{$userProfileString}

**Jawaban Lengkap Kuesioner (Gunakan ini untuk mengidentifikasi kontributor risiko dan personalisasi):**
{$originalInputsString}

## 2.2 HASIL ANALISIS KLINIS (SUDAH FINAL)
Ini adalah data hasil perhitungan dari model klinis SCORE2. Angka ini adalah FAKTA UTAMA yang harus menjadi pusat dari analisis Anda.
- Model yang Digunakan: {$assessment->model_used}
- Estimasi Risiko 10 Tahun Terkena Penyakit Kardiovaskular: **{$assessment->final_risk_percentage}%**

---
# BAGIAN 3: STRUKTUR OUTPUT JSON (WAJIB)
Berdasarkan SEMUA data di atas, hasilkan laporan komprehensif dalam format JSON yang valid dengan struktur sebagai berikut:
    "riskSummary": {
      "riskPercentage": "{{float}}",
      "riskCategory": {
        "code": "{{LOW|MODERATE|HIGH|VERY_HIGH}}",
        "title": "{{Risiko Rendah/Tinggi/dll}}",
      },
      "executiveSummary": "{{2-3 kalimat menyebutkan persentase + 1-2 faktor utama}}",
      "primaryContributors": [
        {
          "title": "{{Judul Faktor}}",
          "severity": "{{LOW|MEDIUM|HIGH}}",
          "description": "{{Deskripsi kenapa ini faktor utama}}"
        }
      ],
      "contextualRiskExplanation": "{{Penjelasan kenapa risiko segitu untuk usia user}}",
      "positiveFactors": [
        "{{faktor positif 1}}",
        "{{faktor positif 2}}"
      ]
    },
    "actionPlan": {
      "medicalConsultation": {
        "recommendationLevel": {
          "code": "{{ROUTINE|RECOMMENDED|URGENT}}",
          "title": "{{Tingkat Rekomendasi}}",
          "description": "{{Penjelasan mengapa konsultasi perlu}}"
        },
        "suggestedTests": [
          {
            "title": "{{Nama Tes}}",
            "description": "{{Penjelasan singkat tes ini}}"
          }
        ]
      },
      "priorityLifestyleActions": [
        {
          "rank": "{{1-n}}",
          "title": "{{Judul Aksi}}",
          "description": "{{Penjelasan singkat}}",
          "target": "{{Target yang realistis}}",
          "estimatedImpact": "{{Estimasi dampak kuantitatif atau kualitatif}}"
        }
      ],
      "impactSimulation": {
        "message": "{{Kalimat prediksi dampak jika saran dilakukan}}",
        "riskAfterChange": "{{float}}"
      }
    },
    "personalizedEducation": {
      "keyHealthMetrics": [
        {
          "title": "{{Nama Parameter}}",
          "yourValue": "{{Nilai User}}",
          "idealRange": "{{Rentang Ideal}}",
          "code": "{{POOR|FAIR|GOOD}}",
          "description": "{{Penjelasan mengapa penting dan status nilai user}}"
        }
      ],
      "mythVsFact": [
        {
          "myth": "{{Mitos yang sering dipercaya}}",
          "fact": "{{Fakta yang benar secara ilmiah}}"
        }
      ]
    },
    "closingStatement": {
      "motivationalMessage": "{{Kalimat penyemangat untuk user}}",
      "firstStepAction": "{{Langkah konkret dan realistis pertama}}",
      "localContextTip": "{{Saran tambahan yang relevan untuk masyarakat yang ada di daerah atau negara tersebut}}"
    }
  }

---

# BAGIAN 4: BUKU PANDUAN PENGISIAN JSON (UPDATED & FINAL)

## 4.1. riskCategory
Tentukan berdasarkan USIA dan PERSENTASE RISIKO pengguna:

- **Usia < 50**:
  - < 2.5% → LOW_MODERATE
  - 2.5 - 7.49% → HIGH
  - ≥ 7.5% → VERY_HIGH

- **Usia 50–69**:
  - < 5% → LOW_MODERATE
  - 5 - 9.99% → HIGH
  - ≥ 10% → VERY_HIGH

- **Usia ≥ 70**:
  - < 10% → MODERATE
  - 10 - 19.99% → HIGH
  - ≥ 20% → VERY_HIGH

## 4.2. executiveSummary
Sebutkan kategori risiko dan persentase, lalu jelaskan dalam 2-3 kalimat empatik yang mengaitkan 1–2 penyebab utama dari jawaban pengguna.

## 4.3. primaryContributors
Ambil dari jawaban kuesioner (gaya hidup atau gejala), pilih 2–3 faktor dominan, dan jelaskan dengan bahasa awam namun tegas.

## 4.4. contextualRiskExplanation
Berikan narasi edukatif:  
“Risiko sebesar 20% berarti dari 100 orang dengan profil serupa, 20 akan terkena serangan jantung atau stroke dalam 10 tahun. Ini tergolong tinggi karena secara klinis dapat dicegah melalui intervensi sejak dini.”

## 4.5. positiveFactors
Tampilkan 2–3 kebiasaan baik pengguna. Beri apresiasi dan motivasi melanjutkan.

## 4.6. medicalConsultation.recommendationLevel
Gunakan logika berikut:
- LOW_MODERATE → GENERAL_ADVICE
- HIGH → CONSIDER_INTERVENTION
- VERY_HIGH → URGENT_INTERVENTION

Sesuaikan `description` dan `title` dengan tingkat urgensi.

## 4.7. suggestedTests
Minimal 1 tes penting (misal: lipid_panel, ekg, treadmill test). Jelaskan fungsinya dan kapan sebaiknya dilakukan.

## 4.8. priorityLifestyleActions
Pilih 2–3 perubahan gaya hidup prioritas (berhenti merokok, turunkan tekanan darah, perbaiki pola makan). Tambahkan `target` konkret.  
Contoh: “Kurangi tekanan darah ke <135 mmHg dalam 3 bulan.”

## 4.9. impactSimulation
Simulasikan penurunan risiko jika saran utama dijalankan. Contoh:  
“Jika Anda berhenti merokok, risiko Anda dapat turun 50% dalam setahun.”

## 4.10. keyHealthMetrics
Ambil 2–3 dari `generated_values` seperti tekanan darah, kolesterol, dan denyut jantung maksimal. Berikan nilai pengguna dan idealnya, jangan lupa kategorinya berdasarkan perbandingan antara nilai pengguna dan idealnya.

## 4.11. mythVsFact
Ambil mitos relevan dari kebiasaan pengguna. Contoh jika pengguna merokok:  
Mitos: “Vape lebih aman dari rokok.”  
Fakta: “Vape tetap mengandung nikotin dan bahan kimia…”
Jangan memasukkan seperti "Fakta:" atau "Mitos:" dalam kalimatnya

## 4.12. closingStatement
- **motivationalMessage:** positif, meyakinkan bahwa perubahan bisa dimulai dari sekarang.
- **firstStepAction:** sederhana, mudah dilakukan minggu ini.

PROMPT;

    return $prompt;
  }

  /**
   * Mem-parsing respons string JSON dari Gemini menjadi array PHP.
   */
  private function parseGeminiResponse(string $textResponse): array
  {
    try {
      $cleanedResponse = trim($textResponse);
      if (str_starts_with($cleanedResponse, '```json')) {
        $cleanedResponse = preg_replace('/^```json\s*|\s*```$/', '', $cleanedResponse);
        $cleanedResponse = trim($cleanedResponse);
      }
      return json_decode($cleanedResponse, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      Log::error("Gagal mem-parsing JSON dari Gemini.", [
        'error' => $e->getMessage(),
        'response_snippet' => substr($textResponse, 0, 500)
      ]);
      throw new Exception("Gagal memproses respons dari layanan AI.");
    }
  }
}
