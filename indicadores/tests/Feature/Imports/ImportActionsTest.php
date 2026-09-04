<?php

declare(strict_types=1);

use App\Actions\Imports\ConfirmImport;
use App\Actions\Imports\ImportWorkbook;
use App\Domain\Imports\Exceptions\ImportException;
use App\Domain\Shared\Period;
use App\Enums\DayStatus;
use App\Enums\ImportStatus;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\ImportBatch;
use App\Models\PeriodEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const IMPORT_FIXTURE = __DIR__.'/../../Fixtures/cuadro-septiembre-2025.xlsx';

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('lee el archivo real, lo deja en revisión y al confirmar carga los 30 días con sus tasas (UC-15)', function (): void {
    $user = userWithRole(Role::Supervision);
    $branch = $user->branches->first();

    $batch = app(ImportWorkbook::class)->handle(IMPORT_FIXTURE, 'CUADRO SEOTIEMBRE.xlsx', $branch, $user, 'g1');

    expect($batch->status)->toBe(ImportStatus::Parsed)
        ->and($batch->period?->toDateString())->toBe('2025-09-01')
        ->and($batch->summary['rows'])->toBe(30)
        ->and($batch->summary['period_label'])->toBe('Septiembre 2025')
        ->and($batch->summary['blocking'])->toBe(0)
        ->and($batch->summary['info'])->toBe(30) // la letra del día viene corrida en todo el archivo (H1)
        ->and(strlen($batch->file_hash))->toBe(64);

    $result = app(ConfirmImport::class)->handle($batch, [], $user);

    expect($result['created'])->toBe(30)
        ->and($result['updated'])->toBe(0)
        ->and($result['rates'])->toBe(30)
        ->and($result['rejected'])->toBeFalse()
        ->and(DailyRecord::query()->forBranch($branch->id)->count())->toBe(30)
        ->and(DailyRecord::query()->where('date', '2025-09-06')->firstOrFail()->inventory_units)->toBeNull()
        ->and((string) DailyRecord::query()->where('date', '2025-09-01')->firstOrFail()->sales_bs)->toBe('91154.02')
        ->and(DailyRecord::query()->where('date', '2025-09-01')->firstOrFail()->created_by)->toBe($user->id)
        ->and(ExchangeRate::query()->count())->toBe(30)
        ->and(ExchangeRate::query()->where('date', '2025-09-01')->firstOrFail()->source)->toBe(RateSource::Manual)
        ->and($batch->refresh()->status)->toBe(ImportStatus::Confirmed)
        ->and(Activity::query()->where('subject_type', ImportBatch::class)->where('event', 'imported')->exists())->toBeTrue()
        ->and(PeriodEvent::isClosed($branch->id, Period::of('2025-09')))->toBeFalse();
});

it('volver a importar el mismo archivo exige decidir: reemplazar actualiza, omitir rechaza', function (): void {
    $user = userWithRole(Role::Supervision);
    $branch = $user->branches->first();
    $first = app(ImportWorkbook::class)->handle(IMPORT_FIXTURE, 'a.xlsx', $branch, $user, 'g1');
    app(ConfirmImport::class)->handle($first, [], $user);

    $second = app(ImportWorkbook::class)->handle(IMPORT_FIXTURE, 'a.xlsx', $branch, $user, 'g2');
    expect($second->summary['blocking'])->toBe(1)
        ->and($second->parsed_payload['anomalies'][0]['type'])->toBe('already_imported')
        ->and($second->parsed_payload['anomalies'][0]['message'])->toContain('03/10/2025');

    expect(fn () => app(ConfirmImport::class)->handle($second, [], $user))->toThrow(ImportException::class, 'Falta 1 anomalía por resolver');

    $result = app(ConfirmImport::class)->handle($second, ['already_imported' => 'replace'], $user);
    expect($result['updated'])->toBe(30)->and($result['created'])->toBe(0)->and(DailyRecord::query()->count())->toBe(30);

    $third = app(ImportWorkbook::class)->handle(IMPORT_FIXTURE, 'a.xlsx', $branch, $user, 'g3');
    $result = app(ConfirmImport::class)->handle($third, ['already_imported' => 'skip'], $user);
    expect($result['rejected'])->toBeTrue()->and($third->refresh()->status)->toBe(ImportStatus::Rejected);

    expect(fn () => app(ConfirmImport::class)->handle($third, [], $user))->toThrow(ImportException::class, 'ya se procesó');
});

