<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Indicators\PeriodSummary;
use App\Domain\Shared\Period;
use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Cache de agregados por (sede | consolidado, período, excluir atípicos) (§4.2 principio 7, §7.3).
 * Usa una versión por (sede, período) porque los drivers `file` y `database` no soportan tags:
 * invalidar es incrementar la versión, y las claves viejas expiran solas.
 */
final class PeriodSummaryCache
{
    private const TTL_SECONDS = 86400 * 7;

    public function __construct(private readonly Repository $cache) {}

    /** @param  Closure(): PeriodSummary  $compute */
    public function remember(?int $branchId, Period $period, bool $excludeAtypical, Closure $compute): PeriodSummary
    {
        $key = sprintf('summary:%d:%s:%s:%d', $this->version($branchId, $period), $this->scope($branchId), $period->key(), $excludeAtypical ? 1 : 0);

        // Se guarda como arreglo escalar: los stores reales no deserializan objetos (cache.serializable_classes).
        /** @var array<string, mixed> $data */
        $data = $this->cache->remember($key, self::TTL_SECONDS, static fn (): array => $compute()->toArray());

        return PeriodSummary::fromArray($data);
    }

    /** Invalida la sede y el consolidado para ese período. */
    public function forget(?int $branchId, Period $period): void
    {
        $this->cache->increment($this->versionKey($branchId, $period));

        if ($branchId !== null) {
            $this->cache->increment($this->versionKey(null, $period));
        }
    }

    private function version(?int $branchId, Period $period): int
    {
        $key = $this->versionKey($branchId, $period);
        $this->cache->add($key, 1, self::TTL_SECONDS);

        return (int) $this->cache->get($key, 1);
    }

    private function versionKey(?int $branchId, Period $period): string
    {
        return sprintf('summary:ver:%s:%s', $this->scope($branchId), $period->key());
    }

    private function scope(?int $branchId): string
    {
        return $branchId === null ? 'all' : (string) $branchId;
    }
}
