<?php

declare(strict_types=1);

use App\Actions\Periods\CloseMonth;
use App\Actions\Rates\FetchBcvRate;
use App\Console\Commands\SendMonthlyReport;
use App\Domain\Shared\Period;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Livewire\Admin\AdminPage;
use App\Mail\MonthlyReportMail;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MonthCloseReminder;
use App\Notifications\RateDeviationDetected;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoGoalsSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('envía el reporte mensual el día configurado, con el PDF adjunto y el resumen (§11.2)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoGoalsSeeder::class]);
    Mail::fake();
    Setting::put('report_email_day', 3);
    Setting::put('report_recipients', 'direccion@farmacia.com, contador@farmacia.com');

    $this->artisan('reports:send-monthly')->expectsOutputToContain('Reportes enviados: 1')->assertSuccessful();

    Mail::assertSent(MonthlyReportMail::class, function (MonthlyReportMail $mail): bool {
        $attachments = $mail->attachments();

        return $mail->hasTo('direccion@farmacia.com')
            && $mail->hasTo('contador@farmacia.com')
            && $mail->period->key() === '2025-09'
            && $mail->summary['days'] === 30
            && $mail->summary['salesUsd'] === '$ 18.611'
            && count($attachments) === 1
            && str_starts_with($mail->pdf, '%PDF')
            && $mail->envelope()->subject === 'Indicadores de septiembre 2025 · Sede Principal';
    });
});

it('no envía el reporte otro día, ni sin destinatarios; --force y --period lo mandan igual', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    Mail::fake();

    Setting::put('report_email_day', 0);
    $this->artisan('reports:send-monthly')->expectsOutputToContain('apagado')->assertSuccessful();
    Setting::put('report_email_day', 15);
    $this->artisan('reports:send-monthly')->expectsOutputToContain('no se envía')->assertSuccessful();
    Mail::assertNothingSent();

    $this->artisan('reports:send-monthly', ['--force' => true])->expectsOutputToContain('No hay destinatarios')->assertSuccessful();
    Mail::assertNothingSent();

    Setting::put('report_recipients', 'direccion@farmacia.com');
    $this->artisan('reports:send-monthly', ['--force' => true, '--period' => '2025-08'])->assertSuccessful();
    Mail::assertSent(MonthlyReportMail::class, fn (MonthlyReportMail $m) => $m->period->key() === '2025-08');

    $this->artisan('reports:send-monthly', ['--force' => true, '--period' => 'nada'])->assertFailed();

    expect(SendMonthlyReport::recipients("A@x.com, a@x.com;\nmalo b@y.org"))->toBe(['a@x.com', 'b@y.org']);
});

it('recuerda cerrar el mes anterior a quien puede cerrarlo, solo si tiene pendientes', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class, DemoUsersSeeder::class]);
    Notification::fake();

    // Septiembre 2025 está completo pero abierto: aviso a supervisión, dirección y admin, no al operador.
    $this->artisan('periods:remind-close')->expectsOutputToContain('Recordatorios enviados: 3')->assertSuccessful();
    Notification::assertSentTo(User::query()->where('email', 'supervision@guadalupe.local')->firstOrFail(), MonthCloseReminder::class, function (MonthCloseReminder $n): bool {
        $mail = $n->toMail(new User);

        return $n->periodKey === '2025-09' && $n->missing === 0 && $n->closed === false
            && str_contains($mail->subject ?? '', 'pendiente de cierre');
    });
    Notification::assertNotSentTo(User::query()->where('email', 'operador@guadalupe.local')->firstOrFail(), MonthCloseReminder::class);

    Setting::put('close_reminder_enabled', false);
    Notification::fake();
    $this->artisan('periods:remind-close')->expectsOutputToContain('apagado')->assertSuccessful();
    Notification::assertNothingSent();

    // Cerrado y completo: nada que recordar
    Setting::put('close_reminder_enabled', true);
    $branch = mainBranch();
    app(CloseMonth::class)->handle($branch, Period::of('2025-09'), User::query()->where('email', 'admin@guadalupe.local')->firstOrFail(), true);
    Notification::fake();
    $this->artisan('periods:remind-close')->expectsOutputToContain('Recordatorios enviados: 0')->assertSuccessful();
});

it('avisa por correo a quien gestiona la tasa cuando el BCV se desvía más del umbral (RN-16)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoUsersSeeder::class]);
    Notification::fake();
    ExchangeRate::query()->create(['date' => '2025-08-29', 'rate' => '148.00', 'source' => RateSource::Bcv]);
    Http::fake(['ve.dolarapi.com/*' => Http::response(['promedio' => 200])]);

    app(FetchBcvRate::class)->handle(CarbonImmutable::parse('2025-09-01'));

    Notification::assertSentTo(User::query()->where('email', 'admin@guadalupe.local')->firstOrFail(), RateDeviationDetected::class, function (RateDeviationDetected $n): bool {
        $mail = $n->toMail(new User);

        return $n->previous === '148,00' && $n->new === '200,00' && $n->variation === '+35,1 %'
            && str_contains($mail->subject ?? '', '01/09/2025');
    });
    Notification::assertNotSentTo(User::query()->where('email', 'operador@guadalupe.local')->firstOrFail(), RateDeviationDetected::class);
});

it('rates:fetch guarda hoy y el siguiente día hábil, y falla con claridad sin proveedor', function (): void {
    $this->seed(DatabaseSeeder::class);
    Http::fake(['ve.dolarapi.com/*' => Http::response(['promedio' => 150.5])]);

    $this->artisan('rates:fetch')->expectsOutputToContain('2025-10-03: 150.5000')->expectsOutputToContain('2025-10-06')->assertSuccessful();
    expect(ExchangeRate::query()->count())->toBe(2);
});

it('rates:fetch avisa con claridad cuando ninguna fuente responde', function (): void {
    $this->seed(DatabaseSeeder::class);
    Http::fake(['*' => Http::response('', 500)]);

    $this->artisan('rates:fetch', ['--date' => '2025-10-07'])->expectsOutputToContain('Sin cotización')->assertFailed();
    expect(ExchangeRate::query()->count())->toBe(0);
});

it('el operador no ve Administración › Correo pero el administrador guarda día y destinatarios', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    Livewire\Livewire::actingAs($admin)->test(AdminPage::class)
        ->set('tab', 'parametros')
        ->assertSee('Día del reporte mensual')
        ->set('settingsForm.report_email_day', 40)
        ->call('saveSettings')
        ->assertHasErrors(['settingsForm.report_email_day'])
        ->set('settingsForm.report_email_day', 5)
        ->set('settingsForm.report_recipients', 'direccion@farmacia.com, malo')
        ->call('saveSettings')
        ->assertHasErrors(['settingsForm.report_recipients'])
        ->set('settingsForm.report_recipients', 'Direccion@farmacia.com, contador@farmacia.com')
        ->set('settingsForm.close_reminder_enabled', false)
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect(Setting::get('report_email_day'))->toBe(5)
        ->and(Setting::get('report_recipients'))->toBe('direccion@farmacia.com, contador@farmacia.com')
        ->and(Setting::get('close_reminder_enabled'))->toBeFalse();

    $this->actingAs(userWithRole(Role::Operador))->get(route('admin'))->assertForbidden();
});
