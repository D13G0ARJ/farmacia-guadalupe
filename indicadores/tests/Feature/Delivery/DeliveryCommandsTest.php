<?php

declare(strict_types=1);

use App\Console\Commands\ClearDemoData;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\Goal;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoGoalsSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('toda respuesta web lleva las cabeceras de seguridad (§15.2)', function (): void {
    $response = $this->get('/login');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse(); // solo con HTTPS

    $secure = $this->get('https://localhost/login');
    expect($secure->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
});

it('db:backup crea el respaldo y borra los viejos', function (): void {
    // La conexión de pruebas vive dentro de una transacción (RefreshDatabase) y VACUUM no admite eso:
    // se respalda una base SQLite en archivo, como la de desarrollo.
    $dir = sys_get_temp_dir().'/backups-'.uniqid();
    File::ensureDirectoryExists($dir);
    $source = "{$dir}/origen.sqlite";
    File::put($source, '');
    config()->set('database.connections.backup_test', ['driver' => 'sqlite', 'database' => $source, 'prefix' => '', 'foreign_key_constraints' => false]);
    DB::connection('backup_test')->statement('CREATE TABLE prueba (id INTEGER PRIMARY KEY, texto TEXT)');
    for ($i = 0; $i < 300; $i++) {
        DB::connection('backup_test')->insert('INSERT INTO prueba (texto) VALUES (?)', [str_repeat('x', 100)]);
    }
    $old = "{$dir}/indicadores-2020-01-01-000000.sqlite";
    File::put($old, 'viejo');
    touch($old, CarbonImmutable::now()->subDays(40)->getTimestamp());
    $recent = "{$dir}/indicadores-reciente.sqlite";
    File::put($recent, 'reciente');

    $this->artisan('db:backup', ['--path' => $dir, '--keep' => 30, '--connection' => 'backup_test'])
        ->expectsOutputToContain('Respaldo creado')
        ->assertSuccessful();

    $files = collect(File::files($dir))->map(fn ($f) => $f->getFilename());
    expect($files->filter(fn (string $f) => preg_match('/^indicadores-\d{4}-\d{2}-\d{2}-\d{6}\.sqlite$/', $f) === 1)->count())->toBe(1)
        ->and(File::exists($old))->toBeFalse()
        ->and(File::exists($recent))->toBeTrue();

    $backup = $files->first(fn (string $f) => preg_match('/-\d{6}\.sqlite$/', $f) === 1);
    config()->set('database.connections.backup_copy', ['driver' => 'sqlite', 'database' => "{$dir}/{$backup}", 'prefix' => '', 'foreign_key_constraints' => false]);
    expect((int) DB::connection('backup_copy')->selectOne('SELECT COUNT(*) AS n FROM prueba')->n)->toBe(300);

    DB::purge('backup_copy');
    DB::purge('backup_test');
    File::deleteDirectory($dir);

    $this->artisan('db:backup', ['--path' => $dir, '--connection' => 'inexistente'])->assertFailed();
});

it('demo:clear borra los datos de demostración y conserva sedes, parámetros y administrador', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoGoalsSeeder::class, DemoUsersSeeder::class]);
    expect(DailyRecord::query()->count())->toBeGreaterThan(0)->and(User::query()->count())->toBe(4);

    $this->artisan('demo:clear', ['--force' => true])
        ->expectsOutputToContain('Datos de demostración borrados')
        ->assertSuccessful();

    expect(DailyRecord::query()->count())->toBe(0)
        ->and(ExchangeRate::query()->count())->toBe(0)
        ->and(Goal::query()->count())->toBe(0)
        ->and(User::query()->whereIn('email', ClearDemoData::DEMO_EMAILS)->count())->toBe(0)
        ->and(User::query()->where('email', 'admin@guadalupe.local')->exists())->toBeTrue()
        ->and(Branch::query()->count())->toBe(1)
        ->and(Setting::get('sales_deviation_pct'))->toBe(35);
});