it('aplica las decisiones: atípico por desvío, tasa en conflicto conservada, cierre al importar', function (): void {
    $user = userWithRole(Role::Direccion);
    $branch = $user->branches->first();
    ExchangeRate::query()->create(['date' => '2025-09-02', 'rate' => '150.0000', 'source' => RateSource::Bcv]);

    $batch = app(ImportWorkbook::class)->handle(IMPORT_FIXTURE, 'a.xlsx', $branch, $user, 'g1');
    $ids = array_column($batch->parsed_payload['anomalies'], 'type', 'date');
    expect($ids['2025-09-02'] ?? null)->toBe('rate_conflict');

    $result = app(ConfirmImport::class)->handle($batch, [
        'sales_deviation:2025-09-16' => 'atypical',
        'rate_conflict:2025-09-02' => 'keep',
    ], $user, closeAfter: true);

    $atypical = DailyRecord::query()->where('date', '2025-09-16')->firstOrFail();
    expect($result['atypical'])->toBe(1)
        ->and($atypical->status)->toBe(DayStatus::Atypical)
        ->and($atypical->notes)->toContain('importar')
        ->and((string) ExchangeRate::query()->where('date', '2025-09-02')->firstOrFail()->rate)->toBe('150.0000') // conservada
        ->and((string) DailyRecord::query()->where('date', '2025-09-02')->firstOrFail()->exchange_rate)->toBe('149.4600') // el snapshot usa la del archivo
        ->and(PeriodEvent::isClosed($branch->id, Period::of('2025-09')))->toBeTrue();
});

it('reemplazar un mes conserva la marca de atípico puesta a mano y reabre un día cerrado que ahora trae venta', function (): void {
    $user = userWithRole(Role::Direccion);
    $branch = $user->branches->first();
    $first = app(ImportWorkbook::class)->handle(IMPORT_FIXTURE, 'a.xlsx', $branch, $user, 'g1');
    app(ConfirmImport::class)->handle($first, [], $user);
    DailyRecord::query()->where('date', '2025-09-16')->update(['status' => DayStatus::Atypical, 'notes' => 'Corte de luz']);
    DailyRecord::query()->where('date', '2025-09-17')->update(['status' => DayStatus::Closed, 'notes' => 'Cerrado por error']);

    $again = app(ImportWorkbook::class)->handle(IMPORT_FIXTURE, 'a.xlsx', $branch, $user, 'g2');
    $result = app(ConfirmImport::class)->handle($again, ['already_imported' => 'replace'], $user);

    $atypical = DailyRecord::query()->where('date', '2025-09-16')->firstOrFail();
    $reopened = DailyRecord::query()->where('date', '2025-09-17')->firstOrFail();
    expect($result['updated'])->toBe(30)
        ->and($atypical->status)->toBe(DayStatus::Atypical)
        ->and($atypical->notes)->toBe('Corte de luz')
        ->and($reopened->status)->toBe(DayStatus::Normal)
        ->and($reopened->notes)->toBeNull();
});

it('no importa sobre un mes cerrado: pide reabrirlo y no toca ningún día (RN-13)', function (): void {
    $user = userWithRole(Role::Direccion);
    $branch = $user->branches->first();
    $batch = app(ImportWorkbook::class)->handle(IMPORT_FIXTURE, 'a.xlsx', $branch, $user, 'g1');
    app(ConfirmImport::class)->handle($batch, [], $user, closeAfter: true);
    expect(PeriodEvent::isClosed($branch->id, Period::of('2025-09')))->toBeTrue();

    $again = app(ImportWorkbook::class)->handle(IMPORT_FIXTURE, 'a.xlsx', $branch, $user, 'g2');
    $before = DailyRecord::query()->where('date', '2025-09-02')->firstOrFail()->updated_at;

    expect(fn () => app(ConfirmImport::class)->handle($again, ['already_imported' => 'replace'], $user))
        ->toThrow(ImportException::class, 'Septiembre 2025 está cerrado');
    expect($again->fresh()?->status)->toBe(ImportStatus::Parsed)
        ->and(DailyRecord::query()->where('date', '2025-09-02')->firstOrFail()->updated_at)->toEqual($before);
});

it('un archivo ilegible queda como fallido con la explicación, sin lanzar', function (): void {
    $user = userWithRole(Role::Supervision);
    $branch = $user->branches->first();
    $path = tempnam(sys_get_temp_dir(), 'bad');
    file_put_contents($path, 'esto no es un libro de Excel');

    $batch = app(ImportWorkbook::class)->handle($path, 'malo.xlsx', $branch, $user, 'g1');

    expect($batch->status)->toBe(ImportStatus::Failed)
        ->and($batch->summary['error'])->not->toBeEmpty()
        ->and($batch->parsed_payload)->toBeNull();
});
