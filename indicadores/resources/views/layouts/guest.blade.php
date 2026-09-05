<!DOCTYPE html>
<html lang="es" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#0F3F8F">
        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    {{-- Acceso (UC-01, §13.4): el único lugar, junto al PDF, donde vive el wordmark. La marca se expresa
         aquí a pantalla completa; dentro de la aplicación vuelve a ser solo la cruz de la barra. --}}
    <body class="min-h-dvh bg-surface font-sans text-body text-ink-900 antialiased">
        <div class="flex min-h-dvh flex-col lg:flex-row">

            {{-- Panel de marca: azul de la farmacia, retícula de cruces y el pie violeta del logotipo. --}}
            <aside class="brand-panel relative isolate flex flex-col justify-between overflow-hidden px-6 py-7 text-white sm:px-10 sm:py-10 lg:w-[46%] lg:max-w-[640px] lg:shrink-0 lg:px-14 lg:py-14">
                {{-- Retícula de cruces: la marca repetida a escala de textura, desvanecida para no competir con el texto. --}}
                <svg class="pointer-events-none absolute inset-0 -z-10 h-full w-full" aria-hidden="true"
                     style="mask-image:linear-gradient(150deg,rgba(0,0,0,.9) 0%,rgba(0,0,0,.35) 55%,transparent 92%);-webkit-mask-image:linear-gradient(150deg,rgba(0,0,0,.9) 0%,rgba(0,0,0,.35) 55%,transparent 92%)">
                    <defs>
                        <pattern id="reticula-cruz" width="104" height="104" patternUnits="userSpaceOnUse" patternTransform="rotate(14)">
                            <path d="M14 4h8v10h10v8H22v10h-8V22H4v-8h10z" fill="#FFFFFF" fill-opacity="0.06" />
                        </pattern>
                    </defs>
                    <rect width="100%" height="100%" fill="url(#reticula-cruz)" />
                </svg>
                {{-- Una sola cruz sobredimensionada, trazada a hairline: la marca como arquitectura del panel. --}}
                <svg class="pointer-events-none absolute -bottom-20 -right-20 -z-10 hidden h-[440px] w-[440px] lg:block" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                    <path d="M11.5 2.5h9v9h9v9h-9v9h-9v-9h-9v-9h9z" stroke="#FFFFFF" stroke-opacity="0.16" stroke-width="0.11" stroke-linejoin="round" />
                    <path d="M14.5 5.5h3v9h9v3h-9v9h-3v-9h-9v-3h9z" stroke="#FFFFFF" stroke-opacity="0.10" stroke-width="0.11" stroke-linejoin="round" />
                </svg>

                <a href="{{ route('login') }}" wire:navigate class="inline-flex w-fit items-center gap-3.5 rounded-card focus-visible:ring-offset-brand-800 sm:gap-4" aria-label="Farmacia Guadalupe, indicadores">
                    <x-brand-mark class="h-10 w-10 shrink-0 sm:h-12 sm:w-12" />
                    <x-brand-wordmark size="lg" />
                </a>

                <div class="mt-7 sm:mt-8 lg:mt-0">
                    {{-- El violeta del pie de la cruz, convertido en la regla que abre el mensaje. --}}
                    <span class="mb-5 hidden h-[3px] w-12 rounded-full bg-[#8E77E6] lg:block" aria-hidden="true"></span>
                    <p class="max-w-[24ch] text-[25px] font-semibold leading-[31px] tracking-[-0.015em] sm:text-[30px] sm:leading-[36px] lg:text-[38px] lg:leading-[44px]">
                        Los números del mes, claros desde el primer día.
                    </p>
                    <p class="mt-3 hidden max-w-[46ch] text-body text-brand-100/80 sm:block lg:mt-4 lg:text-sub">
                        El cuadro de indicadores de la farmacia, en un solo lugar y siempre al día.
                    </p>

                    <ul class="mt-9 hidden space-y-3.5 lg:block" role="list">
                        @foreach ([
                            'Siete números al día: cargar la jornada toma menos de un minuto.',
                            'El mes en una frase: cuánto llevas, cómo cierra y qué falta.',
                            'Metas, comparativa anual y el reporte listo para enviar.',
                        ] as $punto)
                            <li class="flex items-start gap-3 text-body text-brand-100">
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10 text-white">
                                    <x-lucide name="check" class="h-3 w-3" />
                                </span>
                                <span class="max-w-[42ch]">{{ $punto }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="mt-8 hidden text-label text-brand-100/70 sm:block lg:mt-0">
                    Farmacia Guadalupe, C.A. · Sistema interno de indicadores
                </p>
            </aside>

            {{-- Formulario: superficie blanca, ancho de lectura corto, todo el aire del sistema. --}}
            <main class="flex flex-1 items-start justify-center px-5 py-10 sm:px-8 sm:py-12 lg:items-center lg:px-12">
                <div class="auth-enter w-full max-w-[400px]">
                    {{ $slot }}

                    <div class="mt-10 border-t border-line pt-5 text-label text-ink-400">
                        <p class="flex items-center gap-2">
                            <x-lucide name="lock" class="h-3.5 w-3.5 shrink-0" />
                            Acceso solo para el personal autorizado.
                        </p>
                        <p class="mt-1.5 sm:hidden">Farmacia Guadalupe, C.A. · Sistema interno de indicadores</p>
                    </div>
                </div>
            </main>
        </div>
    </body>
</html>
