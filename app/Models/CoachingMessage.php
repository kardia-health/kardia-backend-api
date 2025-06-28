<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachingMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'coaching_thread_id',
        'role',
        'content'
    ];

    protected $casts = [
        'content' => 'array', // Otomatis ubah JSON ke/dari array
    ];

    protected $touches = ['thread'];

    /**
     * Relasi: Pesan ini adalah bagian dari satu CoachingThread.
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(CoachingThread::class, 'coaching_thread_id');
    }
}
