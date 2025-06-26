<?php

namespace App\Services;

use App\Models\UserProfile;
use Carbon\Carbon;
use Exception;

/**
 * Class ClinicalRiskService
 * Merupakan "otak" utama dari aplikasi Kardia. Bertanggung jawab untuk:
 * 1. Mengelola alur input fleksibel (manual vs proksi).
 * 2. Menjalankan mesin inferensi untuk menghasilkan nilai proksi klinis jika diperlukan.
 * 3. Memilih dan menjalankan model kalkulator risiko yang sesuai (SCORE2, SCORE2-OP, SCORE2-Diabetes).
 * 4. Mengembalikan hasil risiko final yang sudah terkalibrasi.
 */
class ClinicalRiskService
{
  /**
   * @var array Menyimpan semua konstanta model dari file config.
   */
  private array $config;
  private array $regionMapping;


  /**
   * Muat semua konstanta matematis saat service diinisialisasi.
   */
  public function __construct()
  {
    $this->config = config('score_models');
  }

  /**
   * Metode publik utama yang mengatur seluruh proses kalkulasi.
   * @param array $answers Data yang sudah divalidasi dari KardiaRiskRequest.
   * @return array Hasil akhir kalkulasi.
   * @throws Exception
   */
  public function processRiskCalculation(array $answers, UserProfile $profile): array
  {
    // 1. Dapatkan Wilayah Risiko secara Otomatis dari Accessor!
    $riskRegion = $profile->risk_region;

    // 2. Siapkan satu set nilai klinis yang seragam, baik dari input manual maupun hasil inferensi proksi.
    $clinicalValues = $this->prepareClinicalValues($answers, $profile);

    // 3. Pilih & jalankan model SCORE2 yang sesuai berdasarkan data inti.
    $modelUsed = '';
    $finalRiskPercent = 0.0;

    if ($clinicalValues['has_diabetes']) {
      $modelUsed = 'SCORE2-Diabetes';
      $finalRiskPercent = $this->calculateScore2Diabetes($clinicalValues, $riskRegion);
    } elseif ($clinicalValues['age'] >= 70) {
      $modelUsed = 'SCORE2-OP';
      $finalRiskPercent = $this->calculateScore2Op($clinicalValues, $riskRegion);
    } else {
      $modelUsed = 'SCORE2';
      $finalRiskPercent = $this->calculateScore2($clinicalValues, $riskRegion);
    }

    // 4. Kembalikan hasil akhir yang lengkap untuk ditampilkan di frontend.
    return [
      "determined_risk_region" => $riskRegion,
      'model_used' => $modelUsed,
      'calibrated_10_year_risk_percent' => $finalRiskPercent,
      'final_clinical_inputs' => $clinicalValues, // Opsional: untuk transparansi/debug
    ];
  }

  /**
   * Mengatur data klinis berdasarkan pilihan input pengguna (manual atau proksi).
   * @param array $answers Data input mentah dari pengguna.
   * @return array Set nilai klinis yang seragam.
   */
  private function prepareClinicalValues(array $answers, UserProfile $profile): array
  {
    $values = [];

    // Data inti yang selalu ada
    $values['age'] = Carbon::parse($profile->date_of_birth)->age;
    $values['sex_label'] = $profile->sex; // 'male' or 'female'

    $values['is_smoker'] = ($answers['smoking_status'] === 'Perokok aktif');
    $values['has_diabetes'] = (bool)$answers['has_diabetes'];

    // Proses setiap parameter secara modular
    $values['sbp'] = ($answers['sbp_input_type'] == 'manual') ? (float)$answers['sbp_value'] : $this->estimateSbp($answers, $profile);
    $values['tchol'] = ($answers['tchol_input_type'] == 'manual') ? (float)$answers['tchol_value'] : $this->estimateTotalChol($answers);
    $values['hdl'] = ($answers['hdl_input_type'] == 'manual') ? (float)$answers['hdl_value'] : $this->estimateHdl($answers, $profile);

    if ($values['has_diabetes']) {
      $values['age_at_diabetes_diagnosis'] = (int)$answers['age_at_diabetes_diagnosis'];
      $values['hba1c'] = ($answers['hba1c_input_type'] == 'manual') ? (float)$answers['hba1c_value'] : $this->estimateHba1c($answers['hba1c_proxy_answers'] ?? [], $answers, $profile);
      $values['scr'] = ($answers['scr_input_type'] == 'manual') ? (float)$answers['scr_value'] : $this->estimateScr($answers['scr_proxy_answers'] ?? [], $answers, $profile);
    }

    return $values;
  }

