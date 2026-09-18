<?php

declare(strict_types=1);

use App\Actions\Imports\ConfirmImport;
use App\Actions\Imports\ImportWorkbook;
use App\Domain\Imports\Exceptions\ImportException;
use App\Domain\Imports\ParsedMonth;
use App\Domain\Imports\WorkbookParser;
use App\Enums\AnomalySeverity;
use App\Enums\AnomalyType;
use App\Enums\ImportStatus;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Livewire\Imports\ImportWizard;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\ImportBatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

const ROBUSTEZ_HEADERS = [
    '#', 'Fecha', 'Venta BS ', 'Venta en $', 'Tasa $', 'TRN', 'Unidades', 'Ticket Promedio',
    "Unidades Promedio\n x Compra", 'Ticket Promedio en $', 'Unidades Cargadas (inventario)',
    'Valuacion de Inventario costo', 'Transacciones/ Jornadas', 'Jornada',
];

const ROBUSTEZ_LETTERS = [1 => 'L', 2 => 'M', 3 => 'M', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];

/**
 * Cuadro de un mes con la plantilla real. `$gaps` son números de día tras los cuales se cuela
 * una fila totalmente en blanco; `$tweaks` cambia columnas sueltas de un día.
 *
 * @param  list<int>  $gaps
 * @param  array<int, array<int, mixed>>  $tweaks  día => [índice de columna (0 = A) => valor]
 */
