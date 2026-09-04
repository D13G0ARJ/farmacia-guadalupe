<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Shared\Period;
use App\Models\Branch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Recordatorio de cierre (§13.8): el día 1, si el mes anterior tiene faltantes o no está cerrado. */
class MonthCloseReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Branch $branch,
        public readonly string $periodKey,
        public readonly int $missing,
        public readonly bool $closed,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $period = Period::of($this->periodKey);
        $label = $period->label();

        $mail = (new MailMessage)
            ->subject("{$label} en {$this->branch->name}: ".($this->closed ? 'días sin cargar' : 'pendiente de cierre'))
            ->greeting('Hola');

        if ($this->missing > 0) {
            $mail->line($this->missing === 1 ? "A {$label} le falta 1 día por cargar en {$this->branch->name}." : "A {$label} le faltan {$this->missing} días por cargar en {$this->branch->name}.");
        }
        if (! $this->closed) {
            $mail->line("{$label} todavía no está cerrado. Cerrarlo deja los indicadores fijos para las comparaciones.");
        }

        return $mail
            ->action('Ver el mes', route('month', ['period' => $period->key()]))
            ->line('Este recordatorio llega el día 1 mientras el mes anterior tenga pendientes.');
    }
}
