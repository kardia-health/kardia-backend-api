<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
  /**
   * Define the application's command schedule.
   */
  protected function schedule(Schedule $schedule)
  {
    // Complete expired programs setiap hari jam 00:00 (midnight)
    $schedule->command('programs:complete-expired --force')
      ->dailyAt('00:00')
      ->timezone('Asia/Jakarta')
      ->withoutOverlapping(60) // Prevent overlap, timeout after 60 minutes
      ->onOneServer() // Only run on one server if you have multiple servers
      ->runInBackground() // Run in background to not block other scheduled tasks
      ->sendOutputTo(storage_path('logs/complete-expired-programs.log'));
    // ->emailOutputOnFailure(config('mail.admin_email', 'admin@example.com'));

    // Alternative schedules (uncomment one if needed):

    // Setiap jam untuk testing/development
    // $schedule->command('programs:complete-expired')->hourly();

    // Setiap 6 jam
    // $schedule->command('programs:complete-expired')->everySixHours();

    // Setiap hari jam 2 pagi (less traffic time)
    // $schedule->command('programs:complete-expired')->dailyAt('02:00');

    // Custom cron expression (setiap hari jam 1 malam)
    // $schedule->command('programs:complete-expired')->cron('0 1 * * *');
  }

  /**
   * Register the commands for the application.
   */
  protected function commands()
  {
    $this->load(__DIR__ . '/Commands');

    require base_path('routes/console.php');
  }
}
