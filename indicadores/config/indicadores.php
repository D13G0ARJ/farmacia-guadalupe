<?php

declare(strict_types=1);

/*
 * Parámetros del sistema que dependen del entorno (§4.5). Los parámetros
 * operativos (umbrales, metas, periodicidad) viven en la tabla `settings`.
 */
return [

    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrador'),
        'email' => env('ADMIN_EMAIL', 'admin@guadalupe.local'),
        'password' => env('ADMIN_PASSWORD', 'password'),
    ],

    'rates' => [
        // bcv | null (todo manual). Ver §9.2.
        'provider' => env('RATES_PROVIDER', 'bcv'),
        // Fuente primaria: API comunitaria con el USD oficial del BCV.
        'primary_url' => env('RATES_PRIMARY_URL', 'https://ve.dolarapi.com/v1/dolares/oficial'),
        // Fuente de respaldo: la página del BCV (se extrae el valor del bloque "dolar").
        'fallback_url' => env('RATES_FALLBACK_URL', 'https://www.bcv.org.ve/'),
        'connect_timeout' => 3,
        'timeout' => 10,
        'retries' => 2,
    ],

];
