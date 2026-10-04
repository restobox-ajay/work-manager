<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ConsoleCookie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR-053. The db-console credential. (Resolving DATABASE_URL for the gateway is MysqlDsn's job — see
 * MysqlDsnTest.)
 */
final class ConsoleCookieTest extends TestCase
{
    public function testTokenIsA256BitHexValueAndNeverRepeats(): void
    {
        $a = ConsoleCookie::generateToken();
        $b = ConsoleCookie::generateToken();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        self::assertNotSame($a, $b, 'each mint must produce a fresh token');
    }

    public function testOnlyTheHashIsStorable(): void
    {
        $token = ConsoleCookie::generateToken();
        $hash = ConsoleCookie::hashToken($token);

        self::assertSame(hash('sha256', $token), $hash);
        self::assertNotSame($token, $hash, 'the raw token must never be the stored form');
        self::assertSame($hash, ConsoleCookie::hashToken($token), 'hashing must be deterministic');
    }

    /** @return array<string,array{0:int|string|null,1:int}> */
    public static function windows(): array
    {
        return [
            'configured'      => [45, 45 * 60],
            'numeric string'  => ['10', 10 * 60],
            'null'            => [null, ConsoleCookie::DEFAULT_WINDOW_MINUTES * 60],
            'zero'            => [0, ConsoleCookie::DEFAULT_WINDOW_MINUTES * 60],
            'negative'        => [-5, ConsoleCookie::DEFAULT_WINDOW_MINUTES * 60],
            'non-numeric'     => ['abc', ConsoleCookie::DEFAULT_WINDOW_MINUTES * 60],
        ];
    }

    #[DataProvider('windows')]
    public function testWindowSecondsNeverYieldsANonPositiveWindow(int|string|null $minutes, int $expected): void
    {
        // A zero/negative window would expire every session the instant it is minted.
        self::assertSame($expected, ConsoleCookie::windowSeconds($minutes));
    }
}
