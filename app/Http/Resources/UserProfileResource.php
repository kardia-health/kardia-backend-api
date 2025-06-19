<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // '$this' di sini merujuk pada instance model UserProfile yang dikirim
        return [
            // Mengambil email dari relasi 'user' yang terhubung
            'email' => $this->user->email,

            // Mengambil data langsung dari model UserProfile
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'sex' => $this->sex,
            'country_of_residence' => $this->country_of_residence,
            'risk_region' => $this->risk_region, // Diambil otomatis dari accessor
            
            // Memformat tanggal lahir sesuai permintaan Anda (dd/mm/yyyy)
            'date_of_birth' => Carbon::parse($this->date_of_birth)->format('d/m/Y'),
            'age' => Carbon::parse($this->date_of_birth)->age,

            'language' => $this->language,
        ];
    }
}
