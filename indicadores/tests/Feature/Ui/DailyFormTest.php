<?php

declare(strict_types=1);

use App\Enums\DayStatus;
use App\Enums\PeriodAction;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Livewire\Records\DailyForm;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\PeriodEvent;
use App\Models\User;
use App\Support\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-09-16 10:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('abre en el primer día no cargado del mes con la tasa BCV precargada y su insignia (UC-02)', function (): void {
    $user = userWithRole(Role::Operador);
    $branch = $user->branches->first();
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-01', 'created_by' => $user->id]);

    Livewire::actingAs($user)->test(DailyForm::class)
        ->assertSet('form.date', '2025-09-02')
        ->assertSet('form.rate', '148,44')
        ->assertSet('rateLabel', 'Arrastrada del lun 01/09')
        ->assertSet('form.shifts', '3')
        ->assertSee('Martes 2 de septiembre de 2025')
        ->assertSee('Ayer:')
        ->assertSee('Guardar día')
        // La vista previa se enlaza con entangle: el x-data no cambia entre morphs y no reinicia el estado.
        ->assertSee('x-data="dailyPreview($wire)"', false);
});

it('guarda un día y redirige al siguiente faltante', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-01'])
        ->set('form.sales_bs', '91.154,02')
        ->set('form.transactions', '119')
        ->set('form.units', '300')
        ->set('form.inventory_units', '9029')
        ->set('form.inventory_value_usd', '21848,73')
        ->set('form.shifts', '4')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('records.create', ['date' => '2025-09-02']));

    $record = DailyRecord::query()->firstOrFail();
    expect((string) $record->sales_bs)->toBe('91154.02')
        ->and((string) $record->exchange_rate)->toBe('148.4400')
        ->and($record->exchange_rate_source)->toBe(RateSource::Bcv)
        ->and($record->inventory_units)->toBe(9029)
        ->and((string) $record->inventory_value_usd)->toBe('21848.73');
});

it('muestra advertencias en línea y exige confirmar antes de guardar (RN-16)', function (): void {
    $user = userWithRole(Role::Operador);
    $branch = $user->branches->first();
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-01', 'sales_bs' => '91154.02', 'exchange_rate' => '148.44', 'created_by' => $user->id]);

    $component = Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-02'])
        ->set('form.sales_bs', '90000')
        ->set('form.transactions', '138')
        ->set('form.units', '120')
        ->set('form.rate', '15')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertSee('advertencias sin revisar')
        ->assertSee('Es 90 % menor que ayer (148,44). Revísala.')
        ->assertSee('Hay menos unidades (120) que transacciones (138).')
        // El botón «Revisar» enfoca el primer campo con advertencia; el id debe llegar compilado, no como @js literal.
        ->assertSee("document.getElementById('rate')", false)
        ->assertDontSee('@js(', false);

    expect(DailyRecord::query()->count())->toBe(1);

    $component->call('saveAnyway')->assertRedirect();

    $saved = DailyRecord::query()->where('date', '2025-09-02')->firstOrFail();
    expect($saved->exchange_rate_source)->toBe(RateSource::Manual)
        ->and((string) $saved->exchange_rate)->toBe('15.0000');
});

it('el aviso de inventario vacío no interrumpe mientras se escribe: solo al guardar', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-01'])
        ->set('form.sales_bs', '91.154,02')
        ->set('form.transactions', '119')
        ->set('form.units', '300')
        ->assertDontSee('Hoy toca conteo de inventario')
        ->call('save')
        ->assertNoRedirect()
        ->assertSee('Hoy toca conteo de inventario');
});

it('valida en servidor: fecha futura y números inválidos', function (): void {
    $user = userWithRole(Role::Operador);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-10'])
        ->set('form.date', '2025-09-17')
        ->set('form.sales_bs', 'abc')
        ->set('form.transactions', '')
        ->call('save')
        ->assertHasErrors(['form.date', 'form.sales_bs', 'form.transactions']);
});

