<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Trait ini memberikan kemampuan caching otomatis pada sebuah Model Eloquent.
 * Ia akan secara otomatis meng-cache data saat dibaca via findAndCache()
 * dan menghapus cache saat data di-update atau di-delete.
 */
trait Cacheable
{
  /**
   * Boot method untuk Trait. Dijalankan otomatis oleh Laravel.
   * Di sinilah kita mendaftarkan 'event listeners' pada model.
   */
  public static function bootCacheable(): void
  {
    // Event ini terpicu setiap kali model berhasil di-save (baik create maupun update)
    static::saved(function ($model) {
      Log::info("CACHE FORGET [EVENT]: Menghapus cache untuk " . $model->getCacheKey());
      Cache::forget($model->getCacheKey());
    });

    // Event ini terpicu setiap kali model di-delete
    static::deleted(function ($model) {
      Log::info("CACHE FORGET [EVENT]: Menghapus cache untuk " . $model->getCacheKey());
      Cache::forget($model->getCacheKey());
    });
  }

  /**
   * Metode publik baru untuk mencari model berdasarkan ID, dengan caching.
   * Ini adalah pengganti untuk ::find($id).
   */
  public static function findAndCache(int|string $id)
  {
    $cacheKey = (new static)->getCacheKey($id);

    // Ambil dari cache selamanya. Invalidasi akan ditangani oleh event 'saved' & 'deleted'.
    return Cache::rememberForever($cacheKey, function () use ($id, $cacheKey) {
      Log::info("CACHE MISS [MODEL]: Mengambil dari DB untuk key: {$cacheKey}");
      // Jika tidak ada di cache, cari di database, dan eager load relasi user-nya.
      return static::with('user')->find($id);
    });
  }

  /**
   * Helper untuk mendapatkan nama kunci cache yang konsisten.
   * Contoh: 'userprofile:1'
   */
  public function getCacheKey(int|string $id = null): string
  {
    $id = $id ?? $this->getKey();
    return strtolower(class_basename($this)) . ':' . $id;
  }
}
