<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoachingMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'role' => $this->role,
            // Secara otomatis decode content jika ia adalah JSON (balasan AI)
            'content' => json_decode($this->content) ?? $this->content,
            'created_at_human' => $this->created_at->diffForHumans(),
        ];
    }
}
