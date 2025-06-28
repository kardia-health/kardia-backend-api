<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class CoachingTask extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'coaching_week_id',
        'task_date',
        'task_type',
        'title',
        'description',
        'is_completed',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
    ];

    /**
     * Relasi: Tugas ini adalah bagian dari satu CoachingWeek.
     */
    public function week(): BelongsTo
    {
        return $this->belongsTo(CoachingWeek::class, 'coaching_week_id');
    }
}
