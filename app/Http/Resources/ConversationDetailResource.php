<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            // Muat dan format semua pesan menggunakan ChatMessageResource
            'messages' => ChatMessageResource::collection($this->whenLoaded('chatMessages')),
        ];
    }
}