function cuadro(string $period = '2025-11', int $days = 10, array $gaps = [], array $tweaks = [], ?string $sheetTitle = null, ?string $monthName = null): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($sheetTitle ?? 'Indicadores ');
    $sheet->setCellValue('B2', $monthName ?? mb_strtoupper(CarbonImmutable::parse($period.'-01')->locale('es')->translatedFormat('F')));
    $sheet->setCellValue('C2', 'FARMACIA GUADALUPE, C.A.');
    foreach (ROBUSTEZ_HEADERS as $i => $header) {
        $sheet->setCellValue([$i + 1, 3], $header);
    }

    $r = 4;
    for ($day = 1; $day <= $days; $day++) {
        $date = CarbonImmutable::parse($period.'-01')->addDays($day - 1);
        $row = [
            ROBUSTEZ_LETTERS[$date->dayOfWeekIso], $date->toDateString(), 100000 + $day, null, 150 + $day,
            120, 250, null, null, null, 9000, 21000, null, 3,
        ];
        foreach ($tweaks[$day] ?? [] as $column => $value) {
            $row[$column] = $value;
        }
        foreach ($row as $c => $value) {
            if ($value !== null) {
                $sheet->setCellValue([$c + 1, $r], $value);
            }
        }
        $r++;
        if (in_array($day, $gaps, true)) {
            $r++; // fila totalmente en blanco en medio del mes
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'robustez').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function robustezUser(): User
{
    return userWithRole(Role::Direccion);
}

it('una fila en blanco en medio del mes ya no corta la lectura (A6)', function (): void {
    $month = (new WorkbookParser)->parse(cuadro(days: 10, gaps: [3, 7]));

    expect($month->rows)->toHaveCount(10)
        ->and(array_map(fn ($r) => $r->date, $month->rows))->toContain('2025-11-01', '2025-11-04', '2025-11-10')
        ->and($month->anomalies)->toBe([]);
});

it('una fila con datos pero sin fecha se omite y se avisa cuántas (A6)', function (): void {
    // El día 5 pierde su fecha pero conserva la letra del día: es un día, no la fila de totales
    $month = (new WorkbookParser)->parse(cuadro(days: 8, tweaks: [5 => [1 => null]]));

    $withoutDate = array_values(array_filter($month->anomalies, fn ($a) => $a->type === AnomalyType::RowWithoutDate));

    expect($month->rows)->toHaveCount(7)
        ->and($withoutDate)->toHaveCount(1)
        ->and($withoutDate[0]->message)->toContain('Se omitió 1 fila')
        ->and($withoutDate[0]->severity())->toBe(AnomalySeverity::Low);
});

it('encuentra la hoja del cuadro aunque no se llame "Indicadores", y si no la hay lo explica (B23)', function (): void {
    $month = (new WorkbookParser)->parse(cuadro(days: 3, sheetTitle: 'Hoja1'));
    expect($month->rows)->toHaveCount(3)->and($month->period)->toBe('2025-11');

    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->setTitle('Otra')->setCellValue('A1', 'Nada que ver');
    $path = tempnam(sys_get_temp_dir(), 'robustez').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    expect(fn () => (new WorkbookParser)->parse($path))
        ->toThrow(RuntimeException::class, 'se llame "Indicadores"');
});

it('los valores imposibles se marcan como anomalía alta y nunca llegan a la base (A8)', function (): void {
    $user = robustezUser();
    $branch = $user->branches->first();

    // Día 4: 300 jornadas (la columna admite hasta 255). Día 6: transacciones astronómicas.
    $path = cuadro(days: 8, tweaks: [4 => [13 => 300], 6 => [5 => 5000000000]]);
    $batch = app(ImportWorkbook::class)->handle($path, 'raro.xlsx', $branch, $user, 'g1');

    $outOfRange = array_values(array_filter(
        ParsedMonth::fromArray($batch->parsed_payload)->anomalies,
        fn ($a) => $a->type === AnomalyType::ValueOutOfRange,
    ));

    expect($outOfRange)->toHaveCount(2)
        ->and($outOfRange[0]->severity())->toBe(AnomalySeverity::High)
        ->and($outOfRange[0]->message)->toContain('jornadas 300', 'entre 0 y 255')
        ->and($outOfRange[1]->message)->toContain('transacciones 5000000000');

    // Sin decidir, nada entra; decidiendo "omitir", entran los demás días y ninguno rompe la base
    expect(fn () => app(ConfirmImport::class)->handle($batch, [], $user))
        ->toThrow(ImportException::class, 'por resolver');

    $result = app(ConfirmImport::class)->handle($batch, [
        $outOfRange[0]->id() => 'omit',
        $outOfRange[1]->id() => 'omit',
    ], $user);

    expect($result['created'])->toBe(6)
        ->and(DailyRecord::query()->where('date', '2025-11-04')->exists())->toBeFalse()
        ->and(DailyRecord::query()->where('date', '2025-11-06')->exists())->toBeFalse()
        ->and(DailyRecord::query()->max('shifts'))->toBe(3);
});

it('si un archivo falla al guardarse, el lote queda fallido con un mensaje claro y no revienta (A8)', function (): void {
    $user = robustezUser();
    $upload = UploadedFile::fake()->createWithContent('cuadro.xlsx', (string) file_get_contents(cuadro(days: 5)));

    // La base rechaza la inserción (como haría una columna desbordada): debe ser un fallo del archivo, no un 500
    DB::statement("CREATE TRIGGER robustez_no_insert BEFORE INSERT ON daily_records BEGIN SELECT RAISE(ABORT, 'numeric value out of range'); END");

    $component = Livewire::actingAs($user)->test(ImportWizard::class)
        ->set('files', [$upload])
        ->call('analyze')
        ->assertSet('step', 2);

    $batchId = $component->get('batchIds')[0];

    $component->call('confirm')
        ->assertSet('step', 2)
        ->assertHasErrors(['batch.'.$batchId])
        ->assertSee('No se pudo importar este archivo');

    $batch = ImportBatch::query()->findOrFail($batchId);
    expect($batch->status)->toBe(ImportStatus::Failed)
        ->and($batch->summary['error'])->toContain('No se pudo importar este archivo')
        ->and(DailyRecord::query()->count())->toBe(0);
});

it('dos archivos del mismo mes en un lote obligan a elegir cuál importar (M1)', function (): void {
    $user = robustezUser();
    $branch = $user->branches->first();

    $first = app(ImportWorkbook::class)->handle(cuadro(days: 5), 'noviembre-v1.xlsx', $branch, $user, 'lote-1');
    $second = app(ImportWorkbook::class)->handle(cuadro(days: 5, tweaks: [1 => [2 => 999999]]), 'noviembre-v2.xlsx', $branch, $user, 'lote-1');

    $types = fn (ImportBatch $b) => array_column($b->refresh()->parsed_payload['anomalies'], 'type');

    // Los dos lo avisan: el primero también, para poder descartarlo a él
    expect($types($first))->toContain(AnomalyType::DuplicatePeriodInBatch->value)
        ->and($types($second))->toContain(AnomalyType::DuplicatePeriodInBatch->value)
        ->and($first->refresh()->summary['blocking'])->toBeGreaterThan(0)
        ->and(AnomalyType::DuplicatePeriodInBatch->severity())->toBe(AnomalySeverity::High);

    // Descartando el primero, solo entra el segundo
    $rejected = app(ConfirmImport::class)->handle($first, [AnomalyType::DuplicatePeriodInBatch->value => 'skip'], $user);
    $imported = app(ConfirmImport::class)->handle($second, [AnomalyType::DuplicatePeriodInBatch->value => 'import'], $user);

    expect($rejected['rejected'])->toBeTrue()
        ->and($first->refresh()->status)->toBe(ImportStatus::Rejected)
        ->and($imported['created'])->toBe(5)
        ->and((string) DailyRecord::query()->where('date', '2025-11-01')->firstOrFail()->sales_bs)->toBe('999999.00');

    // Otro lote distinto con el mismo mes no dispara la anomalía
    $other = app(ImportWorkbook::class)->handle(cuadro(days: 5), 'noviembre-v3.xlsx', $branch, $user, 'lote-2');
    expect($types($other))->not->toContain(AnomalyType::DuplicatePeriodInBatch->value);
});

it('la decisión de un mes ya cargado dice lo que de verdad hace (M2)', function (): void {
    $options = array_column(AnomalyType::AlreadyImported->options(), 'label', 'value');

    expect($options['replace'])->toBe('Actualizar los días que trae el archivo')
        ->and($options['replace'])->not->toContain('Reemplazar');

    $user = robustezUser();
    $branch = $user->branches->first();
    app(ConfirmImport::class)->handle(app(ImportWorkbook::class)->handle(cuadro(days: 10), 'a.xlsx', $branch, $user, 'g1'), [], $user);

    $again = app(ImportWorkbook::class)->handle(cuadro(days: 4), 'b.xlsx', $branch, $user, 'g2');
    $already = array_values(array_filter(ParsedMonth::fromArray($again->parsed_payload)->anomalies, fn ($a) => $a->type === AnomalyType::AlreadyImported));

    expect($already[0]->message)->toContain('los que no trae se quedan como están');

    // Y en efecto: actualiza 4 días y deja los otros 6 intactos
    $result = app(ConfirmImport::class)->handle($again, ['already_imported' => 'replace'], $user);
    expect($result['updated'])->toBe(4)->and(DailyRecord::query()->count())->toBe(10);
});

it('una tasa oficial del BCV no se degrada a manual al importar (M3)', function (): void {
    $user = robustezUser();
    $branch = $user->branches->first();

    // El archivo trae 151 para el 01/11 y 152 para el 02/11; la base ya tiene el mismo 151 del BCV
    ExchangeRate::query()->create(['date' => '2025-11-01', 'rate' => '151.0000', 'source' => RateSource::Bcv, 'fetched_at' => now()]);
    ExchangeRate::query()->create(['date' => '2025-11-02', 'rate' => '200.0000', 'source' => RateSource::Bcv, 'fetched_at' => now()]);

    $batch = app(ImportWorkbook::class)->handle(cuadro(days: 3), 'a.xlsx', $branch, $user, 'g1');
    $result = app(ConfirmImport::class)->handle($batch, ['rate_conflict:2025-11-02' => 'replace'], $user);

    expect(ExchangeRate::query()->where('date', '2025-11-01')->firstOrFail()->source)->toBe(RateSource::Bcv)
        ->and((string) ExchangeRate::query()->where('date', '2025-11-01')->firstOrFail()->rate)->toBe('151.0000')
        // La que estaba en conflicto y se pidió reemplazar sí pasa a manual
        ->and(ExchangeRate::query()->where('date', '2025-11-02')->firstOrFail()->source)->toBe(RateSource::Manual)
        // Y el día que no tenía tasa se crea con la del archivo
        ->and(ExchangeRate::query()->where('date', '2025-11-03')->firstOrFail()->source)->toBe(RateSource::Manual)
        ->and($result['rates'])->toBe(2);
});

it('analizar no deja archivos temporales sueltos (M7)', function (): void {
    $user = robustezUser();
    $upload = UploadedFile::fake()->createWithContent('cuadro.xlsx', (string) file_get_contents(cuadro(days: 4)));

    $count = fn (): int => count(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'imp*') ?: []);
    $before = $count();

    Livewire::actingAs($user)->test(ImportWizard::class)->set('files', [$upload])->call('analyze')->assertSet('step', 2);

    expect($count())->toBe($before);
});

