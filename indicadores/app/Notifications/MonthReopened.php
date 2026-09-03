<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Shared\Period;
use App\Models\PeriodEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Aviso a dirección cuando alguien reabre un mes cerrado (UC-07). */
class MonthReopened extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PeriodEvent $event) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $period = Period::of($this->event->period);
        $branch = $this->event->branch->name;
        $who = $this->event->user->name;

        return (new MailMessage)
            ->subject($period->label().' fue reabierto en '.$branch)
            ->greeting('Hola')
            ->line("{$who} reabrió {$period->label()} en {$branch}.")
            ->line('Motivo: '.($this->event->reason ?? '—'))
            ->action('Ver el mes', route('month', ['period' => $period->key()]))
            ->line('Mientras esté abierto, sus días se pueden volver a editar.');
    }
}
