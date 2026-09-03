<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Permisos granulares (§15.1). Se sincronizan con la base de datos en el seeder.
 */
enum Permission: string
{
    case RecordsView = 'records.view';
    case RecordsCreate = 'records.create';
    case RecordsUpdate = 'records.update';
    case RecordsDelete = 'records.delete';
    case RecordsMarkAtypical = 'records.mark_atypical';
    case PeriodsClose = 'periods.close';
    case PeriodsReopen = 'periods.reopen';
    case GoalsView = 'goals.view';
    case GoalsManage = 'goals.manage';
    case RatesManage = 'rates.manage';
    case ImportsRun = 'imports.run';
    case ReportsExport = 'reports.export';
    case BranchesAll = 'branches.all';
    case AdminManage = 'admin.manage';
}
