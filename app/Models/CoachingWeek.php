<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoachingWeek extends Model
{
    use HasFactory;

    protected $fillable = [
        'coaching_program_id',
        'week_number',
        'title',
        'description',
    ];

    /**
     * Relasi: Minggu ini adalah bagian dari satu CoachingProgram.
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(CoachingProgram::class, 'coaching_program_id');
    }

    /**
     * Relasi: Satu minggu memiliki banyak 'Tugas Harian'.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(CoachingTask::class);
    }
}
