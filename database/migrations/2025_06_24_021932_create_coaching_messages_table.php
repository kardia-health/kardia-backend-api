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
        Schema::create('coaching_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coaching_thread_id')->constrained()->onDelete('cascade');
            $table->enum('role', ['user', 'ai_coach']);
            $table->json('content');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coaching_messages');
    }
};