  // ===================================================================
  // KUMPULAN METODE INFERENSI PROKSI ("BANTU ESTIMASI") - EDISI PAKAR
  // ===================================================================

  private function estimateSbp(array $allAnswers, UserProfile $profile): int
  {
    $proxyAns = $allAnswers['sbp_proxy_answers'] ?? [];
    $sbp = 110 + (($profile->age - 25) * 0.45);
    if ($profile->sex == 'male') $sbp += 5;

    if (($proxyAns['q_fam_htn'] ?? 'Tidak') == 'Ya') $sbp += 7;
    if (($proxyAns['q_sleep_pattern'] ?? 'Nyenyak dan teratur') == 'Sulit tidur atau insomnia') $sbp += 7;

    if (isset($proxyAns['q_salt_diet']) && is_array($proxyAns['q_salt_diet'])) {
      $sbp += count($proxyAns['q_salt_diet']) * 5;
    }

    if (($proxyAns['q_stress_response'] ?? '') == 'Jantung berdebar dan wajah panas') $sbp += 10;
    if ($allAnswers['smoking_status'] == 'Perokok aktif') $sbp += 5;
    if (($proxyAns['q_body_shape'] ?? 'Langsing atau ideal') == 'Perut buncit') $sbp += 12;

    if (($proxyAns['q_exercise'] ?? 'Jarang') == 'Rutin & Intens') $sbp -= 7;

    return (int) round($sbp);
  }

  private function estimateTotalChol(array $allAnswers): float
  {
    $proxyAns = $allAnswers['tchol_proxy_answers'] ?? [];
    $chol = 4.0;

    if (($proxyAns['q_fam_chol_heart_attack'] ?? 'Tidak') == 'Ya') $chol += 0.7;
    if (($proxyAns['q_cooking_oil'] ?? '') == 'Minyak kelapa sawit atau minyak goreng curah') $chol += 1.2;
    if (($proxyAns['q_exercise_type'] ?? '') == 'Hampir tidak pernah') $chol += 0.5;
    if (($proxyAns['q_xanthoma'] ?? 'Tidak') == 'Ya') $chol += 3.0;
    if ($allAnswers['smoking_status'] === 'Perokok aktif') $chol += 0.4;

    if (($proxyAns['q_fish_intake'] ?? '') == '2 kali seminggu atau lebih') $chol -= 0.3;

    return round($chol, 2);
  }

  private function estimateHdl(array $allAnswers, UserProfile $profile): float
  {
    $proxyAns = $allAnswers['hdl_proxy_answers'] ?? [];
    $hdl = ($profile->sex == 'female') ? 1.3 : 1.1;

    $exerciseType = $proxyAns['q_exercise_type'] ?? 'Hampir tidak pernah';
    if ($exerciseType == 'Angkat beban atau HIIT') $hdl += 0.3;
    elseif ($exerciseType == 'Rutin tapi ringan (jalan kaki)') $hdl += 0.1;
    else $hdl -= 0.2;

    if ($allAnswers['smoking_status'] === 'Perokok aktif') $hdl -= 0.25;

    if (($proxyAns['q_fish_intake'] ?? 'Jarang') == '2 kali seminggu atau lebih') $hdl += 0.15;

    return round($hdl, 2);
  }

