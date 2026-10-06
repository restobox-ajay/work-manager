<?php

declare(strict_types=1);

namespace App\Config;

class GeneralConfigPage implements ConfigPageProviderInterface
{
    public const REGISTRATION_MODE_KEY = 'registration.mode';
    public const REGISTRATION_OPEN = 'open';
    public const REGISTRATION_INVITATION_ONLY = 'invitation-only';
    /** ADR-095: nobody signs themselves up; the owner invites anyone else. */
    public const DEFAULT_REGISTRATION_MODE = self::REGISTRATION_INVITATION_ONLY;

    public function getSlug(): string
    {
        return 'general';
    }

    public function getTitle(): string
    {
        return 'General';
    }

    public function getFields(): array
    {
        return [
            self::REGISTRATION_MODE_KEY => [
                'label'   => 'Registration Mode',
                'type'    => 'enum',
                'options' => [self::REGISTRATION_INVITATION_ONLY, self::REGISTRATION_OPEN],
                'default' => self::DEFAULT_REGISTRATION_MODE,
            ],
            'audit_log.retention_days' => [
                'label'   => 'Audit Log Retention (days)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '90',
            ],
            'login_notifications.enabled' => [
                'label'   => 'Enable Login Notifications',
                'type'    => 'bool',
                'default' => '1',
            ],
            'login_notifications.recognition_mode' => [
                'label'   => 'Login Notification Recognition Mode (fingerprint, ip_only)',
                'type'    => 'enum',
                'options' => ['fingerprint', 'ip_only'],
                'default' => 'fingerprint',
            ],
            'email_verification.mode' => [
                'label'   => 'Email Verification Mode (disabled, optional, required)',
                'type'    => 'enum',
                'options' => ['disabled', 'optional', 'required'],
                'default' => 'disabled',
            ],
            // ADR-051 / session lifetime: the sliding idle window every session gets. Read per request by
            // App\Session\SessionTtlResolver and applied as the PdoSessionHandler TTL, so a change takes effect
            // on the next request. Staying signed in longer is the remember-me cookie's job (lifetime below).
            'session.idle_lifetime_minutes' => [
                'label'   => 'Session Idle Lifetime (minutes)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '180',
            ],
            'remember_me.lifetime_days' => [
                'label'   => 'Remember Me Lifetime (days)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '30',
            ],
            'invitation.expiry_days' => [
                'label'   => 'Invitation Expiry (days)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '7',
            ],
            // FEATURE-147 / ADR-048: retention grace before app:prune reclaims a terminal
            // (expired-or-used) invitation. Invitations are not logs; provenance is in the audit log.
            'invitation.retention_days' => [
                'label'   => 'Invitation Retention (days)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '30',
            ],
        ];
    }
}
