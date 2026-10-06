<?php

declare(strict_types=1);

namespace App\Service\Vault;

/** A Password Manager request the server refuses (ADR-092); the message is safe to show, the code is the HTTP status. */
final class VaultRequestRefused extends \RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message, 422);
    }

    public static function notFound(): self
    {
        return new self('That entry no longer exists.', 404);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    /** The request did not prove the master password's auth key (ADR-094): a stale tab, or not the vault's owner. */
    public static function locked(): self
    {
        return new self('Your vault session is out of date. Lock the vault and unlock it again.', 403);
    }

    public static function tooManyAttempts(): self
    {
        return new self('Too many attempts. Wait a few minutes and try again.', 429);
    }
}
