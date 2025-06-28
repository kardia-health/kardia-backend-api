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
        Schema::create('coaching_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_profile_id')->constrained()->onDelete('cascade');
            $table->foreignId('risk_assessment_id')->nullable()->unique()->comment('Analisis awal yang memicu program')->constrained()->onDelete('set null');
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description');
            $table->enum('status', ['active', 'paused', 'completed'])->default('active');
            $table->enum('difficulty', ['Santai & Bertahap', 'Standar & Konsisten', 'Intensif & Menantang']);
            $table->date('start_date');
            $table->date('end_date');
            $table->json('graduation_report')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coaching_programs');
    }
};
