<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\TestCase;

/**
 * FEATURE-131 (review C17 / ADR-029): the app must FAIL FAST at boot unless DEFAULT_URI is a
 * valid absolute URL and APP_DOMAIN is set (ADMIN_DOMAIN went away with the admin realm, ADR-068).
 * This guarantees no empty value can reach framework.trusted_hosts (an empty pattern would match
 * every host and silently disable host-header protection).
 *
 * Booting with a deliberately-broken host config would poison the shared in-process kernel, so
 * this is exercised out-of-process via bin/console. A real env var overrides the .env.test
 * default (Symfony Dotenv does not override real env vars), so passing e.g. APP_DOMAIN='' makes
 * the guard see an empty value.
 */
final class BootGuardTest extends TestCase
{
    /**
     * @param array<string, string> $envOverrides
     *
     * @return array{int, string}
     */
    private static function runConsole(array $envOverrides): array
    {
        $projectDir = \dirname(__DIR__, 2);
        // An argv array plus an explicit environment instead of a `VAR=x cmd` shell line, so this runs the
        // same under POSIX shells and cmd.exe. 'about' boots the kernel (so the boot guard runs) with minimal work.
        $process = proc_open(
            [PHP_BINARY, $projectDir . '/bin/console', 'about', '--env=test'],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $projectDir,
            array_merge(getenv(), $envOverrides),
        );
        self::assertIsResource($process, 'bin/console could not be started');

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    public function testBootSucceedsWithValidHostConfig(): void
    {
        // No overrides -> .env.test provides APP_DOMAIN=localhost and .env provides
        // DEFAULT_URI=http://localhost, so the guard passes and the kernel boots.
        [$exitCode, $output] = self::runConsole([]);

        self::assertSame(0, $exitCode, 'Kernel must boot with a valid host configuration. Output: ' . $output);
    }

    public function testBootFailsWhenAppDomainIsEmpty(): void
    {
        // Whitespace-only, which the guard trims to empty: Windows drops a truly empty variable from a child's
        // environment, so Dotenv would then refill it from .env.test and the guard would never see it blank.
        [$exitCode, $output] = self::runConsole(['APP_DOMAIN' => ' ']);

        self::assertNotSame(0, $exitCode, 'Kernel must refuse to boot with an empty APP_DOMAIN.');
        self::assertStringContainsString('APP_DOMAIN must be set', $output);
    }

    public function testBootFailsWhenDefaultUriIsNotAnAbsoluteUrl(): void
    {
        [$exitCode, $output] = self::runConsole(['DEFAULT_URI' => 'not-a-url']);

        self::assertNotSame(0, $exitCode, 'Kernel must refuse to boot when DEFAULT_URI is not an absolute URL.');
        self::assertStringContainsString('DEFAULT_URI must be a valid absolute URL', $output);
    }
}
