<?php

namespace App\Listeners;

use App\Events\UserDashboardShouldUpdate;
use App\Repositories\ConversationRepository;
use Illuminate\Support\Facades\Log;

class ClearUserConversationListCache
{
    /**
     * Inject repository yang akan kita gunakan.
     */
    public function __construct(private ConversationRepository $conversationRepository)
    {
        // Hapus semua yang ada di sini - constructor cukup inject repository saja
    }

    /**
     * Menangani event dan menjalankan logika pembersihan cache.
     *
     * @param  \App\Events\UserDashboardShouldUpdate  $event
     * @return void
     */
    public function handle(UserDashboardShouldUpdate $event): void
    {
        // User sudah ada di event, tidak perlu di-inject
        $user = $event->user;

        Log::info("LISTENER TRIGGERED: Membersihkan cache daftar percakapan umum untuk user ID: {$user->id}");

        $this->conversationRepository->forgetUserConversationsCache($user);
    }
}
