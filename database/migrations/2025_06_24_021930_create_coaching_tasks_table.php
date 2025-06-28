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
        Schema::create('coaching_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('coaching_week_id')->constrained()->onDelete('cascade');
            $table->date('task_date');
            $table->enum('task_type', ['main_mission', 'bonus_challenge']);
            $table->string('title');
            $table->text('description');
            $table->boolean('is_completed')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coaching_tasks');
    }
};
