<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Bundle\AuthPat\Entity\PersonalAccessToken;
use PHPUnit\Framework\TestCase;

/**
 * FEATURE-103: the token fields and lifecycle live in the dumb-data trait AccessTokenColumns. Since ADR-068
 * removed the admin API token, PersonalAccessToken is its only user; this pins the trait's behaviour through it.
 */
final class AccessTokenColumnsTest extends TestCase
{
    public function testFreshTokenExposesItsFields(): void
    {
        $expires = new \DateTimeImmutable('+30 days');

        $token = new PersonalAccessToken(7, 'ci', 'hash-u', $expires);

        $this->assertNull($token->getId());
        $this->assertSame(7, $token->getUserId());
        $this->assertSame('ci', $token->getName());
        $this->assertNotSame('', $token->getTokenHash());
        $this->assertSame($expires, $token->getExpiresAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $token->getCreatedAt());
        $this->assertNull($token->getLastUsedAt());
        $this->assertNull($token->getRevokedAt());
        $this->assertFalse($token->isRevoked());
        $this->assertFalse($token->isExpired());
        $this->assertTrue($token->isActive());
    }

    public function testSetLastUsedAt(): void
    {
        $stamp = new \DateTimeImmutable('-5 minutes');
        $token = new PersonalAccessToken(1, 'ci', 'h');

        $token->setLastUsedAt($stamp);

        $this->assertSame($stamp, $token->getLastUsedAt());
    }

    public function testRevoke(): void
    {
        $token = new PersonalAccessToken(1, 'ci', 'h');
        $this->assertTrue($token->isActive());

        $token->revoke();

        $this->assertTrue($token->isRevoked());
        $this->assertNotNull($token->getRevokedAt());
        $this->assertFalse($token->isActive());
    }

    public function testExpiredTokenIsNotActive(): void
    {
        $token = new PersonalAccessToken(1, 'ci', 'h', new \DateTimeImmutable('-1 second'));

        $this->assertTrue($token->isExpired());
        $this->assertFalse($token->isActive());
    }

    public function testNullExpiryNeverExpires(): void
    {
        $token = new PersonalAccessToken(1, 'ci', 'h');

        $this->assertNull($token->getExpiresAt());
        $this->assertFalse($token->isExpired());
    }
}
