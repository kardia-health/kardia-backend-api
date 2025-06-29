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
        Schema::create('daily_meal_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_profile_id')->constrained()->onDelete('cascade');
            $table->date('guide_date');
            $table->json('generation_context');
            $table->json('guide_data');
            $table->boolean('is_chosen')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_meal_guides');
    }
};
