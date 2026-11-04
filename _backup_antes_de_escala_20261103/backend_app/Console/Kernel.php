<?php

// Kernel de consola de la aplicacion. Define la programacion de tareas y carga los comandos Artisan.

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Limpieza semanal de backups viejos (corre si hay un cron con schedule:run).
        $schedule->command('backups:prune --dias=30')->weekly()->sundays()->at('03:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
