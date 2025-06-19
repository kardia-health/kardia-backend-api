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
        Schema::table('chat_messages', function (Blueprint $table) {
            // Hapus foreign key constraint yang lama dulu
            $table->dropForeign(['user_profile_id']);
            // Hapus kolomnya
            $table->dropColumn('user_profile_id');

            // Tambahkan kolom foreign key yang baru setelah kolom 'id'
            $table->foreignId('conversation_id')->after('id')->constrained()->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
            $table->dropColumn('conversation_id');

            // Kembalikan kolom lama jika migrasi dibatalkan
            $table->foreignId('user_profile_id')->after('id')->nullable()->constrained()->onDelete('cascade');
        });
    }
};
