<?php

declare(strict_types=1);

use App\Domain\Shared\Period;
use App\Livewire\Dashboard\Overview;
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

it('"Ver 4 indicadores más" recuerda si quedó abierto y cambia de texto (M29)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    app(PeriodContext::class)->set(Period::of('2025-09'));
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $html = Livewire::actingAs($admin)->test(Overview::class)
        ->assertSee('Ver 4 indicadores más')
        ->assertSeeHtml('data-tour="dash-more"')
        ->html();

    expect($html)->toContain("\$persist(false).as('dash-more')")
        ->and($html)->toContain('x-bind:open="open"')
        ->and($html)->toContain("open ? 'Ver menos' : 'Ver 4 indicadores más'");
});
