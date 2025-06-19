<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;


class ConversationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Ambil pesan terakhir untuk membuat cuplikan (snippet)
        $lastMessage = $this->chatMessages()->latest()->first();
        $snippet = '[Percakapan baru]';
        if ($lastMessage) {
            // Jika balasan AI (JSON), coba decode. Jika tidak, ambil konten mentah.
            $content = json_decode($lastMessage->content, true);
            $text = $content['reply_components'][0]['content'] ?? $lastMessage->content;
            $snippet = Str::limit($text, 40); // Batasi 40 karakter
        }

        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'last_message_snippet' => $snippet,
            // Format tanggal menjadi "1 jam yang lalu", "Kemarin", dll.
            'last_updated_human' => $this->updated_at->diffForHumans(),
        ];
    }
}
