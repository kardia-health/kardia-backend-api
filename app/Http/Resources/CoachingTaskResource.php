<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoachingTaskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'task_date'    => Carbon::parse($this->task_date)->format('d F Y'),
            'task_type'    => $this->task_type,
            'title'        => $this->title,
            'description'  => $this->description,
            'is_completed' => (bool) $this->is_completed,
        ];
    }
}
