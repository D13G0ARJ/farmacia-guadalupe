<x-app-layout>
    <div class="space-y-6">
        <div>
            <h1 class="text-title text-brand-800">Tu perfil</h1>
            <p class="text-ink-600">Tu nombre y tu contraseña. El correo y el rol los gestiona Administración.</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="profile-info-title">
                <livewire:profile.update-profile-information-form />
            </section>

            <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="profile-password-title">
                <livewire:profile.update-password-form />
            </section>
        </div>
    </div>
</x-app-layout>
