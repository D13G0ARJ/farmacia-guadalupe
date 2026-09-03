<?php

declare(strict_types=1);

namespace App\Domain\Charts;

/**
 * Una gráfica lista para el navegador (§14.1): la opción de ECharts como arreglo serializable,
 * los textos ya formateados en es-VE (tooltips, tabla de datos) y las pistas de formato que el
 * JavaScript resuelve con los mismos helpers `fmt` (paridad con PHP).
 */
final readonly class ChartSpec
{
    /**
     * @param  array<string, mixed>  $option
     * @param  array{tooltips: list<string>, axes: list<array{format: string, currency?: string, precision: int}>, trigger: string, cellLabel?: string, visualMap?: array{format: string, currency?: string, precision: int}}  $meta
     * @param  array{head: list<string>, rows: list<list<string>>}  $table
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $subtitle,
        public string $slug,
        public array $option,
        public array $meta,
        public array $table,
        public bool $empty,
        public string $emptyText = '',
    ) {}

    /** @return array<string, mixed> forma que viaja como propiedad pública de Livewire */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'slug' => $this->slug,
            'option' => $this->option,
            'meta' => $this->meta,
            'table' => $this->table,
            'empty' => $this->empty,
            'emptyText' => $this->emptyText,
        ];
    }
}
