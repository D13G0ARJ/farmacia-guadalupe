<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Respaldo diario de la base de datos (§15.2) sin paquetes extra: `mysqldump` comprimido en MySQL,
 * `VACUUM INTO` en SQLite. Conserva N días en storage/app/backups; el hosting copia esa carpeta afuera.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep=30 : Días de respaldos que se conservan} {--path= : Carpeta destino (por defecto storage/app/backups)} {--connection= : Conexión a respaldar (por defecto la principal)}';

    protected $description = 'Respalda la base de datos y borra los respaldos viejos';

    public function handle(): int
    {
        $dir = (string) ($this->option('path') ?: storage_path('app/backups'));
        File::ensureDirectoryExists($dir);
        $stamp = CarbonImmutable::now()->format('Y-m-d-His');
        $connection = (string) ($this->option('connection') ?: config('database.default'));
        $driver = (string) config("database.connections.{$connection}.driver");

        try {
            $file = match ($driver) {
                'sqlite' => $this->sqlite($dir, $stamp, $connection),
                'mysql', 'mariadb' => $this->mysql($dir, $stamp, $connection),
                default => throw new RuntimeException("Conexión {$connection} ({$driver}) sin respaldo automático."),
            };
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $removed = $this->prune($dir, (int) $this->option('keep'));
        $this->info('Respaldo creado: '.$file.' ('.number_format(File::size($file) / 1024).' KB)'.($removed > 0 ? " · {$removed} respaldo(s) viejo(s) borrado(s)" : ''));

        return self::SUCCESS;
    }

    private function sqlite(string $dir, string $stamp, string $connection): string
    {
        $file = "{$dir}/indicadores-{$stamp}.sqlite";
        // VACUUM INTO copia la base consistente aunque esté en uso (SQLite 3.27+); no admite transacción abierta.
        DB::connection($connection)->statement("VACUUM INTO '".str_replace(['\\', "'"], ['/', "''"], $file)."'");

        return $file;
    }

    private function mysql(string $dir, string $stamp, string $connection): string
    {
        $config = (array) config("database.connections.{$connection}");
        $file = "{$dir}/indicadores-{$stamp}.sql.gz";
        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --single-transaction --quick --routines %s | gzip > %s',
            escapeshellarg((string) ($config['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($config['port'] ?? 3306)),
            escapeshellarg((string) ($config['username'] ?? '')),
            escapeshellarg((string) ($config['database'] ?? '')),
            escapeshellarg($file),
        );

        // La contraseña va por variable de entorno: no aparece en la lista de procesos.
        $result = Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])->timeout(600)->run($command);
        if (! $result->successful() || ! File::exists($file) || File::size($file) === 0) {
            File::delete($file);
            throw new RuntimeException('mysqldump falló: '.trim($result->errorOutput()));
        }

        return $file;
    }

    private function prune(string $dir, int $keepDays): int
    {
        $limit = CarbonImmutable::now()->subDays(max(1, $keepDays));
        $removed = 0;
        foreach (File::files($dir) as $file) {
            if (str_starts_with($file->getFilename(), 'indicadores-') && CarbonImmutable::createFromTimestamp($file->getMTime())->lt($limit)) {
                File::delete($file->getPathname());
                $removed++;
            }
        }

        return $removed;
    }
}
