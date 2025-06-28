<?php

namespace App\Repositories;

use App\Events\CoachingProgramUpdated;
use App\Models\CoachingTask;

/**
 * Class CoachingTaskRepository
 *
 * Repository ini bertanggung jawab untuk mengelola logika bisnis
 * yang terkait dengan model CoachingTask.
 */
class CoachingTaskRepository
{
  // Tidak perlu inject repository lain jika tidak digunakan secara langsung di sini.
  // Event-based system sudah cukup untuk decoupling.
  public function __construct()
  {
    // Konstruktor bisa dikosongkan jika tidak ada dependensi yang perlu di-inject.
  }

  /**
   * Mengubah status sebuah tugas (toggle).
   * Jika tugas sudah selesai (completed), maka akan diubah menjadi belum selesai (incomplete).
   * Jika tugas belum selesai, maka akan diubah menjadi selesai.
   *
   * @param CoachingTask $task Model tugas yang akan diubah statusnya.
   * @return bool Mengembalikan true jika update berhasil, false jika gagal.
   */
  public function toggleStatus(CoachingTask $task): bool
  {
    // 1. Balikkan nilai boolean dari 'is_completed'.
    //    Jika true -> false, jika false -> true.
    $newStatus = !$task->is_completed;

    // 2. Lakukan update pada database dengan status yang baru.
    $result = $task->update(['is_completed' => $newStatus]);

    // 3. [SANGAT PENTING] Jika update ke database berhasil,
    //    kirimkan event untuk memberitahu sistem bahwa ada perubahan.
    //    Event ini akan ditangkap oleh listener yang bertugas menghapus cache
    //    atau melakukan tindakan lain yang diperlukan.
    //    Logika ini sekarang tidak terduplikasi lagi.
    if ($result) {
      CoachingProgramUpdated::dispatch($task->week->program);
    }

    // 4. Kembalikan hasil dari operasi update.
    return $result;
  }

  /*
     * Metode markAsComplete() dan markAsIncomplete() yang lama
     * sudah tidak diperlukan lagi dan dapat dihapus dengan aman,
     * karena fungsionalitasnya telah digantikan oleh toggleStatus().
     */
  // public function markAsComplete(CoachingTask $task): bool { ... }
  // public function markAsIncomplete(CoachingTask $task): bool { ... }
}
