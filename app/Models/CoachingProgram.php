<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoachingProgram extends Model
{
    use HasFactory;

    /**
     * Atribut yang dapat diisi secara massal (mass assignable).
     * Lebih aman daripada $guarded = [].
     */
    protected $fillable = [
        'user_profile_id',
        'risk_assessment_id',
        'slug',
        'title',
        'description',
        'difficulty',
        'status',
        'start_date',
        'end_date',
        'graduation_report'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'graduation_report' => 'array'
    ];

    /**
     * Mengubah kunci rute default dari 'id' menjadi 'slug' untuk URL yang lebih baik.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Relasi: Program ini dimiliki oleh satu UserProfile.
     */
    public function userProfile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class);
    }

    /**
     * Relasi: Program ini berasal dari satu RiskAssessment.
     */
    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class, 'risk_assessment_id');
    }

    /**
     * Relasi: Satu program memiliki banyak 'Fokus Mingguan'.
     */
    public function weeks(): HasMany
    {
        return $this->hasMany(CoachingWeek::class);
    }

    /**
     * Relasi: Satu program memiliki banyak 'Thread Percakapan'.
     */
    public function threads(): HasMany
    {
        return $this->hasMany(CoachingThread::class);
    }
}
