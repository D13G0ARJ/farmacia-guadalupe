<?php

declare(strict_types=1);

use App\Domain\Shared\Period;
use App\Enums\DayStatus;
use App\Enums\Role;
use App\Livewire\Records\DailyForm;
use App\Livewire\Records\MonthOverview;
use App\Models\DailyRecord;
use App\Models\User;
use App\Support\PeriodContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function friendlyAdmin(): User
{
    test()->seed([DatabaseSeeder::class, DemoSeeder::class]);
    app(PeriodContext::class)->set(Period::of('2025-09'));

    return User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
}

it('el cuadro del mes se ordena por columna y se busca por fecha sin tocar los totales (UC-09)', function (): void {
    $admin = friendlyAdmin();

    $component = Livewire::actingAs($admin)->test(MonthOverview::class)
        ->assertSet('sort', 'date')
        ->assertSeeHtml('aria-sort="ascending"')
        ->call('sortBy', 'sales_bs')
        ->assertSet('sort', 'sales_bs')
        ->assertSet('dir', 'desc');

    $rows = $component->viewData('rows');
    expect($rows[0]->data->date->toDateString())->toBe('2025-09-25') // la venta más alta
        ->and(end($rows)->data->date->toDateString())->toBe('2025-09-16'); // el día atípico, la más baja

    $component->call('sortBy', 'sales_bs')->assertSet('dir', 'asc');
    expect($component->viewData('rows')[0]->data->date->toDateString())->toBe('2025-09-16');

    $component->call('sortBy', 'nada')->assertSet('sort', 'sales_bs');

    $component->set('search', '16')->assertSee('mar 16/09')->assertDontSee('lun 15/09');
    expect($component->viewData('rows'))->toHaveCount(1)
        ->and($component->viewData('view')->summary->days)->toBe(30); // los totales siguen siendo del mes completo

    $component->set('search', 'mar');
    expect($component->viewData('rows'))->toHaveCount(5);

    $component->set('search', '99')->assertSee('Ningún día coincide con');
    $component->set('search', '')->call('sortBy', 'date');
    expect($component->viewData('rows'))->toHaveCount(30);
});

it('marcar un día como atípico deja "Deshacer" en el aviso y deshacerlo lo devuelve a normal', function (): void {
    $admin = friendlyAdmin();
    $record = DailyRecord::query()->where('date', '2025-09-10')->firstOrFail();

    Livewire::actingAs($admin)->test(DailyForm::class, ['date' => '2025-09-10'])
        ->set('form.atypical', true)
        ->set('form.notes', 'Media jornada por inventario')
        ->call('save')
        ->assertRedirect();

    expect($record->fresh()?->status)->toBe(DayStatus::Atypical)
        ->and(session('toast')['message'])->toContain('atípico')
        ->and(session('toast')['action']['event'])->toBe('undo-atypical')
        ->and(session('toast')['action']['params']['record'])->toBe($record->id);

    Livewire::actingAs($admin)->test(MonthOverview::class)
        ->dispatch('undo-atypical', record: $record->id)
        ->assertDispatched('toast', fn (string $name, array $params) => str_contains($params['message'], 'Marca de atípico retirada'));

    expect($record->fresh()?->status)->toBe(DayStatus::Normal)
        ->and($record->fresh()?->notes)->toBe('Media jornada por inventario');

    // Repetir el deshacer avisa sin romper nada
    Livewire::actingAs($admin)->test(DailyForm::class, ['date' => '2025-09-10'])
        ->dispatch('undo-atypical', record: $record->id)
        ->assertDispatched('toast', fn (string $name, array $params) => $params['type'] === 'warning');
});

it('la carga en secuencia recorre los días faltantes y sigue con el siguiente al guardar (§13.8)', function (): void {
    $admin = friendlyAdmin();
    DailyRecord::query()->whereIn('date', ['2025-09-03', '2025-09-05', '2025-09-08'])->delete();

    $component = Livewire::actingAs($admin)->withQueryParams(['faltantes' => 1])->test(DailyForm::class, ['date' => '2025-09-05'])
        ->assertSet('sequence', true)
        ->assertSee('Faltante 2 de 3')
        ->assertSee('Anterior')
        ->assertSee('Siguiente')
        ->assertSee('Salir');

    expect($component->viewData('sequenceInfo'))->toMatchArray(['position' => 2, 'total' => 3, 'previous' => '2025-09-03', 'next' => '2025-09-08']);

    // Un viernes toca conteo de inventario: el aviso pide confirmar, y "Guardar de todos modos" sigue la secuencia
    $component->set('form.sales_bs', '90.000,00')->set('form.transactions', '120')->set('form.units', '250')->set('form.shifts', '3')
        ->call('saveAnyway');
    expect($component->errors()->all())->toBe([])->and($component->get('error'))->toBeNull();
    $component->assertRedirect(route('records.create', ['date' => '2025-09-03', 'faltantes' => 1]));

    Livewire::actingAs($admin)->withQueryParams([])->test(DailyForm::class, ['date' => '2025-09-10'])->assertSet('sequence', false)->assertDontSee('Faltante');

    Livewire::actingAs($admin)->test(MonthOverview::class)->assertSee('Cargar los 2 faltantes')->assertSeeHtml('faltantes=1');
});

it('el panel muestra "¿Cómo se calcula?" con el último día y el formulario guarda el borrador por fecha', function (): void {
    $admin = friendlyAdmin();

    $this->actingAs($admin)->get(route('records.create', ['date' => '2025-10-01']))
        ->assertOk()
        ->assertSee('dailyPreview($wire, { branch: 1, edit: false', false)
        ->assertSee('Recuperamos lo que escribiste para este día');

    $this->actingAs($admin)->get(route('records.create', ['date' => '2025-09-10']))
        ->assertSee('edit: true', false);

    $this->actingAs(userWithRole(Role::Operador))->get(route('month'))->assertOk()->assertSee('Buscar fecha');
});
