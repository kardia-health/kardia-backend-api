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
        Schema::create('coaching_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coaching_program_id')->constrained()->onDelete('cascade');
            $table->string('slug')->unique();
            $table->string('title')->default('Diskusi Program');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coaching_threads');
    }
};