  private function estimateScr(array $proxyAns, array $allAnswers, UserProfile $profile): float
  {
    $base_scr = ($profile->sex == 'female') ? 0.7 : 0.9;
    switch ($proxyAns['q_body_type_for_scr'] ?? 'Rata-rata') {
      case 'Sangat berotot':
        $base_scr *= 1.20;
        break;
      case 'Cukup berotot atau atletis':
        $base_scr *= 1.10;
        break;
      case 'Cenderung kurus atau sedikit lemak':
        $base_scr *= 0.90;
        break;
    }

    $damage_points = 0.0;
    if (($proxyAns['q_diabetes_control_scr'] ?? '') == 'Kurang terkontrol') $damage_points += 0.4;
    if (($proxyAns['q_retinopathy_neuropathy'] ?? 'Tidak') == 'Ya') $damage_points += 0.3;

    $stressor_points = 0.0;
    if ($allAnswers['smoking_status'] === 'Perokok aktif') $stressor_points += 0.1;
    if (($proxyAns['q_nsaid_use_scr'] ?? 'Jarang') == 'Sering') $stressor_points += 0.15;
    if (($proxyAns['q_foamy_urine_scr'] ?? 'Tidak pernah') == 'Ya, sering') $stressor_points += 0.25;

    $final_scr = $base_scr + $damage_points + $stressor_points;
    return round(max($base_scr, min(4.0, $final_scr)), 2);
  }

  private function estimateHba1c(array $proxyAns, array $allAnswers): int
  {
    $hba1c = 65; // Default untuk yang tidak monitor

    $smbg = $proxyAns['q_smbg_monitoring'] ?? 'Tidak pernah sama sekali';
    if ($smbg == 'Ya, dan hasilnya seringkali sesuai target dokter.') $hba1c = 53;
    elseif ($smbg == 'Ya, tapi hasilnya seringkali di atas target.') $hba1c = 75;

    $adherence = $proxyAns['q_adherence'] ?? 'Kurang disiplin pada keduanya';
    if ($adherence == 'Disiplin pada obat, tapi sering melanggar diet') $hba1c += 10;
    elseif ($adherence == 'Sering lupa minum obat, tapi diet cukup disiplin') $hba1c += 15;
    elseif ($adherence == 'Kurang disiplin pada keduanya') $hba1c += 25;

    if (($allAnswers['q_exercise'] ?? 'Jarang') == 'Rutin & Intens (lari/HIIT)') $hba1c -= 7;

    return (int) round(max(42, min(160, $hba1c)));
  }

  // ===================================================================
  // KUMPULAN METODE KALKULATOR SCORE2, OP, DIABETES, eGFR, dan KALIBRASI
  // (Kode ini adalah implementasi presisi dari formula yang Anda berikan)
  // ===================================================================

  /**
   * Menghitung risiko untuk model SCORE2 standar (usia 40-69, non-diabetes).
   * @param array $values Set data klinis (baik manual maupun proksi).
   * @param string $riskRegion Wilayah risiko pengguna.
   * @return float Hasil risiko terkalibrasi dalam persen.
   */
  private function calculateScore2(array $values, string $riskRegion): float
  {
    $sex = $values['sex_label'];
    $coef = $this->config['score2']['coefficients'][$sex];

    // 1. Transformasi variabel
    $cage = ($values['age'] - 60) / 5;
    $csbp = ($values['sbp'] - 120) / 20;
    $ctchol = $values['tchol'] - 6;
    $chdl = ($values['hdl'] - 1.3) / 0.5;
    $smoking = $values['is_smoker'] ? 1 : 0;

    // 2. Hitung linear predictor (x)
    $x = ($coef['age'] * $cage) +
      ($coef['smoking'] * $smoking) +
      ($coef['sbp'] * $csbp) +
      ($coef['tchol'] * $ctchol) +
      ($coef['hdl'] * $chdl) +
      ($coef['smoking_age'] * $smoking * $cage) +
      ($coef['sbp_age'] * $csbp * $cage) +
      ($coef['tchol_age'] * $ctchol * $cage) +
      ($coef['hdl_age'] * $chdl * $cage);

    // 3. Hitung risiko mentah (uncalibrated)
    $baselineSurvival = $this->config['score2']['baseline_survival'][$sex];
    $uncalibratedRisk = 1 - pow($baselineSurvival, exp($x));

    // 4. Terapkan kalibrasi dan kembalikan hasil
    return $this->applyCalibration($uncalibratedRisk, $riskRegion, $sex, 'score2');
  }

