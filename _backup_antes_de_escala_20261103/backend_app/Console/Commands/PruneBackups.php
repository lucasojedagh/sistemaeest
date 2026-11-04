<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

// Borra backups viejos para que no llenen el disco.
// - Solo archivos .sql y .zip dentro de storage/app/backups.
// - Conserva SIEMPRE los 3 más nuevos, aunque superen la antigüedad.
// - Antigüedad por defecto: 30 días (--dias=N para cambiar).
// - Con --dry-run solo informa sin borrar.
class PruneBackups extends Command
{
    protected $signature = 'backups:prune
        {--dias=30 : Borra backups con más de N días}
        {--dry-run : Solo muestra lo que se borraría}';

    protected $description = 'Borra archivos de backup viejos conservando los 3 más nuevos';

    public function handle(): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $dryRun = (bool) $this->option('dry-run');
        $dir = 'backups';

        if (!Storage::disk('local')->exists($dir)) {
            $this->info('No hay carpeta de backups.');
            return self::SUCCESS;
        }

        $archivos = collect(Storage::disk('local')->files($dir))
            ->filter(fn ($f) => preg_match('/\.(sql|zip)$/i', $f))
            ->map(fn ($f) => ['ruta' => $f, 'ts' => Storage::disk('local')->lastModified($f)])
            ->sortByDesc('ts')
            ->values();

        // Los 3 más nuevos no se tocan nunca.
        $candidatos = $archivos->slice(3)->values();
        $corte = time() - $dias * 86400;
        $viejos = $candidatos->filter(fn ($a) => $a['ts'] < $corte)->values();

        if ($viejos->isEmpty()) {
            $this->info("Nada para borrar ({$archivos->count()} archivos, se conservan los 3 más nuevos).");
            return self::SUCCESS;
        }

        foreach ($viejos as $a) {
            if ($dryRun) {
                $this->line("[dry-run] Borraría: {$a['ruta']}");
            } else {
                Storage::disk('local')->delete($a['ruta']);
                $this->line("Borrado: {$a['ruta']}");
            }
        }

        $this->info(($dryRun ? 'Se borrarían ' : 'Borrados ') . $viejos->count() . ' archivos.');
        return self::SUCCESS;
    }
}
