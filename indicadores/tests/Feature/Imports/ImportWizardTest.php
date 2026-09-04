<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Livewire\Imports\ImportWizard;
use App\Models\DailyRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

/** El archivo real como subida de prueba (Livewire serializa archivos de prueba, no UploadedFile crudos). */
function fixtureUpload(string $name = 'CUADRO SEOTIEMBRE.xlsx'): File
{
    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(__DIR__.'/../../Fixtures/cuadro-septiembre-2025.xlsx'));
}

it('solo quien puede importar entra; el operador no ve la entrada del menú', function (): void {
    $branch = mainBranch();

    $this->actingAs(userWithRole(Role::Supervision, $branch))->get(route('imports'))->assertOk()->assertSee('Importar meses anteriores');
    $this->actingAs(userWithRole(Role::Operador, $branch))->get(route('imports'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Operador, $branch))->get(route('month'))->assertOk()->assertDontSee('Importar');
});

it('analiza el archivo real, muestra la revisión con la vista previa e importa el mes en tres pasos', function (): void {
    $user = userWithRole(Role::Supervision);

    $component = Livewire::actingAs($user)->test(ImportWizard::class)
        ->assertSet('step', 1)
        ->call('analyze')
        ->assertHasErrors(['files'])
        ->assertSee('Elige al menos un archivo .xlsx.')
        ->set('files', [fixtureUpload()])
        ->call('analyze')
        ->assertHasNoErrors()
        ->assertSet('step', 2)
        ->assertSee('CUADRO SEOTIEMBRE.xlsx')
        ->assertSee('Septiembre 2025 · 30 filas')
        ->assertSee('Vista previa (30 días')
        ->assertSee('lun 01/09')
        ->assertSee('Importar 1 mes');

    expect($component->get('batchIds'))->toHaveCount(1);

    $component->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 3)
        ->assertDispatched('toast')
        ->assertSee('Septiembre 2025 importado.')
        ->assertSee('30 días nuevos')
        ->assertSee('Ver el mes');

    expect(DailyRecord::query()->count())->toBe(30);
});

it('con el mes ya cargado, la anomalía alta bloquea hasta decidir y el reemplazo actualiza', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $component = Livewire::actingAs($admin)->test(ImportWizard::class)
        ->set('files', [fixtureUpload()])
        ->call('analyze')
        ->assertSet('step', 2)
        ->assertSee('ya tiene 30 días cargados')
        ->assertSee('Falta 1 anomalía por resolver');

    $batchId = $component->get('batchIds')[0];

    $component->call('confirm')
        ->assertSet('step', 2)
        ->assertHasErrors(['batch.'.$batchId]);

    $component->set("decisions.{$batchId}.already_imported", 'replace')
        ->assertSee('Importar 1 mes')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 3)
        ->assertSee('30 actualizados');

    expect(DailyRecord::query()->count())->toBe(30);
});

it('rechaza archivos que no son .xlsx y explica por qué', function (): void {
    $user = userWithRole(Role::Supervision);

    Livewire::actingAs($user)->test(ImportWizard::class)
        ->set('files', [UploadedFile::fake()->create('cuadro.xlsm', 10, 'application/vnd.ms-excel.sheet.macroEnabled.12')])
        ->call('analyze')
        ->assertHasErrors(['files.0'])
        ->assertSee('Solo se aceptan archivos .xlsx (sin macros).');
});