  /**
   * Menghitung risiko untuk model SCORE2-OP (usia >= 70, non-diabetes).
   * @param array $values Set data klinis (baik manual maupun proksi).
   * @param string $riskRegion Wilayah risiko pengguna.
   * @return float Hasil risiko terkalibrasi dalam persen.
   */
  private function calculateScore2Op(array $values, string $riskRegion): float
  {
    $sex = $values['sex_label'];
    $coef = $this->config['score2_op']['coefficients'][$sex];

    // 1. Transformasi variabel
    $cage = $values['age'] - 73;
    $csbp = $values['sbp'] - 150;
    $ctchol = $values['tchol'] - 6;
    $chdl = $values['hdl'] - 1.4;
    $smoking = $values['is_smoker'] ? 1 : 0;
    $diabetes = $values['has_diabetes'] ? 1 : 0; // Akan selalu 0 di sini, tapi disertakan untuk kelengkapan formula

    // 2. Hitung linear predictor (x)
    $x = ($coef['age'] * $cage) +
      ($coef['diabetes'] * $diabetes) +
      ($coef['smoking'] * $smoking) +
      ($coef['sbp'] * $csbp) +
      ($coef['tchol'] * $ctchol) +
      ($coef['hdl'] * $chdl) +
      ($coef['diabetes_age'] * $diabetes * $cage) +
      ($coef['smoking_age'] * $smoking * $cage) +
      ($coef['sbp_age'] * $csbp * $cage) +
      ($coef['tchol_age'] * $ctchol * $cage) +
      ($coef['hdl_age'] * $chdl * $cage);

    // 3. Hitung risiko mentah (uncalibrated) dengan Mean Linear Predictor (MLP)
    $baselineSurvival = $this->config['score2_op']['baseline_survival'][$sex];
    $mlp = $this->config['score2_op']['mean_linear_predictor'][$sex];
    $uncalibratedRisk = 1 - pow($baselineSurvival, exp($x - $mlp));

    // 4. Terapkan kalibrasi dan kembalikan hasil
    return $this->applyCalibration($uncalibratedRisk, $riskRegion, $sex, 'score2_op');
  }

