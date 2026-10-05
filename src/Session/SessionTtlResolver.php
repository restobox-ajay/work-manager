<?php

declare(strict_types=1);

namespace App\Session;

use App\Service\ConfigService;

/**
 * Resolves how long the CURRENT session stays valid, in seconds (ADR-051): the DB-backed
 * `session.idle_lifetime_minutes` (default 180 = 3h), a sliding idle window that replaces PHP's 24-minute
 * `session.gc_maxlifetime` default. Staying signed in for longer is the remember-me cookie's job.
 *
 * The value is applied as the PdoSessionHandler `ttl`, which re-stamps `sess_lifetime` on EVERY request
 * (PdoSessionHandler::updateTimestamp), so the window slides on activity with no refresh listener of our own.
 *
 * Because this runs inside the session write, it is deliberately defensive: it memoises per request (so
 * ConfigService is queried at most once, not once per write) and never throws — any failure falls back to
 * the default rather than breaking session persistence.
 */
final class SessionTtlResolver
{
    public const DEFAULT_IDLE_MINUTES = 180;

    private ?int $memo = null;

    public function __construct(
        private readonly ConfigService $configService,
    ) {}

    /** TTL in seconds for the current session. */
    public function resolve(): int
    {
        return $this->memo ??= $this->compute();
    }

    private function compute(): int
    {
        try {
            $minutes = $this->configService->getInt('session.idle_lifetime_minutes', self::DEFAULT_IDLE_MINUTES);

            return max(1, $minutes) * 60;
        } catch (\Throwable) {
            // Never let a config/DB hiccup break session writes — fall back to the default.
            return self::DEFAULT_IDLE_MINUTES * 60;
        }
    }
}
