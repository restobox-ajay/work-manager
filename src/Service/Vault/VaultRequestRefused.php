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
}