  /**
   * Menghitung risiko untuk model SCORE2-Diabetes.
   * @param array $values Set data klinis (baik manual maupun proksi).
   * @param string $riskRegion Wilayah risiko pengguna.
   * @return float Hasil risiko terkalibrasi dalam persen.
   * @throws \Exception
   */
  private function calculateScore2Diabetes(array $values, string $riskRegion): float
  {
    $sex = $values['sex_label'];
    $coef = $this->config['score2_diabetes']['coefficients'][$sex];

    // 1. Hitung eGFR dulu karena dibutuhkan untuk transformasi
    $egfr = $this->calculateEgfr($values['scr'], $values['age'], $sex);

    // 2. Transformasi variabel
    $cage = ($values['age'] - 60) / 5;
    $csbp = ($values['sbp'] - 120) / 20;
    $ctchol = $values['tchol'] - 6;
    $chdl = ($values['hdl'] - 1.3) / 0.5;
    $smoking = $values['is_smoker'] ? 1 : 0;
    $diabetes = 1; // Selalu 1 untuk model ini
    $cagediab = ($values['age_at_diabetes_diagnosis'] - 50) / 5;
    $ca1c = ($values['hba1c'] - 31) / 9.34;
    $cegfr = (log($egfr) - 4.5) / 0.15;

    // 3. Hitung linear predictor (x)
    $x = ($coef['age'] * $cage) + ($coef['smoking'] * $smoking) + ($coef['sbp'] * $csbp) +
      ($coef['diabetes'] * $diabetes) + ($coef['tchol'] * $ctchol) + ($coef['hdl'] * $chdl) +
      ($coef['smoking_age'] * $smoking * $cage) + ($coef['sbp_age'] * $csbp * $cage) +
      ($coef['diabetes_age'] * $diabetes * $cage) + ($coef['tchol_age'] * $ctchol * $cage) +
      ($coef['hdl_age'] * $chdl * $cage) + ($coef['age_at_diabetes_diagnosis'] * $cagediab) +
      ($coef['hba1c'] * $ca1c) + ($coef['egfr'] * $cegfr) + ($coef['egfr2'] * pow($cegfr, 2)) +
      ($coef['hba1c_age'] * $ca1c * $cage) + ($coef['egfr_age'] * $cegfr * $cage);

    // 4. Hitung risiko mentah (uncalibrated)
    $baselineSurvival = $this->config['score2_diabetes']['baseline_survival'][$sex];
    $uncalibratedRisk = 1 - pow($baselineSurvival, exp($x));

    // 5. Terapkan kalibrasi dan kembalikan hasil
    return $this->applyCalibration($uncalibratedRisk, $riskRegion, $sex, 'score2_diabetes');
  }

  /**
   * Helper untuk menghitung eGFR menggunakan formula 2021 CKD-EPI Creatinine.
   * @param float $scr Serum Creatinine dalam mg/dL
   * @param int $age Usia dalam tahun
   * @param string $sex 'male' atau 'female'
   * @return float eGFR
   */
  private function calculateEgfr(float $scr, int $age, string $sex): float
  {
    $A = 0.0;
    $B = 0.0;

    if ($sex == 'female') {
      $A = 0.7;
      $B = ($scr <= 0.7) ? -0.241 : -1.2;
    } else { // male
      $A = 0.9;
      $B = ($scr <= 0.9) ? -0.302 : -1.2;
    }

    $egfr = 142 * pow($scr / $A, $B) * pow(0.9938, $age);

    if ($sex == 'female') {
      $egfr *= 1.012;
    }

    // Mencegah error log(0) atau log(negatif) di langkah selanjutnya
    return $egfr > 0 ? $egfr : 0.1;
  }

  /**
   * Helper untuk menerapkan formula kalibrasi akhir yang sama untuk semua model.
   * @param float $uncalibratedRisk Risiko mentah (0-1)
   * @param string $riskRegion Wilayah risiko
   * @param string $sex 'male' atau 'female'
   * @param string $modelType 'score2', 'score2_op', atau 'score2_diabetes'
   * @return float Risiko terkalibrasi dalam persen (0-100)
   */
  private function applyCalibration(float $uncalibratedRisk, string $riskRegion, string $sex, string $modelType): float
  {
    // Safety check untuk mencegah error matematis
    if ($uncalibratedRisk >= 1.0) return 100.0;
    if ($uncalibratedRisk <= 0.0) return 0.0;

    $scales = $this->config[$modelType]['calibration_scales'][$riskRegion][$sex];
    $scale1 = $scales[0];
    $scale2 = $scales[1];

    // Formula kalibrasi: 1 – exp(-exp(scale1 + scale2*ln(-ln(1 – risk))))
    $calibratedRisk = 1 - exp(-exp($scale1 + $scale2 * log(-log(1 - $uncalibratedRisk))));

    return round($calibratedRisk * 100, 2);
  }
}
