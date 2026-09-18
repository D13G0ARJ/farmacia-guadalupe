<?php

declare(strict_types=1);

use App\Actions\Records\RegisterClosedDay;
use App\Domain\Shared\Period;
use App\Enums\DayStatus;
use App\Enums\PeriodAction;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Livewire\Records\DailyForm;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\PeriodEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-09-16 10:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('editar otro campo no reescribe la tasa guardada ni la tasa global del día (A1)', function (): void {
    $user = userWithRole(Role::Supervision);
    $branch = $user->branches->first();
    ExchangeRate::query()->create(['date' => '2025-09-10', 'rate' => '148.4421', 'source' => RateSource::Bcv]);
    $record = DailyRecord::factory()->for($branch)->create([
        'date' => '2025-09-10', 'exchange_rate' => '148.4421', 'exchange_rate_source' => RateSource::Bcv,
        'transactions' => 132, 'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-10'])
        // La tasa se muestra completa, no recortada a dos decimales
        ->assertSet('form.rate', '148,4421')
        ->assertSet('rateEdited', false)
        ->assertSet('rateLabel', 'BCV')
        ->set('form.transactions', '140')
        ->call('saveAnyway')
        ->assertHasNoErrors();

    $fresh = $record->fresh();
    expect($fresh->transactions)->toBe(140)
        ->and((string) $fresh->exchange_rate)->toBe('148.4421')
        ->and($fresh->exchange_rate_source)->toBe(RateSource::Bcv);

    $published = ExchangeRate::query()->where('date', '2025-09-10')->firstOrFail();
    expect((string) $published->rate)->toBe('148.4421')
        ->and($published->source)->toBe(RateSource::Bcv)
        ->and(ExchangeRate::query()->count())->toBe(1);
});

it('cambiar la tasa a mano sí la marca como manual (A1, contraprueba)', function (): void {
    $user = userWithRole(Role::Supervision);
    $branch = $user->branches->first();
    ExchangeRate::query()->create(['date' => '2025-09-10', 'rate' => '148.4421', 'source' => RateSource::Bcv]);
    $record = DailyRecord::factory()->for($branch)->create([
        'date' => '2025-09-10', 'exchange_rate' => '148.4421', 'exchange_rate_source' => RateSource::Bcv,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-10'])
        ->set('form.rate', '150,00')
        ->assertSet('rateEdited', true)
        ->call('saveAnyway')
        ->assertHasNoErrors();

    expect((string) $record->fresh()->exchange_rate)->toBe('150.0000')
        ->and($record->fresh()->exchange_rate_source)->toBe(RateSource::Manual)
        ->and((string) ExchangeRate::query()->where('date', '2025-09-10')->firstOrFail()->rate)->toBe('150.0000');
});

it('si el mes se cierra mientras el formulario está abierto, guardar avisa en vez de dar un 403 (A2)', function (): void {
    $user = userWithRole(Role::Supervision);
    $branch = $user->branches->first();
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-10', 'created_by' => $user->id]);

    $component = Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-10'])
        ->assertSet('periodClosed', false);

    PeriodEvent::query()->create(['branch_id' => $branch->id, 'period' => '2025-09-01', 'action' => PeriodAction::Closed, 'user_id' => $user->id]);

    $component->set('form.transactions', '140')
        ->call('save')
        ->assertNoRedirect()
        ->assertSet('error', 'Septiembre 2025 está cerrado. Pide la reapertura para editarlo.')
        ->assertSet('periodClosed', true);

    $component->call('deleteDay')
        ->assertNoRedirect()
        ->assertSet('error', 'Septiembre 2025 está cerrado. Pide la reapertura para editarlo.');

    expect(DailyRecord::query()->where('date', '2025-09-10')->firstOrFail()->transactions)->not->toBe(140);
});

it('si vence la ventana del operador mientras el formulario está abierto, guardar avisa (A2)', function (): void {
    $operador = userWithRole(Role::Operador);
    $branch = $operador->branches->first();
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-14', 'created_by' => $operador->id]);

    $component = Livewire::actingAs($operador)->test(DailyForm::class, ['date' => '2025-09-14'])
        ->assertSet('readOnly', false);

    // El día se aleja: ya está fuera de los 7 días de la ventana.
    CarbonImmutable::setTestNow('2025-09-30 10:00:00');

    $component->set('form.transactions', '140')
        ->call('save')
        ->assertNoRedirect()
        ->assertSet('error', 'Solo puedes editar los últimos 7 días. Pide el cambio a supervisión.');
});

it('rechaza números que desbordan las columnas con un mensaje claro (A3)', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-01'])
        ->set('form.sales_bs', '9.999.999.999.999,99')
        ->set('form.transactions', '4294967296')
        ->set('form.units', '300')
        ->call('save')
        ->assertHasErrors(['form.sales_bs', 'form.transactions'])
        ->assertSee('Es demasiado grande. Revisa el valor.');

    expect(DailyRecord::query()->count())->toBe(0);
});

it('avisa del formato inglés en vez de leer "1,234.56" como 1,23 (M16)', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-01'])
        ->set('form.sales_bs', '1,234.56')
        ->set('form.transactions', '10')
        ->set('form.units', '20')
        ->call('save')
        ->assertHasErrors(['form.sales_bs'])
        ->assertSee('Usa la coma para los decimales: 1.234,56.');
});

