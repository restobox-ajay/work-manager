<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/** Carries an email's Email Log row id through the Messenger queue to the worker that sends it (ADR-093). */
final class EmailLogStamp implements StampInterface
{
    public function __construct(public readonly int $logId)
    {
    }
}
