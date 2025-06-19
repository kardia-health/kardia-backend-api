<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Coba decode konten, jika gagal (artinya itu pesan user biasa), kembalikan teks asli
        $content = json_decode($this->content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $content = $this->content;
        }

        return [
            'id' => $this->id,
            'role' => $this->role, // 'user' or 'model'
            'content' => $content, // Bisa string (untuk user) atau array (untuk AI)
            'sent_at' => $this->created_at->toDateTimeString(),
        ];
    }
}
