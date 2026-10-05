<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\AccountStatus;

/**
 * Single shared validation contract for the role/status fields of a User write
 * (create + edit), used by both the web AdminUserController and the AdminApiUserController
 * so the two surfaces cannot drift (review C22 / FEATURE-115).
 *
 * A role must be one of User::ALLOWED_ROLES — an *unknown* submitted role is rejected, not silently
 * dropped. Whether the actor may GRANT it is App\Security\AccountManagementPolicy's call (ADR-068).
 * Status is validated against the AccountStatus enum.
 */
final class UserFieldValidator
{
    /**
     * @return string|null an error message when the role is not allowed, null when it is valid
     */
    public function validateRole(string $role): ?string
    {
        if (!in_array($role, User::ALLOWED_ROLES, true)) {
            return 'Invalid role.';
        }

        return null;
    }

    /**
     * @return string|null an error message when the status is unknown, null when it is valid
     */
    public function validateStatus(string $status): ?string
    {
        if (!AccountStatus::isValid($status)) {
            return 'Invalid status.';
        }

        return null;
    }
}
