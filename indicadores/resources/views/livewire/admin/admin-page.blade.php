<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-title text-brand-800">Administración</h1>
            <p class="text-ink-600">Quién entra, con qué rol, en qué sede, y cómo se comporta el sistema.</p>
        </div>
        @if ($tab === 'usuarios')
            <x-btn icon="plus" wire:click="openUser">Nuevo usuario</x-btn>
        @elseif ($tab === 'sedes')
            <x-btn icon="plus" wire:click="openBranch">Nueva sede</x-btn>
        @endif
    </div>

    <div class="flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Secciones de administración">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" id="tab-{{ $key }}" wire:click="$set('tab', '{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}" aria-controls="panel-{{ $key }}"
                    class="-mb-px whitespace-nowrap border-b-2 px-4 py-2.5 text-body font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 {{ $tab === $key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-600 hover:text-ink-900' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Credenciales recién emitidas: se muestran una sola vez, para entregarlas en persona --}}
    @if ($issued)
        <div class="flex flex-wrap items-start gap-3 rounded-card border border-success-100 bg-success-100/60 p-4" role="status" x-data="{ copied: false }">
            <x-lucide name="key" class="mt-0.5 h-5 w-5 shrink-0 text-success-600" />
            <div class="min-w-0 flex-1 space-y-1">
                <p class="font-medium text-ink-900">Entrégale estos datos a {{ $issued['name'] }}. La contraseña no se vuelve a mostrar.</p>
                <p class="text-body text-ink-600">Correo: <span class="font-medium text-ink-900">{{ $issued['email'] }}</span> · Contraseña: <span class="font-medium tnum text-ink-900" data-testid="issued-password">{{ $issued['password'] }}</span></p>
                <p class="text-label text-ink-400">Podrá cambiarla en su perfil cuando entre.</p>
            </div>
            <div class="flex shrink-0 gap-2">
                {{-- El texto viaja en un atributo de datos: nada de directivas ni comillas dentro de atributos de componente --}}
                <x-btn variant="secondary" icon="copy" data-copy="Correo: {{ $issued['email'] }} · Contraseña: {{ $issued['password'] }}" x-on:click="navigator.clipboard?.writeText($el.dataset.copy); copied = true; setTimeout(() => copied = false, 2000)"><span x-text="copied ? 'Copiado' : 'Copiar'"></span></x-btn>
                <x-btn variant="ghost" wire:click="dismissIssued">Listo</x-btn>
            </div>
        </div>
    @endif

    {{-- ===================== Usuarios ===================== --}}
    @if ($tab === 'usuarios')
        <section id="panel-usuarios" role="tabpanel" aria-labelledby="tab-usuarios" class="overflow-x-auto rounded-card border border-line bg-surface">
            <table class="w-full min-w-[720px] border-collapse text-body">
                <thead class="bg-panel text-label text-ink-600">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Usuario</th>
                        <th class="px-4 py-2 text-left font-medium">Rol</th>
                        <th class="px-4 py-2 text-left font-medium">Sedes</th>
                        <th class="px-4 py-2 text-left font-medium">Acceso</th>
                        <th class="px-4 py-2 text-right font-medium">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($users as $user)
                        @php $role = \App\Enums\Role::tryFrom((string) $user->roles->first()?->name); @endphp
                        <tr wire:key="user-{{ $user->id }}" class="{{ $user->is_active ? '' : 'text-ink-400' }}">
                            <td class="px-4 py-3">
                                <p class="font-medium {{ $user->is_active ? 'text-ink-900' : '' }}">{{ $user->name }}@if ($user->is($me)) <span class="text-label text-ink-400">(tú)</span>@endif</p>
                                <p class="text-label text-ink-600">{{ $user->email }}</p>
                            </td>
                            <td class="px-4 py-3"><x-badge :tone="$role === \App\Enums\Role::Admin ? 'accent' : ($role === \App\Enums\Role::Direccion ? 'brand' : 'neutral')">{{ $role?->label() ?? 'Sin rol' }}</x-badge></td>
                            <td class="px-4 py-3 text-ink-600">{{ $user->canSeeAllBranches() ? 'Todas' : ($user->branches->pluck('name')->join(', ') ?: '—') }}</td>
                            <td class="px-4 py-3">
                                @if ($user->is_active)
                                    <x-badge tone="success" icon="check">Activo</x-badge>
                                @else
                                    <x-badge tone="neutral" icon="power">Desactivado</x-badge>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1">
                                    <x-btn variant="ghost" icon="pencil" wire:click="openUser({{ $user->id }})" class="min-h-[36px] px-2.5 text-label">Editar</x-btn>
                                    <x-btn variant="ghost" icon="key" wire:click="openPassword({{ $user->id }})" class="min-h-[36px] px-2.5 text-label">Contraseña</x-btn>
                                    @unless ($user->is($me))
                                        <x-btn variant="ghost" icon="power" wire:click="toggleUser({{ $user->id }})" wire:loading.attr="disabled" class="min-h-[36px] px-2.5 text-label {{ $user->is_active ? 'text-danger-600 hover:bg-danger-100' : '' }}">{{ $user->is_active ? 'Desactivar' : 'Activar' }}</x-btn>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <x-dialog show="$wire.userDialog" id="user-dialog" :title="$userForm->id ? 'Editar usuario' : 'Nuevo usuario'" max-width="max-w-lg">
            <div class="space-y-4">
                <x-field label="Nombre" for="user-name" :error="$errors->first('userForm.name')">
                    <x-input id="user-name" type="text" wire:model="userForm.name" autocomplete="off" :invalid="$errors->has('userForm.name')" />
                </x-field>
                <x-field label="Correo" for="user-email" :error="$errors->first('userForm.email')" help="Con él entra al sistema y recupera su contraseña.">
                    <x-input id="user-email" type="email" wire:model="userForm.email" autocomplete="off" :invalid="$errors->has('userForm.email')" />
                </x-field>
                <x-field label="Rol" for="user-role" :error="$errors->first('userForm.role')" :help="$roleHelp[$userForm->role] ?? null">
                    <select id="user-role" wire:model.live="userForm.role" class="block w-full rounded-control border-line bg-surface px-3 py-2.5 text-body focus:border-brand-500 focus:ring-2 focus:ring-brand-500">
                        @foreach ($roles as $r)
                            <option value="{{ $r->value }}">{{ $r->label() }}</option>
                        @endforeach
                    </select>
                </x-field>
                <div>
                    <p class="text-label font-medium text-ink-600">Sedes</p>
                    @if ($userForm->needsBranches())
                        <div class="mt-1.5 flex flex-wrap gap-3">
                            @foreach ($branches as $branch)
                                <label class="flex items-center gap-2 text-body"><input type="checkbox" wire:model="userForm.branch_ids" value="{{ $branch->id }}" class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">{{ $branch->name }}</label>
                            @endforeach
                        </div>
                        @error('userForm.branch_ids')<p class="mt-1 text-label text-danger-600" role="alert">{{ $message }}</p>@enderror
                    @else
                        <p class="mt-1 text-label text-ink-400">Este rol ve todas las sedes.</p>
                    @endif
                </div>
                <x-field :label="$userForm->id ? 'Contraseña nueva (opcional)' : 'Contraseña inicial'" for="user-password" :error="$errors->first('userForm.password')" :help="$userForm->id ? 'Déjala vacía para no cambiarla.' : 'Se la entregas en persona; podrá cambiarla en su perfil.'">
                    <div class="flex gap-2">
                        <x-input id="user-password" type="text" wire:model="userForm.password" autocomplete="new-password" class="tnum" :invalid="$errors->has('userForm.password')" />
                        <x-btn variant="secondary" icon="refresh" wire:click="suggestPassword" title="Generar otra">Generar</x-btn>
                    </div>
                </x-field>
            </div>
            <x-slot:actions>
                <x-btn variant="ghost" x-on:click="$wire.userDialog = false">Cancelar</x-btn>
                <x-btn wire:click="saveUser" wire:loading.attr="disabled" wire:target="saveUser">{{ $userForm->id ? 'Guardar cambios' : 'Crear usuario' }}</x-btn>
            </x-slot:actions>
        </x-dialog>

        <x-dialog show="$wire.passwordDialog" id="password-dialog" title="Cambiar contraseña">
            <p>Se reemplaza la contraseña actual. Entrégale la nueva en persona.</p>
            <x-field label="Contraseña nueva" for="new-password" :error="$errors->first('newPassword')">
                <div class="flex gap-2">
                    <x-input id="new-password" type="text" wire:model="newPassword" autocomplete="new-password" class="tnum" :invalid="$errors->has('newPassword')" />
                    <x-btn variant="secondary" icon="refresh" wire:click="$set('newPassword', '{{ \App\Actions\Admin\SetUserPassword::suggest() }}')" title="Generar otra">Generar</x-btn>
                </div>
            </x-field>
            <x-slot:actions>
                <x-btn variant="ghost" x-on:click="$wire.passwordDialog = false">Cancelar</x-btn>
                <x-btn wire:click="savePassword" wire:loading.attr="disabled" wire:target="savePassword">Cambiar contraseña</x-btn>
            </x-slot:actions>
        </x-dialog>
    @endif

    {{-- ===================== Sedes ===================== --}}
    @if ($tab === 'sedes')
        <section id="panel-sedes" role="tabpanel" aria-labelledby="tab-sedes" class="grid gap-4 md:grid-cols-2">
            @foreach ($branches as $branch)
                <article wire:key="branch-{{ $branch->id }}" class="rounded-card border border-line bg-surface p-5 {{ $branch->is_active ? '' : 'opacity-70' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-sub font-semibold text-ink-900">{{ $branch->name }}</h2>
                            <p class="text-label text-ink-600">{{ $branch->code }} · {{ $branch->legal_name }}</p>
                        </div>
                        @if ($branch->is_active)
                            <x-badge tone="success" icon="check">Activa</x-badge>
                        @else
                            <x-badge tone="neutral" icon="power">Inactiva</x-badge>
                        @endif
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-body">
                        <dt class="text-ink-600">Jornadas por defecto</dt><dd class="tnum text-ink-900">{{ $branch->default_shifts }}</dd>
                        <dt class="text-ink-600">Inventario se cuenta</dt><dd class="text-ink-900">{{ collect($branch->inventory_days ?? [])->map(fn ($d) => ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'][$d - 1] ?? $d)->join(', ') }}</dd>
                        <dt class="text-ink-600">Umbral de venta</dt><dd class="tnum text-ink-900">{{ $branch->sales_deviation_pct === null ? 'El global' : (int) $branch->sales_deviation_pct.' %' }}</dd>
                        <dt class="text-ink-600">Días cargados</dt><dd class="tnum text-ink-900">{{ $branch->daily_records_count }}</dd>
                    </dl>
                    <div class="mt-4 flex justify-end">
                        <x-btn variant="secondary" icon="pencil" wire:click="openBranch({{ $branch->id }})">Editar</x-btn>
                    </div>
                </article>
            @endforeach
        </section>

        <x-dialog show="$wire.branchDialog" id="branch-dialog" :title="$branchForm->id ? 'Editar sede' : 'Nueva sede'" max-width="max-w-lg">
            <div class="space-y-4">
                <x-field label="Nombre" for="branch-name" :error="$errors->first('branchForm.name')">
                    <x-input id="branch-name" type="text" wire:model="branchForm.name" :invalid="$errors->has('branchForm.name')" />
                </x-field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Código" for="branch-code" :error="$errors->first('branchForm.code')" help="Corto, por ejemplo GUA-02.">
                        <x-input id="branch-code" type="text" wire:model="branchForm.code" :invalid="$errors->has('branchForm.code')" />
                    </x-field>
                    <x-field label="Jornadas por defecto" for="branch-shifts" :error="$errors->first('branchForm.default_shifts')" help="Se precarga al cargar el día.">
                        <x-input id="branch-shifts" numeric inputmode="numeric" wire:model="branchForm.default_shifts" :invalid="$errors->has('branchForm.default_shifts')" />
                    </x-field>
                </div>
                <x-field label="Razón social" for="branch-legal" :error="$errors->first('branchForm.legal_name')" help="Va en el encabezado de los reportes.">
                    <x-input id="branch-legal" type="text" wire:model="branchForm.legal_name" :invalid="$errors->has('branchForm.legal_name')" />
                </x-field>
                <div>
                    <p class="text-label font-medium text-ink-600">Días en que se cuenta inventario</p>
                    <div class="mt-1.5 flex flex-wrap gap-3">
                        @foreach (['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'] as $i => $day)
                            <label class="flex items-center gap-1.5 text-body"><input type="checkbox" wire:model="branchForm.inventory_days" value="{{ $i + 1 }}" class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">{{ $day }}</label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-label text-ink-400">Los días sin conteo no exigen inventario al cargar.</p>
                </div>
                <x-field label="Umbral de advertencia de venta (%)" for="branch-threshold" :error="$errors->first('branchForm.sales_deviation_pct')" help="Vacío usa el parámetro global.">
                    <x-input id="branch-threshold" numeric inputmode="numeric" wire:model="branchForm.sales_deviation_pct" placeholder="Global" :invalid="$errors->has('branchForm.sales_deviation_pct')" />
                </x-field>
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="branchForm.is_active" class="mt-1 h-5 w-5 rounded border-line text-brand-600 focus:ring-brand-500">
                    <span><span class="font-medium text-ink-900">Sede activa</span><br><span class="text-label text-ink-600">Una sede inactiva no aparece en el selector ni recibe cargas; sus datos se conservan.</span></span>
                </label>
                @error('branchForm.is_active')<p class="text-label text-danger-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <x-slot:actions>
                <x-btn variant="ghost" x-on:click="$wire.branchDialog = false">Cancelar</x-btn>
                <x-btn wire:click="saveBranch" wire:loading.attr="disabled" wire:target="saveBranch">{{ $branchForm->id ? 'Guardar cambios' : 'Crear sede' }}</x-btn>
            </x-slot:actions>
        </x-dialog>
    @endif

    {{-- ===================== Parámetros ===================== --}}
    @if ($tab === 'parametros')
        <form id="panel-parametros" role="tabpanel" aria-labelledby="tab-parametros" wire:submit="saveSettings" class="space-y-6">
            <section class="rounded-card border border-line bg-surface p-5">
                <h2 class="text-sub font-semibold text-ink-900">Advertencias al cargar el día</h2>
                <p class="mt-1 text-label text-ink-600">Nunca bloquean: avisan y piden confirmar.</p>
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <x-field label="Desvío de la venta (%)" for="s-sales" :error="$errors->first('settingsForm.sales_deviation_pct')" help="Si la venta del día se aleja más de este porcentaje del promedio de los últimos 14 días, se avisa.">
                        <x-input id="s-sales" numeric inputmode="numeric" wire:model="settingsForm.sales_deviation_pct" :invalid="$errors->has('settingsForm.sales_deviation_pct')" />
                    </x-field>
                    <x-field label="Desvío de la tasa (%)" for="s-rate" :error="$errors->first('settingsForm.rate_deviation_pct')" help="Si la tasa escrita se aleja más de esto de la del día anterior, se avisa.">
                        <x-input id="s-rate" numeric inputmode="numeric" wire:model="settingsForm.rate_deviation_pct" :invalid="$errors->has('settingsForm.rate_deviation_pct')" />
                    </x-field>
                </div>
            </section>

            <section class="rounded-card border border-line bg-surface p-5">
                <h2 class="text-sub font-semibold text-ink-900">Edición</h2>
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <x-field label="Ventana del operador (días)" for="s-window" :error="$errors->first('settingsForm.operator_edit_window_days')" help="El operador solo edita días de hasta este número de días atrás. Supervisión y dirección no tienen límite.">
                        <x-input id="s-window" numeric inputmode="numeric" wire:model="settingsForm.operator_edit_window_days" :invalid="$errors->has('settingsForm.operator_edit_window_days')" />
                    </x-field>
                </div>
            </section>

            <section class="rounded-card border border-line bg-surface p-5">
                <h2 class="text-sub font-semibold text-ink-900">Metas</h2>
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <x-field label="Crecimiento sugerido (%)" for="s-growth" :error="$errors->first('settingsForm.goal_growth_pct')" help="La sugerencia de meta parte del mes anterior más este porcentaje.">
                        <x-input id="s-growth" numeric inputmode="numeric" wire:model="settingsForm.goal_growth_pct" :invalid="$errors->has('settingsForm.goal_growth_pct')" />
                    </x-field>
                    <x-field label="Moneda de las metas" for="s-currency" :error="$errors->first('settingsForm.goal_currency')" help="En qué moneda se definen las metas de venta.">
                        <select id="s-currency" wire:model="settingsForm.goal_currency" class="block w-full rounded-control border-line bg-surface px-3 py-2.5 text-body focus:border-brand-500 focus:ring-2 focus:ring-brand-500">
                            <option value="USD">Dólares ($)</option>
                            <option value="BS">Bolívares (Bs)</option>
                        </select>
                    </x-field>
                    <x-field label="En meta a partir de (%)" for="s-ontrack" :error="$errors->first('settingsForm.goal_on_track_pct')" help="La proyección de cierre igual o mayor a esto se muestra en verde.">
                        <x-input id="s-ontrack" numeric inputmode="numeric" wire:model="settingsForm.goal_on_track_pct" :invalid="$errors->has('settingsForm.goal_on_track_pct')" />
                    </x-field>
                    <x-field label="En riesgo a partir de (%)" for="s-atrisk" :error="$errors->first('settingsForm.goal_at_risk_pct')" help="Entre este valor y el anterior se muestra en ámbar; por debajo, en rojo.">
                        <x-input id="s-atrisk" numeric inputmode="numeric" wire:model="settingsForm.goal_at_risk_pct" :invalid="$errors->has('settingsForm.goal_at_risk_pct')" />
                    </x-field>
                </div>
            </section>

            <section class="rounded-card border border-line bg-surface p-5">
                <h2 class="text-sub font-semibold text-ink-900">Otros</h2>
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <x-field label="Nombre del sistema" for="s-name" :error="$errors->first('settingsForm.app_name')" help="Aparece en la pestaña del navegador y en los reportes.">
                        <x-input id="s-name" type="text" wire:model="settingsForm.app_name" :invalid="$errors->has('settingsForm.app_name')" />
                    </x-field>
                    <x-field label="Margen bruto (%)" for="s-margin" :error="$errors->first('settingsForm.gross_margin_pct')" help="Opcional. Con él se activan la rotación y los días de inventario.">
                        <x-input id="s-margin" numeric wire:model="settingsForm.gross_margin_pct" placeholder="Sin definir" :invalid="$errors->has('settingsForm.gross_margin_pct')" />
                    </x-field>
                </div>
            </section>

            <div class="flex justify-end">
                <x-btn type="submit" wire:loading.attr="disabled" wire:target="saveSettings">Guardar parámetros</x-btn>
            </div>
        </form>
    @endif

    {{-- ===================== Bitácora ===================== --}}
    @if ($tab === 'bitacora')
        <section id="panel-bitacora" role="tabpanel" aria-labelledby="tab-bitacora" class="space-y-4">
            <div class="flex flex-wrap items-end gap-3">
                <x-field label="Mostrar" for="log-type" class="w-48">
                    <select id="log-type" wire:model.live="logType" class="block w-full rounded-control border-line bg-surface px-3 py-2.5 text-body focus:border-brand-500 focus:ring-2 focus:ring-brand-500">
                        @foreach ($logTypes as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field label="Quién" for="log-search" class="w-64">
                    <x-input id="log-search" type="search" wire:model.live.debounce.400ms="logSearch" placeholder="Nombre de la persona" />
                </x-field>
            </div>

            @if ($entries === [])
                <div class="rounded-card border border-dashed border-line bg-surface px-6 py-10 text-center text-ink-400">Nada en la bitácora con ese filtro.</div>
            @else
                <ul class="divide-y divide-line rounded-card border border-line bg-surface">
                    @foreach ($entries as $entry)
                        <li wire:key="log-{{ $entry['id'] }}" class="px-4 py-3" x-data="{ open: false }">
                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <span class="text-label tnum text-ink-400">{{ $entry['when'] }}</span>
                                <p class="text-body text-ink-900"><span class="font-medium">{{ $entry['who'] }}</span> {{ $entry['verb'] }} {{ $entry['subject'] }}</p>
                                @if ($entry['changes'] !== [])
                                    <button type="button" x-on:click="open = ! open" class="text-label text-brand-700 hover:underline" x-text="open ? 'Ocultar cambios' : 'Ver cambios ({{ count($entry['changes']) }})'"></button>
                                @endif
                            </div>
                            @if ($entry['changes'] !== [])
                                <dl x-cloak x-show="open" class="mt-2 grid gap-x-4 gap-y-1 text-label sm:grid-cols-[auto_1fr]">
                                    @foreach ($entry['changes'] as $change)
                                        <dt class="text-ink-600">{{ $change['label'] }}</dt>
                                        <dd class="tnum text-ink-900"><span class="text-ink-400 line-through">{{ $change['old'] }}</span> → {{ $change['new'] }}</dd>
                                    @endforeach
                                </dl>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($hasMore)
                    <div class="flex justify-center"><x-btn variant="secondary" wire:click="loadMore" wire:loading.attr="disabled">Ver más</x-btn></div>
                @endif
            @endif
        </section>
    @endif
</div>
