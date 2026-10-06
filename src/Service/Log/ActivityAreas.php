<?php

declare(strict_types=1);

namespace App\Service\Log;

/**
 * The Activity Log's "area" for an audited action (ADR-093). Module actions are "<area>.<what>" (rent.mark_paid,
 * vault.entry_create…); sign-in and account security actions carry no prefix (login, password_reset…).
 */
final class ActivityAreas
{
    public const SECURITY = 'security';

    /** prefix => label, in menu order; SECURITY covers every action without a prefix. */
    public const AREAS = [
        'client'       => 'Clients',
        'project'      => 'Projects',
        'task'         => 'Tasks',
        'invoice'      => 'Invoices',
        'rent'         => 'Rent',
        'expense'      => 'Expenses',
        'subscription' => 'Subscriptions',
        'vault'        => 'Password Manager',
        'admin'        => 'Users & administration',
        'config'       => 'Config',
        'settings'     => 'Settings',
        'logs'         => 'Logs',
        self::SECURITY => 'Sign-in & security',
    ];

    public static function of(string $action): string
    {
        $dot = strpos($action, '.');
        if ($dot === false) {
            return self::SECURITY;
        }
        $prefix = substr($action, 0, $dot);

        return isset(self::AREAS[$prefix]) ? $prefix : self::SECURITY;
    }

    public static function label(string $action): string
    {
        return self::AREAS[self::of($action)];
    }
}
