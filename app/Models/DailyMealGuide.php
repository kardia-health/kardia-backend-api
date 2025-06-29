<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyMealGuide extends Model
{
    use HasFactory;

    /**
     * Kolom yang diizinkan untuk diisi secara massal.
     */
    protected $fillable = [
        'user_profile_id',
        'guide_date',
        'generation_context',
        'guide_data',
        'is_chosen',
    ];

    /**
     * Otomatisasi tipe data. Sangat penting untuk kolom JSON dan date.
     */
    protected $casts = [
        'guide_date' => 'date',
        'generation_context' => 'array',
        'guide_data' => 'array',
        'is_chosen' => 'boolean',
    ];

    /**
     * Relasi bahwa setiap panduan dimiliki oleh satu UserProfile.
     */
    public function userProfile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class);
    }
}
