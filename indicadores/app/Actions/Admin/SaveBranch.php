<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Domain\Admin\Exceptions\AdminException;
use App\Models\Branch;
use App\Models\User;

/** Crea o actualiza una sede (UC-17). Siempre debe quedar al menos una activa. */
final class SaveBranch
{
    /**
     * @param  array{name: string, code: string, legal_name: string, default_shifts: int, inventory_days: list<int>, sales_deviation_pct: string|null, is_active: bool}  $data
     */
    public function handle(array $data, User $actor, ?Branch $branch = null): Branch
    {
        $isNew = $branch === null;
        $branch ??= new Branch;

        if (! $data['is_active'] && Branch::query()->where('is_active', true)->whereKeyNot($branch->id ?? 0)->doesntExist()) {
            throw AdminException::lastActiveBranch();
        }

        $days = array_values(array_unique(array_map('intval', $data['inventory_days'])));
        sort($days);

        $branch->fill([
            'name' => trim($data['name']),
            'code' => mb_strtoupper(trim($data['code'])),
            'legal_name' => trim($data['legal_name']),
            'default_shifts' => $data['default_shifts'],
            'inventory_days' => $days,
            'sales_deviation_pct' => $data['sales_deviation_pct'] === null || $data['sales_deviation_pct'] === '' ? null : $data['sales_deviation_pct'],
            'is_active' => $data['is_active'],
        ])->save();

        activity()
            ->performedOn($branch)
            ->causedBy($actor)
            ->event($isNew ? 'created' : 'updated')
            ->withProperties(['attributes' => $branch->only(['name', 'code', 'default_shifts', 'inventory_days', 'sales_deviation_pct', 'is_active'])])
            ->log($isNew ? 'created' : 'updated');

        return $branch;
    }
}
