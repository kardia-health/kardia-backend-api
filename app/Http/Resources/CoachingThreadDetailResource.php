<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoachingThreadDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            // Gunakan resource baru untuk memformat setiap pesan
            'messages' => CoachingMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
