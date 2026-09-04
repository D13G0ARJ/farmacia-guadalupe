<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Models\Branch;
use Illuminate\Validation\Rule;
use Livewire\Form;

/** Alta y edición de sedes en Administración (UC-17). */
class BranchForm extends Form
{
    public ?int $id = null;

    public string $name = '';

    public string $code = '';

    public string $legal_name = '';

    public int $default_shifts = 3;

    /** @var list<int> */
    public array $inventory_days = Branch::DEFAULT_INVENTORY_DAYS;

    public string $sales_deviation_pct = '';

    public bool $is_active = true;

    public function fillFromBranch(Branch $branch): void
    {
        $this->id = $branch->id;
        $this->name = $branch->name;
        $this->code = $branch->code;
        $this->legal_name = $branch->legal_name;
        $this->default_shifts = $branch->default_shifts;
        $this->inventory_days = array_map('intval', $branch->inventory_days ?? Branch::DEFAULT_INVENTORY_DAYS);
        $this->sales_deviation_pct = $branch->sales_deviation_pct === null ? '' : (string) (int) $branch->sales_deviation_pct;
        $this->is_active = $branch->is_active;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'code' => ['required', 'string', 'max:20', Rule::unique('branches', 'code')->ignore($this->id)],
            'legal_name' => ['required', 'string', 'max:160'],
            'default_shifts' => ['required', 'integer', 'between:1,6'],
            'inventory_days' => ['array'],
            'inventory_days.*' => ['integer', 'between:1,7'],
            'sales_deviation_pct' => ['nullable', 'integer', 'between:5,100'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre de la sede.',
            'code.required' => 'Escribe un código corto, por ejemplo GUA-02.',
            'code.unique' => 'Ya hay una sede con ese código.',
            'legal_name.required' => 'Escribe la razón social: va en el encabezado de los reportes.',
            'default_shifts.between' => 'Las jornadas por defecto van de 1 a 6.',
            'sales_deviation_pct.between' => 'El umbral va de 5 a 100 por ciento.',
            'sales_deviation_pct.integer' => 'Escribe un número entero de por ciento.',
        ];
    }

    /** @return array{name: string, code: string, legal_name: string, default_shifts: int, inventory_days: list<int>, sales_deviation_pct: string|null, is_active: bool} */
    public function toData(): array
    {
        return [
            'name' => $this->name,
            'code' => $this->code,
            'legal_name' => $this->legal_name,
            'default_shifts' => $this->default_shifts,
            'inventory_days' => array_map('intval', $this->inventory_days),
            'sales_deviation_pct' => $this->sales_deviation_pct === '' ? null : $this->sales_deviation_pct,
            'is_active' => $this->is_active,
        ];
    }
}