it('al entrar al importador se descarta lo analizado y abandonado de hace más de un día (M8)', function (): void {
    $user = robustezUser();
    $branch = $user->branches->first();

    $stale = ImportBatch::query()->create([
        'group_id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'user_id' => $user->id,
        'original_filename' => 'viejo.xlsx', 'file_hash' => str_repeat('b', 64), 'status' => ImportStatus::Parsed,
    ]);
    $stale->forceFill(['created_at' => '2025-10-01 09:00:00'])->save();

    $fresh = ImportBatch::query()->create([
        'group_id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'user_id' => $user->id,
        'original_filename' => 'hoy.xlsx', 'file_hash' => str_repeat('c', 64), 'status' => ImportStatus::Parsed,
    ]);

    $other = ImportBatch::query()->create([
        'group_id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'user_id' => $user->id,
        'original_filename' => 'ajeno.xlsx', 'file_hash' => str_repeat('d', 64), 'status' => ImportStatus::Parsed,
    ]);
    $other->forceFill(['created_at' => '2025-10-01 09:00:00', 'user_id' => userWithRole(Role::Supervision)->id])->save();

    Livewire::actingAs($user)->test(ImportWizard::class)->assertSet('step', 1);

    expect(ImportBatch::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(ImportBatch::query()->whereKey($fresh->id)->exists())->toBeTrue()
        ->and(ImportBatch::query()->whereKey($other->id)->exists())->toBeTrue();
});

it('un archivo que no se puede leer del disco deja el lote fallido, sin error técnico (B21)', function (): void {
    $user = robustezUser();
    $branch = $user->branches->first();

    $batch = app(ImportWorkbook::class)->handle(sys_get_temp_dir().'/no-existe-'.Str::random(8).'.xlsx', 'fantasma.xlsx', $branch, $user, 'g1');

    expect($batch->status)->toBe(ImportStatus::Failed)
        ->and($batch->summary['error'])->not->toBeEmpty()
        ->and($batch->parsed_payload)->toBeNull();
});
