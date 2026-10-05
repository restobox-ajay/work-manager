<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\AuditOutcome;
use App\Enum\PrincipalType;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Audit entries for client/project/task changes (ADR-070), written through the one audit sink (AuditLogger) with
 * the acting user and the request's IP filled in, so the services that record changes need neither.
 */
final class WorkAuditTrail
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** $action is "<record>.<verb>", e.g. "task.update"; $context names the record ("#12 Website redesign"). */
    public function record(User $actor, string $action, string $context): void
    {
        $this->auditLogger->log(
            $actor->getEmail(),
            PrincipalType::User->value,
            $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli',
            $action,
            AuditOutcome::Success->value,
            $context,
        );
    }
}
