<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\ExportController;
use App\Livewire\Charts\ChartsPage;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Goals\GoalsManager;
use App\Livewire\Records\DailyForm;
use App\Livewire\Records\MonthOverview;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', Overview::class)->name('dashboard');
    Route::get('cargar/{date?}', DailyForm::class)->name('records.create');
    Route::get('mes/{period?}', MonthOverview::class)->name('month');
    Route::get('graficas', ChartsPage::class)->name('charts');
    Route::get('metas', GoalsManager::class)->name('goals');
    Route::get('exportar/mes/{period}', [ExportController::class, 'month'])->name('exports.month');

    Route::view('profile', 'profile')->name('profile');

    // Cierre de sesión desde el formulario del layout (el stack Livewire de Breeze no lo define).
    Route::post('logout', LogoutController::class)->name('logout');
});

require __DIR__.'/auth.php';
