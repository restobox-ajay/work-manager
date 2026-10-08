<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Service\ConfigService;
use App\Session\SessionTtlResolver;
use PHPUnit\Framework\TestCase;

/**
 * ADR-051 as amended by ADR-068: every session gets the DB-configured sliding idle window
 * (`session.idle_lifetime_minutes`); the admin-only "remember me" long session window is gone (staying signed in
 * is the remember-me cookie's job). These tests pin the resolution rules and the fail-safe behaviour.
 */
final class SessionTtlResolverTest extends TestCase
{
    /** @param array<string,string> $config */
    private function resolver(array $config): SessionTtlResolver
    {
        $configService = new class ($config) extends ConfigService {
            /** @param array<string,string> $values */
            public function __construct(private array $values) {}

            public function getInt(string $key, int $default = 0): int
            {
                return isset($this->values[$key]) ? (int) $this->values[$key] : $default;
            }
        };

        return new SessionTtlResolver($configService);
    }

    public function testDefaultIdleWindowIsThreeHours(): void
    {
        // Replaces PHP's 24-minute gc_maxlifetime.
        self::assertSame(180 * 60, $this->resolver([])->resolve());
    }

    public function testConfiguredValueOverridesTheDefault(): void
    {
        self::assertSame(45 * 60, $this->resolver(['session.idle_lifetime_minutes' => '45'])->resolve());
    }

    public function testTheRemovedRememberMeLifetimeKeyNoLongerAffectsTheWindow(): void
    {
        self::assertSame(180 * 60, $this->resolver(['session.remember_me_lifetime_days' => '7'])->resolve());
    }

    public function testNonPositiveConfiguredValuesAreFlooredNotZero(): void
    {
        // A 0/negative row must never yield a zero TTL (that would expire every session instantly).
        self::assertSame(60, $this->resolver(['session.idle_lifetime_minutes' => '0'])->resolve());
        self::assertSame(60, $this->resolver(['session.idle_lifetime_minutes' => '-5'])->resolve());
    }

    public function testAConfigFailureFallsBackToTheDefault(): void
    {
        $configService = new class extends ConfigService {
            public function __construct() {}

            public function getInt(string $key, int $default = 0): int
            {
                throw new \RuntimeException('database unavailable');
            }
        };

        self::assertSame(180 * 60, (new SessionTtlResolver($configService))->resolve());
    }

    public function testResolutionIsMemoised(): void
    {
        // Memoised so the handler's per-write call does not re-query config on every write.
        $configService = new class extends ConfigService {
            public int $calls = 0;

            public function __construct() {}

            public function getInt(string $key, int $default = 0): int
            {
                ++$this->calls;

                return $default;
            }
        };

        $resolver = new SessionTtlResolver($configService);
        $resolver->resolve();
        $resolver->resolve();

        self::assertSame(1, $configService->calls);
    }
}
