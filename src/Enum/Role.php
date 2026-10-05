<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The roles a User can hold (ADR-068: one account type; access is decided by roles).
 *
 * The order is the privilege ladder, mirrored by security.yaml's role_hierarchy: each role includes every
 * role below it. A user stores exactly one of these (their highest); ROLE_USER is implied for everyone.
 *
 * ROLE_TECH_SUPPORT is the hidden maintainer tier (ADR-050): its holders are invisible to everyone else on
 * the account-management surfaces and must use 2FA.
 */
enum Role: string
{
    case User = 'ROLE_USER';
    case Admin = 'ROLE_ADMIN';
    case SuperAdmin = 'ROLE_SUPER_ADMIN';
    case TechSupport = 'ROLE_TECH_SUPPORT';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    public function rank(): int
    {
        return match ($this) {
            self::User => 0,
            self::Admin => 1,
            self::SuperAdmin => 2,
            self::TechSupport => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Admin => 'Admin',
            self::SuperAdmin => 'Super Admin',
            self::TechSupport => 'Tech Support',
        };
    }

    /**
     * The highest role in a set of role strings (unknown strings are ignored); ROLE_USER when none is known.
     *
     * @param iterable<string> $roles
     */
    public static function highestOf(iterable $roles): self
    {
        $highest = self::User;
        foreach ($roles as $role) {
            $candidate = self::tryFrom($role);
            if ($candidate !== null && $candidate->rank() > $highest->rank()) {
                $highest = $candidate;
            }
        }

        return $highest;
    }
}
