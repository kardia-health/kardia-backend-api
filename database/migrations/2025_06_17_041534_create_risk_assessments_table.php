<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_profile_id')->constrained()->onDelete('cascade');

            // Menyimpan hasil akhir kalkulasi
            $table->string('model_used'); // Contoh: 'SCORE2-Diabetes'
            $table->float('final_risk_percentage', 5, 2); // Presisi untuk persentase seperti 66.85

            // 'Snapshot' lengkap dari sesi analisis untuk history tracker
            $table->json('inputs'); // Menyimpan SEMUA jawaban asli pengguna (teknis & non-teknis)
            $table->json('generated_values'); // Menyimpan SEMUA nilai proksi/klinis yang digunakan untuk kalkulasi
            $table->json('result_details')->nullable(); // Untuk menyimpan hasil tambahan dari Gemini nanti (summary, action plan)

            $table->timestamps();
            $table->string('slug')->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
