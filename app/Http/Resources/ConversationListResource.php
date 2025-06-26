<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;


class ConversationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $snippet = '[Percakapan Baru]';

        // Hanya proses jika relasi 'messages' sudah dimuat dan tidak kosong
        if ($this->relationLoaded('chatMessages') && $this->chatMessages->isNotEmpty()) {

            // 1. Prioritaskan untuk mencari pesan terakhir dari PENGGUNA.
            $lastUserMessage = $this->chatMessages->where('role', 'user')->last();

            if ($lastUserMessage) {
                // 2. Jika ditemukan, gunakan kontennya sebagai snippet.
                //    Pesan user selalu string biasa, tidak perlu decode JSON.
                $text = $lastUserMessage->content;
            } else {
                // 3. FALLBACK: Jika belum ada pesan dari user sama sekali (misal: hanya ada pesan selamat datang dari AI),
                //    ambil pesan terakhir apapun yang ada.
                $lastAiMessage = $this->chatMessages->last();
                $content = $lastAiMessage->content;
                $text = is_array($content)
                    ? ($content['reply_components'][0]['content'] ?? '[Balasan AI]')
                    : $content;
            }

            $snippet = Str::limit($text ?? '', 40);
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