it('cambiar la fecha limpia lo escrito para el día anterior (M13)', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-01'])
        ->set('form.sales_bs', '91.154,02')
        ->set('form.transactions', '119')
        ->set('form.units', '300')
        ->set('form.inventory_units', '9029')
        ->set('form.inventory_value_usd', '21848,73')
        ->set('form.notes', 'Algo del primero')
        ->set('form.atypical', true)
        ->set('form.date', '2025-09-02')
        ->assertSet('form.sales_bs', '')
        ->assertSet('form.transactions', '')
        ->assertSet('form.units', '')
        ->assertSet('form.inventory_units', '')
        ->assertSet('form.inventory_value_usd', '')
        ->assertSet('form.notes', '')
        ->assertSet('form.atypical', false);
});

it('la clave del borrador incluye al usuario, la sede y la fecha (M13)', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);

    $this->actingAs($user)->get(route('records.create', ['date' => '2025-09-02']))
        ->assertOk()
        ->assertSee('user: '.$user->id, false);
});

it('un día cerrado se sigue viendo cerrado al editarlo (M11)', function (): void {
    $user = userWithRole(Role::Supervision);
    $branch = $user->branches->first();
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);
    $closed = app(RegisterClosedDay::class)->handle($branch->id, CarbonImmutable::parse('2025-09-07'), 'Feriado local', $user);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-07'])
        ->assertSet('form.closed', true)
        ->assertSee('Día cerrado: ese día no operó.')
        ->assertSee('Motivo: Feriado local')
        ->set('form.notes', 'Feriado nacional decretado')
        ->call('saveAnyway')
        ->assertHasNoErrors();

    expect($closed->fresh()->status)->toBe(DayStatus::Closed)
        ->and($closed->fresh()->notes)->toBe('Feriado nacional decretado');
});

it('registra un día cerrado aunque no haya tasa publicada, con la tasa escrita (M14)', function (): void {
    $user = userWithRole(Role::Operador);

    $component = Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-07'])
        ->assertSet('rateLabel', 'Sin tasa BCV: escríbela')
        ->call('registerClosed', 'Feriado local')
        ->assertNoRedirect()
        ->assertSet('error', 'Escribe la tasa para registrar el día cerrado.');

    $component->set('form.rate', '150,25')
        ->call('registerClosed', 'Feriado local')
        ->assertRedirect();

    $record = DailyRecord::query()->where('date', '2025-09-07')->firstOrFail();
    expect($record->status)->toBe(DayStatus::Closed)
        ->and((string) $record->exchange_rate)->toBe('150.2500')
        ->and($record->exchange_rate_source)->toBe(RateSource::Manual);
});

it('tras editar un día se vuelve al mes, no al primer faltante (M17)', function (): void {
    $user = userWithRole(Role::Supervision);
    $branch = $user->branches->first();
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-10', 'transactions' => 132, 'created_by' => $user->id]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-10'])
        ->set('form.transactions', '140')
        ->call('saveAnyway')
        ->assertHasNoErrors()
        ->assertRedirect(route('month', ['period' => Period::of(CarbonImmutable::parse('2025-09-10'))->key()]));
});

it('ocultar el inventario borra lo escrito en vez de guardarlo a escondidas (M18)', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-05', 'rate' => '152.82', 'source' => RateSource::Bcv]);

    // Sábado: el bloque viene plegado y el botón lo abre.
    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-06'])
        ->assertSet('showInventory', false)
        ->call('toggleInventory')
        ->assertSet('showInventory', true)
        ->set('form.inventory_units', '9029')
        ->set('form.inventory_value_usd', '21848,73')
        ->call('toggleInventory')
        ->assertSet('showInventory', false)
        ->assertSet('form.inventory_units', '')
        ->assertSet('form.inventory_value_usd', '')
        ->set('form.sales_bs', '95981,70')->set('form.transactions', '124')->set('form.units', '261')
        ->call('save')
        ->assertHasNoErrors();

    $record = DailyRecord::query()->where('date', '2025-09-06')->firstOrFail();
    expect($record->inventory_units)->toBeNull()
        ->and($record->inventory_value_usd)->toBeNull();
});

it('el motivo del día atípico pide al menos 10 caracteres en el campo (M19)', function (): void {
    $user = userWithRole(Role::Operador);
    ExchangeRate::query()->create(['date' => '2025-09-01', 'rate' => '148.44', 'source' => RateSource::Bcv]);

    Livewire::actingAs($user)->test(DailyForm::class, ['date' => '2025-09-01'])
        ->assertSee('al menos 10 caracteres')
        ->set('form.sales_bs', '91.154,02')
        ->set('form.transactions', '119')
        ->set('form.units', '300')
        ->set('form.atypical', true)
        ->set('form.notes', 'corto')
        ->call('save')
        ->assertHasErrors(['form.notes'])
        ->assertSee('Escribe al menos 10 caracteres para el motivo del día atípico.');

    expect(DailyRecord::query()->count())->toBe(0);
});

it('una fecha imposible en la URL da 404 en vez de abrir otro día (B16)', function (): void {
    $user = userWithRole(Role::Operador);

    $this->actingAs($user)->get('/cargar/2026-02-30')->assertNotFound();
    $this->actingAs($user)->get('/cargar/2025-13-01')->assertNotFound();
    $this->actingAs($user)->get('/cargar/hoy')->assertNotFound();
    $this->actingAs($user)->get('/cargar/2025-09-10')->assertOk();
});
