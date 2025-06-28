<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoachingThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'coaching_program_id',
        'slug',
        'title'
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Relasi: Thread ini adalah bagian dari satu CoachingProgram.
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(CoachingProgram::class, 'coaching_program_id');
    }

    /**
     * Relasi: Satu thread memiliki banyak 'Pesan'.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(CoachingMessage::class);
    }
}
