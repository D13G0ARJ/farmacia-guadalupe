<?php

declare(strict_types=1);

use App\Domain\Imports\AnomalyDetector;
use App\Domain\Imports\ImportContext;
use App\Domain\Imports\WorkbookParser;
use App\Enums\Role;
use App\Livewire\Imports\ImportWizard;
use App\Models\DailyRecord;
use App\Models\ImportBatch;
use App\Models\User;
use App\Support\GuidedTours;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoGoalsSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

/** Anclas que solo agrupan botones para la verificación de cobertura; no llevan paso propio. */
const TOUR_CONTAINER_ANCHORS = ['chart-panel'];

/** Rutas con recorrido y una página de cada una (con datos de demostración cargados). */
function tourPages(): array
{
    return [
        'dashboard' => route('dashboard'),
        'records.create' => route('records.create', ['date' => '2025-09-10']),
        'month' => route('month', ['period' => '2025-09']),
        'charts' => route('charts'),
        'goals' => route('goals'),
        'annual' => route('annual'),
        'rates' => route('rates'),
        'imports' => route('imports'),
        'admin' => route('admin'),
        'profile' => route('profile'),
    ];
}

/** @return list<string> claves data-tour presentes en el HTML */
function tourAnchorsIn(string $html): array
{
    preg_match_all('/data-tour="([a-z0-9-]+)"/', $html, $m);

    return array_values(array_unique($m[1]));
}

function seedForTours(): User
{
    test()->seed([DatabaseSeeder::class, DemoSeeder::class, DemoGoalsSeeder::class, DemoUsersSeeder::class]);

    return User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
}

it('cada pantalla tiene su recorrido, con introducción, cierre en la ayuda y textos completos', function (): void {
    $admin = seedForTours();

    foreach (GuidedTours::catalog($admin) as $tour) {
        $steps = GuidedTours::for($tour['route'], $admin);
        expect(count($steps))->toBeGreaterThanOrEqual(6, "recorrido {$tour['route']} demasiado corto")
            ->and($steps[0]['element'])->toBeNull()
            ->and($steps[0]['title'])->toStartWith('Recorrido: ')
            ->and(end($steps)['element'])->toBe('help-button');
        foreach ($steps as $step) {
            expect(mb_strlen($step['description']))->toBeGreaterThanOrEqual(40, "paso «{$step['title']}» de {$tour['route']} explica poco")
                ->and($step)->not->toHaveKey('can');
        }
    }

    expect(GuidedTours::catalog($admin))->toHaveCount(10)
        ->and(GuidedTours::for('nada', $admin))->toBe([])
        ->and(GuidedTours::shellTour($admin))->toHaveCount(17);
});

it('ningún botón, enlace ni campo de las pantallas queda sin recorrido, y cada paso apunta a un ancla real', function (): void {
    $admin = seedForTours();
    $withSession = ['context.period' => '2025-09'];

    $missingAnchors = [];
    $unexplained = [];
    $uncovered = [];

    foreach (tourPages() as $route => $url) {
        $html = $this->actingAs($admin)->withSession($withSession)->get($url)->assertOk()->getContent();
        $anchors = tourAnchorsIn($html);
        $steps = GuidedTours::for($route, $admin);
        $stepKeys = array_values(array_filter(array_column($steps, 'element')));

        // Cada ancla de la página (fuera de las de solo agrupación) tiene un paso que la explica
        foreach ($anchors as $anchor) {
            if (in_array($anchor, TOUR_CONTAINER_ANCHORS, true) || str_starts_with($anchor, 'nav-') || str_starts_with($anchor, 'context-') || in_array($anchor, ['help-button', 'new-day-button'], true)) {
                continue; // el menú y la barra los explica el recorrido general
            }
            if (! in_array($anchor, $stepKeys, true)) {
                $unexplained[] = "{$route}: {$anchor}";
            }
        }

        // Cada paso apunta a un ancla que existe en la vista (aunque esté oculta por estado)
        $viewAnchors = $anchors;
        foreach ($stepKeys as $key) {
            if (! in_array($key, $viewAnchors, true) && ! in_array($key, GuidedTours::stateAnchors($route), true)) {
                $missingAnchors[] = "{$route}: {$key}";
            }
        }

        // Todo elemento interactivo de <main> está dentro de un ancla
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//main//button | //main//a[@href] | //main//input | //main//select | //main//textarea | //main//summary') ?: [] as $node) {
            $covered = false;
            for ($n = $node; $n instanceof DOMElement; $n = $n->parentNode) {
                if ($n->hasAttribute('data-tour')) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $label = trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');
                $uncovered[] = "{$route}: <{$node->tagName}> ".($label !== '' ? mb_substr($label, 0, 40) : ($node->getAttribute('id') ?: $node->getAttribute('aria-label')));
            }
        }
    }

    expect($unexplained)->toBe([], 'anclas sin paso: '.implode(' | ', $unexplained))
        ->and($missingAnchors)->toBe([], 'pasos sin ancla: '.implode(' | ', $missingAnchors))
        ->and(array_values(array_unique($uncovered)))->toBe([], 'sin recorrido: '.implode(' | ', array_unique($uncovered)));
});

