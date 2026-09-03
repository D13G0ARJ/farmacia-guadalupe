<?php

declare(strict_types=1);

use App\Actions\Records\DeleteDailyRecord;
use App\Enums\Role;
use App\Livewire\Records\MonthOverview;
use App\Livewire\Shared\ContextBar;
use App\Models\DailyRecord;
use App\Models\User;
use App\Support\CurrencyContext;
use App\Support\PeriodContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('muestra septiembre con calendario, tabla y totales ponderados (UC-06, UC-09)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    Livewire::actingAs($admin)->test(MonthOverview::class, ['period' => '2025-09'])
        ->assertSee('Septiembre 2025')
        ->assertSee('Todos los días cargados')
        ->assertSee('Bs 3.012.770,86')
        ->assertSee('$ 18.611')
        ->assertSee('Bs 782') // ticket ponderado 781,93 → 0 decimales
        ->assertSee('Atípico')
        ->assertSee('Exportar a Excel')
        ->assertSee('Total del mes');
});

it('excluir atípicos cambia los promedios pero no las sumas', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    Livewire::actingAs($admin)->test(MonthOverview::class, ['period' => '2025-09'])
        ->set('excludeAtypical', true)
        ->assertSee('sin 1 atípico')
        ->assertSee('Bs 3.012.770,86')
        ->assertSee('Bs 781'); // 780,99
});

it('con días faltantes muestra el contador y el acceso directo al primero', function (): void {
    CarbonImmutable::setTestNow('2025-09-20 09:00:00');
    $user = userWithRole(Role::Operador);
    DailyRecord::factory()->for($user->branches->first())->create(['date' => '2025-09-01', 'created_by' => $user->id]);

    Livewire::actingAs($user)->test(MonthOverview::class, ['period' => '2025-09'])
        ->assertSee('Faltan 19 días')
        ->assertSee('Cargar el primero')
        ->assertSeeHtml(route('records.create', ['date' => '2025-09-02']));
});

it('un día cerrado se muestra con su insignia y sin ceros en la fila', function (): void {
    CarbonImmutable::setTestNow('2025-09-20 09:00:00');
    $user = userWithRole(Role::Operador);
    $branch = $user->branches->first();
    DailyRecord::factory()->for($branch)->closed()->create(['date' => '2025-09-01', 'created_by' => $user->id]);
    DailyRecord::factory()->for($branch)->create(['date' => '2025-09-02', 'sales_bs' => '91154.02', 'exchange_rate' => '148.44', 'created_by' => $user->id]);

    // La fila cerrada muestra guiones; el único "Bs 0,00" posible sería el suyo (el total no es cero).
    Livewire::actingAs($user)->test(MonthOverview::class, ['period' => '2025-09'])
        ->assertSee('Cerrado')
        ->assertSee('—')
        ->assertSee('Bs 91.154,02')
        ->assertDontSee('Bs 0,00');
});

it('cierra el mes desde la pantalla, muestra "Cerrado el", el historial y permite reabrir con motivo (UC-07)', function (): void {
    Notification::fake();
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $component = Livewire::actingAs($admin)->test(MonthOverview::class, ['period' => '2025-09'])
        ->assertSee('Cerrar septiembre')
        ->assertDontSee('Historial del mes')
        ->call('closeMonth')
        ->assertHasNoErrors()
        ->assertDispatched('month-state-changed')
        ->assertDispatched('toast')
        ->assertSee('Cerrado el 03/10')
        ->assertSee('Historial del mes')
        ->assertSee('Reabrir')
        ->assertDontSee('Cerrar septiembre');

    $component->set('reopenReason', 'x')->call('reopenMonth')->assertHasErrors(['reopenReason']);

    $component->set('reopenReason', 'Se cargó mal el 15')->call('reopenMonth')
        ->assertHasNoErrors()
        ->assertSee('Reabierto')
        ->assertSee('Se cargó mal el 15')
        ->assertSee('Cerrar septiembre')
        ->assertDontSee('Cerrado el 03/10');
});

