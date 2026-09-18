<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BranchSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('si el correo de una sede falla, las demás reciben el suyo y el comando avisa del fallo (A9)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    Branch::factory()->create(['name' => 'Sede Segunda', 'code' => 'GUA-99']);
    Setting::put('report_recipients', 'direccion@farmacia.com');

    Log::spy();
    $pending = Mockery::mock();
    $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('El servidor de correo no respondió'));
    $pending->shouldReceive('send')->once()->andReturnNull();
    Mail::shouldReceive('to')->twice()->andReturn($pending);

    $this->artisan('reports:send-monthly', ['--force' => true])
        ->expectsOutputToContain('No se pudo enviar')
        ->expectsOutputToContain('Reportes enviados: 1')
        ->expectsOutputToContain('Sedes sin reporte')
        ->assertFailed();

    Log::shouldHaveReceived('error')->atLeast()->once();
});

it('el recordatorio de cierre no revienta si los permisos no están sembrados (B22)', function (): void {
    $this->seed(BranchSeeder::class);

    $this->artisan('periods:remind-close', ['--force' => true])
        ->expectsOutputToContain('permisos no están sembrados')
        ->assertSuccessful();
});

it('imports:prune borra lo analizado y nunca confirmado, y respeta lo confirmado (M8)', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
    $branch = Branch::query()->firstOrFail();

    $make = function (ImportStatus $status, string $createdAt) use ($user, $branch): ImportBatch {
        $batch = ImportBatch::query()->create([
            'group_id' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'original_filename' => 'x.xlsx',
            'file_hash' => str_repeat('a', 64),
            'status' => $status,
        ]);
        $batch->forceFill(['created_at' => $createdAt])->save();

        return $batch;
    };

    $old = $make(ImportStatus::Parsed, '2025-09-01 09:00:00');
    $oldFailed = $make(ImportStatus::Failed, '2025-09-01 09:00:00');
    $recent = $make(ImportStatus::Parsed, '2025-10-02 09:00:00');
    $confirmed = $make(ImportStatus::Confirmed, '2025-09-01 09:00:00');

    $this->artisan('imports:prune')->expectsOutputToContain('Lotes de importación borrados: 2')->assertSuccessful();

    expect(ImportBatch::query()->whereKey([$old->id, $oldFailed->id])->count())->toBe(0)
        ->and(ImportBatch::query()->whereKey([$recent->id, $confirmed->id])->count())->toBe(2);

    $this->artisan('imports:prune', ['--days' => 0])->assertFailed();
});

it('los trabajos programados dejan rastro en el log si fallan (M10)', function (): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $callbacks = fn (Event $e): array => (fn () => $this->afterCallbacks)->call($e);

    $watched = ['reports:send-monthly', 'periods:remind-close', 'db:backup', 'bcv-rate-today', 'bcv-rate-next-business-day'];
    $found = [];

    foreach ($schedule->events() as $event) {
        foreach ($watched as $name) {
            if (str_contains((string) $event->description, $name) || str_contains($event->getSummaryForDisplay(), $name)) {
                $found[$name] = $callbacks($event) !== [];
            }
        }
    }

    foreach ($watched as $name) {
        expect($found[$name] ?? false)->toBeTrue("el programado {$name} no avisa si falla");
    }
});
