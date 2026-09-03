<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Sede activa en la sesión (§4.2 principio 6, RN-22/23). `null` = consolidado, solo para quien ve todas.
 */
final class CurrentBranch
{
    private const KEY = 'context.branch_id';

    public function __construct(private readonly Session $session) {}

    /** Sede activa para el usuario; si no hay una válida en sesión, la primera accesible. */
    public function resolve(User $user): ?Branch
    {
        $accessible = $user->accessibleBranches();
        $stored = $this->session->get(self::KEY);

        if ($stored === 'all' && $user->canSeeAllBranches()) {
            return null;
        }

        $branch = is_numeric($stored) ? $accessible->firstWhere('id', (int) $stored) : null;
        $branch ??= $accessible->first();

        if ($branch !== null) {
            $this->session->put(self::KEY, $branch->id);
        }

        return $branch;
    }

    public function set(User $user, ?int $branchId): void
    {
        if ($branchId === null) {
            if ($user->canSeeAllBranches()) {
                $this->session->put(self::KEY, 'all');
            }

            return;
        }

        if ($user->accessibleBranches()->contains('id', $branchId)) {
            $this->session->put(self::KEY, $branchId);
        }
    }

    public function isConsolidated(): bool
    {
        return $this->session->get(self::KEY) === 'all';
    }
}