it('bloquea el formulario si el mes está cerrado y muestra el aviso con la fecha de cierre (UC-02 A3)', function (): void {
    $user = userWithRole(Role::Operador);
    PeriodEvent::query()->create(['branch_id' => $user->branches->first()->id, 'period' => '2025-09-01', 'action' => PeriodAction::Closed, 'user_id' => $user->id]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-05'])
        ->assertSet('periodClosed', true)
        ->assertSet('closedSince', '16/09')
        ->assertSee('Septiembre 2025 está cerrado desde el 16/09')
        ->assertSee('Pedir reapertura a dirección')
        ->assertSeeHtml('inert');
});

it('el operador ve en solo lectura un día fuera de su ventana de edición; supervisión lo edita (§15.1)', function (): void {
    $operator = userWithRole(Role::Operador);
    $branch = $operator->branches->first();
    $supervisor = userWithRole(Role::Supervision, $branch);
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-01', 'created_by' => $operator->id]); // 15 días atrás, ventana de 7

    Livewire::actingAs($operator)->test(DailyForm::class, ['date' => '2025-09-01'])
        ->assertSet('readOnly', true)
        ->assertSee('Solo puedes editar los últimos 7 días')
        ->assertSeeHtml('inert')
        ->assertDontSee('Borrar día');

    Livewire::actingAs($supervisor)->test(DailyForm::class, ['date' => '2025-09-01'])
        ->assertSet('readOnly', false)
        ->assertSee('Borrar día')
        ->assertSee('Última edición:');
});

it('supervisión borra un día con confirmación y el aviso ofrece deshacer (UC-03)', function (): void {
    $supervisor = userWithRole(Role::Supervision);
    $branch = $supervisor->branches->first();
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-03', 'created_by' => $supervisor->id]);

    Livewire::actingAs($supervisor)->test(DailyForm::class, ['date' => '2025-09-03'])
        ->call('deleteDay')
        ->assertRedirect(route('month', ['period' => '2025-09']));

    expect(DailyRecord::query()->where('date', '2025-09-03')->exists())->toBeFalse()
        ->and(session('toast')['message'])->toBe('Día 03/09/2025 borrado.')
        ->and(session('toast')['action']['event'])->toBe('undo-delete')
        ->and(session('toast')['action']['params']['activity'])->toBeInt();
});

it('en sábado pliega el inventario y no lo exige (RN-09)', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-06'])
        ->assertSet('inventoryDay', false)
        ->assertSet('showInventory', false)
        ->assertSee('no se cuenta inventario')
        ->set('form.sales_bs', '95981,70')->set('form.transactions', '124')->set('form.units', '261')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(DailyRecord::query()->where('date', '2025-09-06')->firstOrFail()->inventory_units)->toBeNull();
});

it('edita un día existente y conserva la tasa si no se toca', function (): void {
    $user = userWithRole(Role::Supervision);
    $record = DailyRecord::factory()->for($user->branches->first())->create(['date' => '2025-09-03', 'exchange_rate' => '150.79', 'transactions' => 132, 'created_by' => $user->id]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-03'])
        ->assertSet('recordId', $record->id)
        ->assertSee('Editar día')
        ->assertSee('Guardar cambios')
        ->set('form.transactions', '140')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect($record->fresh()->transactions)->toBe(140)
        ->and((string) $record->fresh()->exchange_rate)->toBe('150.7900');
});

it('registra un día cerrado desde el formulario (UC-05)', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-07'])
        ->call('registerClosed', 'Feriado local')
        ->assertRedirect();

    expect(DailyRecord::query()->where('date', '2025-09-07')->firstOrFail()->status)->toBe(DayStatus::Closed);
});

it('cambiar la fecha a un día ya cargado muestra el aviso en vez de fallar (UC-02 A1)', function (): void {
    $user = userWithRole(Role::Operador);
    $branch = $user->branches->first();
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-01', 'created_by' => $user->id]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-02'])
        ->set('form.date', '2025-09-01')
        ->set('form.sales_bs', '100')->set('form.transactions', '1')->set('form.units', '1')
        ->call('saveAnyway')
        ->assertNoRedirect()
        ->assertSee('El 01/09/2025 ya está cargado');

    expect(DailyRecord::query()->count())->toBe(1);
});

it('si cambia la sede en la barra de contexto, el formulario se reabre para la nueva sede', function (): void {
    $user = userWithRole(Role::Direccion);
    $main = $user->branches->first();
    $other = Branch::factory()->create(['name' => 'Zona Sur']);
    $user->branches()->attach($other->id);
    app(CurrentBranch::class)->set($user, $main->id);

    $component = Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-10'])
        ->assertSet('branchId', $main->id);

    app(CurrentBranch::class)->set($user, $other->id);

    $component->dispatch('context-changed')->assertRedirect(route('records.create', ['date' => '2025-09-10']));
});

it('un usuario sin sede o sin permiso no accede', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->get(route('records.create'))->assertForbidden();
});
