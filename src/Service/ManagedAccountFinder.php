<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccountManagementPolicy;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Loads an account for a management action (web or API) only when the signed-in viewer may manage it.
 * A missing account and one the viewer may not manage are indistinguishable (both null), so a hidden
 * account — e.g. tech support — cannot be enumerated by probing ids (ADR-050 / ADR-068).
 */
final class ManagedAccountFinder
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountManagementPolicy $policy,
    ) {
    }

    public function find(int $id, ?UserInterface $viewer): ?User
    {
        if (!$viewer instanceof User) {
            return null;
        }

        $account = $this->users->find($id);

        return $account !== null && $this->policy->canManage($viewer, $account) ? $account : null;
    }

    /**
     * List filters that leave out the accounts the viewer may not see, merged into the caller's own filters.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function visibleFilters(?UserInterface $viewer, array $filters = []): array
    {
        $filters['excluded_roles'] = $viewer instanceof User ? $this->policy->hiddenRoles($viewer) : User::ALLOWED_ROLES;

        return $filters;
    }
}
