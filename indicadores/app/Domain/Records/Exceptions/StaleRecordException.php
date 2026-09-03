<?php

declare(strict_types=1);

namespace App\Domain\Records\Exceptions;

use App\Models\DailyRecord;

/** UC-03: bloqueo optimista; otro usuario editó el día después de que se cargó el formulario. */
final class StaleRecordException extends RecordException
{
    public static function for(DailyRecord $record): self
    {
        $who = $record->updated_by !== null ? $record->editor->name : $record->creator->name;
        $when = $record->updated_at?->locale('es')->diffForHumans() ?? 'hace un momento';

        return new self("{$who} editó este día {$when}. Revisa sus cambios antes de guardar.");
    }
}
