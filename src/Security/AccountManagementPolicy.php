<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\Role;

/**
 * Who may see and manage which accounts, and which roles they may grant — the single source of truth for the
 * account-management surfaces (web and admin API alike). ADR-068 folded the separate admin realm into one
 * `user` table; this replaces the admin realm's TechSupportVisibility (ADR-050) and its "superadmins manage
 * admins, admins manage users" split.
 *
 * - An admin manages plain users only.
 * - A super admin manages everyone except tech-support accounts, and may grant up to Super Admin.
 * - Tech support manages everyone and may grant any role.
 *
 * An account the viewer may not manage is also one they may not see: callers turn !canManage() into the same
 * 404 as an unknown id, so a hidden (e.g. tech-support) account cannot be enumerated by probing ids.
 * Audit-log rows are deliberately NOT filtered — the trail keeps real actor emails for everyone.
 */
final class AccountManagementPolicy
{
    public function canManage(User $viewer, User $target): bool
    {
        // No special case for the viewer's own account: a plain admin edits themself on /account, not here,
        // so they can never demote or deactivate themself through a role list that lacks their own role.
        return \in_array($target->getPrimaryRole(), $this->manageableRoles($viewer), true);
    }

    /**
     * Roles the viewer may assign — the same set as the accounts they may manage.
     *
     * @return list<Role>
     */
    public function assignableRoles(User $viewer): array
    {
        return $this->manageableRoles($viewer);
    }

    public function canAssign(User $viewer, string $role): bool
    {
        $candidate = Role::tryFrom($role);

        return $candidate !== null && \in_array($candidate, $this->assignableRoles($viewer), true);
    }

    /**
     * Role strings whose holders the viewer may not see, for list queries (so paging and totals are right).
     *
     * @return list<string>
     */
    public function hiddenRoles(User $viewer): array
    {
        $manageable = $this->manageableRoles($viewer);

        return array_values(array_map(
            static fn (Role $role): string => $role->value,
            array_filter(Role::cases(), static fn (Role $role): bool => !\in_array($role, $manageable, true)),
        ));
    }

    /** @return list<Role> */
    private function manageableRoles(User $viewer): array
    {
        return match ($viewer->getPrimaryRole()) {
            Role::TechSupport => Role::cases(),
            Role::SuperAdmin => [Role::User, Role::Admin, Role::SuperAdmin],
            Role::Admin => [Role::User],
            Role::User => [],
        };
    }
}
