<?php

namespace App\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserProfile extends Model
{
    use HasFactory, Cacheable;

    /**
     * Atribut yang dapat diisi secara massal (mass assignable).
     */
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'sex',
        'country_of_residence',
        'language',
    ];

    /**
     * Mendefinisikan relasi bahwa profil ini "dimiliki oleh" satu User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mendefinisikan relasi bahwa satu profil bisa memiliki "banyak" riwayat analisis.
     */
    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class);
    }
    /**
     * Mendefinisikan relasi bahwa satu profil bisa memiliki "banyak" pesan chat.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * [BARU] Atribut dinamis untuk secara otomatis mendapatkan risk_region.
     * Ini memungkinkan kita memanggil $profile->risk_region di mana saja.
     *
     * @return string
     */
    public function getRiskRegionAttribute(): string
    {
        // Muat kamus pemetaan dari file config
        $mapping = config('region_mapping');

        // Ambil negara pengguna dan standarisasi ke huruf kecil
        $userCountry = strtolower(trim($this->country_of_residence));

        // Cari negara pengguna di dalam setiap kategori risiko
        foreach ($mapping as $region => $countries) {
            if (in_array($userCountry, $countries)) {
                return $region; // Mengembalikan 'low', 'moderate', 'high', atau 'very_high'
            }
        }

        // Jika negara pengguna tidak ada dalam daftar kita, berikan nilai default yang aman
        return 'high'; // Atau 'moderate', sesuai kebijakan Anda
    }
}
