<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Shared\Period;
use App\Models\Branch;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reporte mensual por correo (§11.2): el PDF del mes adjunto, sin gráficas (se generan en el
 * navegador), con el resumen en el cuerpo y el enlace al panel.
 */
class MonthlyReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array{days: int, salesUsd: string, salesBs: string, transactions: string, missing: int, closed: bool}  $summary
     */
    public function __construct(
        public readonly Period $period,
        public readonly Branch $branch,
        public readonly array $summary,
        public readonly string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Indicadores de '.mb_strtolower($this->period->label()).' · '.$this->branch->name);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.monthly-report', with: [
            'periodLabel' => $this->period->label(),
            'branchName' => $this->branch->name,
            'summary' => $this->summary,
            'url' => route('dashboard'),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, 'reporte-'.$this->period->key().'.pdf')->withMime('application/pdf'),
        ];
    }
}
