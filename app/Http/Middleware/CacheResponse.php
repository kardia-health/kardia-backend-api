<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CacheResponse
{
    /**
     * Menangani permintaan masuk dan mengimplementasikan cache level HTTP.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  int $minutes Durasi cache dalam menit.
     */
    public function handle(Request $request, Closure $next, int $minutes = 15): Response
    {
        // Hanya cache permintaan GET yang tidak memiliki error
        if ($request->isMethod('get') && !$request->has('error')) {

            // Buat kunci cache yang unik berdasarkan URL lengkap dan ID pengguna
            $user = $request->user();
            // Jika tidak ada user (rute publik), gunakan IP. Jika ada, gunakan ID user.
            $identifier = $user ? "user:{$user->id}" : "ip:{$request->ip()}";
            $cacheKey = "http_response:{$identifier}:" . md5($request->fullUrl());

            // Gunakan Cache::remember untuk mengambil atau menyimpan respons
            return Cache::remember($cacheKey, now()->addMinutes($minutes), function () use ($request, $next, $cacheKey) {

                // Log ini hanya akan berjalan saat CACHE MISS
                Log::info("HTTP CACHE MISS: Generating new response for key: {$cacheKey}");

                // Lanjutkan permintaan ke Controller dan dapatkan responsnya
                return $next($request);
            });
        }

        // Jika bukan permintaan GET, lanjutkan seperti biasa tanpa caching
        return $next($request);
    }
}
