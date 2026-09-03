<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnlyCast;
use App\Enums\ImportStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un archivo importado (§10). `group_id` agrupa los subidos en la misma operación.
 *
 * @property int $id
 * @property string $group_id
 * @property int $branch_id
 * @property int $user_id
 * @property string $original_filename
 * @property string $file_hash
 * @property CarbonImmutable|null $period
 * @property ImportStatus $status
 * @property array<string, mixed>|null $summary
 * @property array<string, mixed>|null $parsed_payload
 */
#[Fillable([
    'group_id', 'branch_id', 'user_id', 'original_filename', 'file_hash', 'period', 'status', 'summary', 'parsed_payload',
])]
class ImportBatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => DateOnlyCast::class,
            'status' => ImportStatus::class,
            'summary' => 'array',
            'parsed_payload' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
