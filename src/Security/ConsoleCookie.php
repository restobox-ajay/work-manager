<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Helpers for the database console credential (ADR-053).
 *
 * The credential is an opaque, randomly generated token. It is NOT derived from the admin id, the
 * session, or any secret: it is unguessable on its own, stored server-side only as a hash, and looked
 * up per request by the gateway (public/db-admin.php). That is what lets it expire and be revoked —
 * there is a row to delete — unlike a deterministic signed cookie, which is the same value forever.
 *
 * Kept as small pure functions so the mint controller and the standalone gateway share them: the
 * gateway runs OUTSIDE the Symfony kernel, so anything it needs must work without the container.
 */
final class ConsoleCookie
{
    public const COOKIE_NAME = 'db_console';

    /** Default console window (minutes) when db_console.window_minutes is unset/invalid. */
    public const DEFAULT_WINDOW_MINUTES = 30;

    /** Config key holding the kill-switch deadline as a UNIX timestamp; absent/0 means OFF. */
    public const ENABLED_UNTIL_KEY = 'db_console.enabled_until';

    /** Config key holding the console window in minutes. */
    public const WINDOW_MINUTES_KEY = 'db_console.window_minutes';

    /** Console window in seconds, from a minutes value; falls back to the default when unusable. */
    public static function windowSeconds(int|string|null $minutes): int
    {
        $minutes = (int) $minutes;

        return ($minutes > 0 ? $minutes : self::DEFAULT_WINDOW_MINUTES) * 60;
    }

    /** A fresh 256-bit opaque token (64 hex chars). This raw value is handed to the client and nothing else. */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The stored form of a token. Only the hash is persisted, so a database leak does not yield a
     * usable credential. A fixed-length hash of a random 256-bit token needs no constant-time compare
     * — it is not a low-entropy secret, and the lookup is an indexed equality match, not a comparison.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
