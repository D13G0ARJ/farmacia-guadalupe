<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles del sistema (§15.1). Los nombres son los que usa spatie/laravel-permission.
 */
enum Role: string
{
    case Operador = 'operador';
    case Supervision = 'supervision';
    case Direccion = 'direccion';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Operador => 'Operador',
            self::Supervision => 'Supervisión',
            self::Direccion => 'Dirección',
            self::Admin => 'Administrador',
        };
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Operador => [
                Permission::RecordsView, Permission::RecordsCreate, Permission::RecordsUpdate,
                Permission::RecordsMarkAtypical,
            ],
            self::Supervision => [
                Permission::RecordsView, Permission::RecordsCreate, Permission::RecordsUpdate,
                Permission::RecordsDelete, Permission::RecordsMarkAtypical, Permission::PeriodsClose,
                Permission::GoalsView, Permission::RatesManage, Permission::ImportsRun, Permission::ReportsExport,
            ],
            self::Direccion => [
                Permission::RecordsView, Permission::RecordsCreate, Permission::RecordsUpdate,
                Permission::RecordsDelete, Permission::RecordsMarkAtypical, Permission::PeriodsClose,
                Permission::PeriodsReopen, Permission::GoalsView, Permission::GoalsManage, Permission::RatesManage,
                Permission::ImportsRun, Permission::ReportsExport, Permission::BranchesAll,
            ],
            self::Admin => Permission::cases(),
        };
    }

    /** Pantalla de inicio por rol (§13.8). */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Operador => 'month',
            default => 'dashboard',
        };
    }
}
