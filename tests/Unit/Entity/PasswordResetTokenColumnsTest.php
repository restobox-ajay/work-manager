<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PasswordResetToken;
use PHPUnit\Framework\TestCase;

/**
 * FEATURE-103 (review C8, C32): the reset-token fields and lifecycle live in the dumb-data trait
 * PasswordResetTokenColumns. Since ADR-068 removed the admin reset token, PasswordResetToken is its only user.
 */
final class PasswordResetTokenColumnsTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $expires = new \DateTimeImmutable('+1 hour');

        $token = new PasswordResetToken('user@example.com', 'hash-u', $expires);

        $this->assertNull($token->getId());
        $this->assertSame('user@example.com', $token->getEmail());
        $this->assertNotSame('', $token->getTokenHash());
        $this->assertSame($expires, $token->getExpiresAt());
        // C32 regression guard: getCreatedAt() must exist and be populated.
        $this->assertInstanceOf(\DateTimeImmutable::class, $token->getCreatedAt());
        $this->assertNull($token->getUsedAt());
    }

    public function testMarkUsedFlipsUsedState(): void
    {
        $token = new PasswordResetToken('u@example.com', 'h', new \DateTimeImmutable('+1 hour'));
        $this->assertFalse($token->isUsed());
        $this->assertNull($token->getUsedAt());

        $token->markUsed();

        $this->assertTrue($token->isUsed());
        $this->assertInstanceOf(\DateTimeImmutable::class, $token->getUsedAt());
    }

    public function testExpiryIsHonoured(): void
    {
        $future = new PasswordResetToken('u@example.com', 'h', new \DateTimeImmutable('+1 hour'));
        $past = new PasswordResetToken('a@example.com', 'h', new \DateTimeImmutable('-1 hour'));

        $this->assertFalse($future->isExpired());
        $this->assertTrue($past->isExpired());
    }

    public function testIsValidRequiresUnusedAndUnexpired(): void
    {
        $token = new PasswordResetToken('u@example.com', 'h', new \DateTimeImmutable('+1 hour'));
        $this->assertTrue($token->isValid());

        $token->markUsed();

        $this->assertFalse($token->isValid());
    }

    public function testExpiredTokenIsInvalidEvenWhenUnused(): void
    {
        $expired = new PasswordResetToken('u@example.com', 'h', new \DateTimeImmutable('-1 second'));

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($expired->isUsed());
        $this->assertFalse($expired->isValid());
    }
}