it('con días faltantes el cierre exige confirmación explícita', function (): void {
    CarbonImmutable::setTestNow('2025-09-20 09:00:00');
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
    DailyRecord::query()->where('date', '>', '2025-09-18')->delete();

    $component = Livewire::actingAs($admin)->test(MonthOverview::class, ['period' => '2025-09'])
        ->assertSee('Cerrar de todos modos')
        ->call('closeMonth')
        ->assertHasErrors(['close'])
        ->assertSee('Faltan 2 días por cargar');

    $component->set('confirmMissing', true)->call('closeMonth')->assertHasNoErrors()->assertSee('Cerrado el 20/09')->assertSee('Cerrado con 2 días sin cargar');
});

it('supervisión cierra pero no reabre; el operador no ve ninguna de las dos acciones', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $supervisor = userWithRole(Role::Supervision);
    $operator = userWithRole(Role::Operador);

    Livewire::actingAs($operator)->test(MonthOverview::class, ['period' => '2025-09'])->assertDontSee('Cerrar septiembre');

    Livewire::actingAs($supervisor)->test(MonthOverview::class, ['period' => '2025-09'])
        ->assertSee('Cerrar septiembre')
        ->call('closeMonth')
        ->assertHasNoErrors()
        ->assertSee('Cerrado el')
        ->assertDontSee('Reabrir');
});

it('"Deshacer" tras borrar un día lo restaura desde la bitácora', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
    $record = DailyRecord::query()->where('date', '2025-09-10')->firstOrFail();
    app(DeleteDailyRecord::class)->handle($record, $admin);
    $activity = Activity::query()->where('event', 'deleted')->latest('id')->firstOrFail();
    expect(DailyRecord::query()->where('date', '2025-09-10')->exists())->toBeFalse();

    Livewire::actingAs($admin)->test(MonthOverview::class, ['period' => '2025-09'])
        ->dispatch('undo-delete', activity: $activity->id)
        ->assertDispatched('toast')
        ->assertSee('Todos los días cargados');

    $restored = DailyRecord::query()->where('date', '2025-09-10')->firstOrFail();
    expect((string) $restored->sales_bs)->toBe((string) $record->sales_bs)
        ->and($restored->transactions)->toBe($record->transactions);

    // Un segundo "deshacer" del mismo borrado no duplica el día
    Livewire::actingAs($admin)->test(MonthOverview::class, ['period' => '2025-09'])->dispatch('undo-delete', activity: $activity->id);
    expect(DailyRecord::query()->where('date', '2025-09-10')->count())->toBe(1);
});

it('sin datos muestra el estado vacío con la acción que lo resuelve', function (): void {
    $user = userWithRole(Role::Operador);

    Livewire::actingAs($user)->test(MonthOverview::class, ['period' => '2025-09'])
        ->assertSee('Aún no hay días cargados en septiembre 2025')
        ->assertSee('Cargar el primer día');
});

it('la moneda de la barra de contexto cambia las columnas de la tabla', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    Livewire::actingAs($admin)->test(ContextBar::class)->set('currency', CurrencyContext::USD)->assertDispatched('context-changed');

    Livewire::actingAs($admin)->test(MonthOverview::class, ['period' => '2025-09'])
        ->assertSee('Venta $')
        ->assertDontSee('Venta Bs');
});

it('la barra de contexto navega entre meses y persiste el período', function (): void {
    $admin = userWithRole(Role::Direccion);

    Livewire::actingAs($admin)->test(ContextBar::class)
        ->assertSet('period', '2025-10')
        ->call('previousPeriod')
        ->assertSet('period', '2025-09')
        ->assertDispatched('context-changed');

    expect(app(PeriodContext::class)->current()->key())->toBe('2025-09');
});

it('el operador aterriza en Mes y no en el panel de dirección (§13.8)', function (): void {
    $operador = userWithRole(Role::Operador);

    $this->actingAs($operador)->get(route('dashboard'))->assertRedirect(route('month'));
    $this->actingAs($operador)->get(route('month'))->assertOk();
});

it('las rutas del mes y del panel responden para un usuario con sede', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $this->actingAs($admin)->get(route('month', ['period' => '2025-09']))->assertOk()->assertSee('Cuadro de indicadores');
    $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Venta en dólares');
});
