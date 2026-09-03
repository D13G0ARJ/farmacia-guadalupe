<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Livewire\Actions\Logout;
use Illuminate\Http\RedirectResponse;

/** Cierre de sesión desde el formulario del layout. Controlador (no closure) para que `route:cache` funcione en producción. */
class LogoutController extends Controller
{
    public function __invoke(Logout $logout): RedirectResponse
    {
        $logout();

        return redirect('/');
    }
}
