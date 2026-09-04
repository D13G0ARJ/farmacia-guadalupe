<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Aviso al administrador cuando la tasa automática se aparta más del umbral (RN-16, §9.2). */
class RateDeviationDetected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $date,
        public readonly string $previous,
        public readonly string $new,
        public readonly string $variation,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tasa BCV del {$this->date}: cambio de {$this->variation}")
            ->greeting('Hola')
            ->line("La tasa automática para el {$this->date} pasó de {$this->previous} a {$this->new} ({$this->variation}).")
            ->line('Se guardó igual para no frenar la carga diaria. Revísala y corrígela si hace falta.')
            ->action('Revisar la tasa', route('rates'));
    }
}