it('las demostraciones de cada paso existen, y la limpieza de cada pantalla también', function (): void {
    $admin = seedForTours();

    foreach (GuidedTours::catalog($admin) as $tour) {
        foreach (GuidedTours::for($tour['route'], $admin) as $step) {
            foreach (['demo', 'undo'] as $field) {
                if (isset($step[$field])) {
                    expect(in_array($step[$field], GuidedTours::DEMOS, true))->toBeTrue("{$tour['route']}: demostración «{$step[$field]}» desconocida");
                }
            }
        }
        foreach (GuidedTours::cleanup($tour['route']) as $name) {
            expect(in_array($name, GuidedTours::DEMOS, true))->toBeTrue("limpieza «{$name}» desconocida");
        }
    }

    // Cada demostración declarada en PHP está implementada en JavaScript
    $js = file_get_contents(resource_path('js/demos.js'));
    foreach (GuidedTours::DEMOS as $name) {
        expect(str_contains($js, "'{$name}':"))->toBeTrue("demos.js no implementa {$name}");
    }
});

it('el cuadro de ejemplo del recorrido se descarga, es de enero 2020 y trae las dos anomalías a propósito', function (): void {
    $admin = seedForTours();

    $response = $this->actingAs($admin)->get(route('tours.sample'));
    $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $month = app(WorkbookParser::class)->parse(resource_path('samples/cuadro-ejemplo.xlsx'));
    expect($month->period)->toBe('2020-01')->and($month->rows)->toHaveCount(30);

    $anomalies = app(AnomalyDetector::class)->detect($month, new ImportContext(inventoryDays: [1, 2, 3, 4, 5], existingRecords: 0, existingRates: [], alreadyImportedAt: null));
    $types = array_map(fn ($a) => $a->type->value, $anomalies);
    expect($types)->toContain('duplicate_date', 'missing_day');
});

it('el cuadro de ejemplo exige sesión', function (): void {
    $this->get(route('tours.sample'))->assertRedirect(route('login'));
});

it('volver a empezar en el importador descarta lo analizado y no confirmado, como hace el recorrido con el cuadro de ejemplo', function (): void {
    $admin = seedForTours();
    $upload = UploadedFile::fake()->createWithContent('cuadro-ejemplo.xlsx', (string) file_get_contents(resource_path('samples/cuadro-ejemplo.xlsx')));

    $component = Livewire\Livewire::actingAs($admin)->test(ImportWizard::class)
        ->set('files', [$upload])
        ->call('analyze')
        ->assertSet('step', 2)
        ->assertSee('Enero 2020')
        ->assertSee('Fecha repetida');
    expect(ImportBatch::query()->count())->toBe(1);

    $component->call('restart')->assertSet('step', 1);

    expect(ImportBatch::query()->count())->toBe(0)
        ->and(DailyRecord::query()->where('date', '>=', '2020-01-01')->where('date', '<', '2020-02-01')->count())->toBe(0);
});

it('el recorrido respeta los permisos: el operador no ve pasos de cierre, reapertura ni metas', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $operator = userWithRole(Role::Operador);

    $month = array_column(GuidedTours::for('month', $operator), 'element');
    expect($month)->not->toContain('month-close', 'dialog-close-month', 'month-reopen', 'dialog-reopen-month')
        ->and($month)->toContain('month-calendar', 'month-table', 'month-search');

    $catalog = array_column(GuidedTours::catalog($operator), 'route');
    expect($catalog)->not->toContain('goals', 'rates', 'imports', 'admin')
        ->and($catalog)->toContain('dashboard', 'records.create', 'month', 'charts', 'annual', 'profile');

    $shell = array_column(GuidedTours::shellTour($operator), 'element');
    expect($shell)->not->toContain('nav-admin', 'nav-tasas', 'nav-importar', 'nav-metas');
});

it('el panel de ayuda expone los pasos de la pantalla y la lista de recorridos', function (): void {
    $admin = seedForTours();

    $this->actingAs($admin)->withSession(['context.period' => '2025-09'])->get(route('month'))
        ->assertOk()
        ->assertSee('id="guided-tour-data"', false)
        ->assertSee('Ver el recorrido de esta pantalla')
        ->assertSee('Cómo moverte por el sistema')
        ->assertSee('?recorrido=1', false)
        ->assertSee('Recorrido: Mes')
        ->assertSee('"route":"month"', false);
});
