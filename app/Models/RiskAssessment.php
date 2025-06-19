<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessment extends Model
{
    use HasFactory;

    /**
     * Atribut yang dapat diisi secara massal.
     */
    protected $fillable = [
        'user_profile_id',
        'slug',
        'model_used',
        'final_risk_percentage',
        'inputs',
        'generated_values',
        'result_details',
    ];

    /**
     * Otomatis mengubah kolom JSON menjadi array saat diakses.
     */
    protected $casts = [
        'inputs' => 'array',
        'generated_values' => 'array',
        'result_details' => 'array',
    ];

    /**
     * Mendefinisikan relasi bahwa analisis ini "dimiliki oleh" satu UserProfile.
     */
    public function userProfile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class);
    }
}
