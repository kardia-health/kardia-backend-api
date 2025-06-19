<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;


    /**
     * Atribut yang dapat diisi secara massal.
     */
    protected $fillable = [
        'user_profile_id',
        'title',
        'slug'
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Mendefinisikan relasi bahwa percakapan ini "dimiliki oleh" satu UserProfile.
     * Nama fungsi: userProfile()
     */
    public function userProfile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class);
    }

    /**
     * Mendefinisikan relasi bahwa satu percakapan "memiliki banyak" ChatMessage.
     * Nama fungsi: chatMessages()
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }
}
