<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\AuditLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[AsEventListener(event: LoginSuccessEvent::class, method: 'onLoginSuccess')]
#[AsEventListener(event: LoginFailureEvent::class, method: 'onLoginFailure')]
#[AsEventListener(event: LogoutEvent::class, method: 'onLogout')]
class AuditLogSecurityListener
{
    use InteractiveFirewallTrait;

    public function __construct(private AuditLogger $auditLogger) {}

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->isInteractiveFirewall($event->getFirewallName())) {
            return;
        }

        $user = $event->getUser();
        $request = $event->getRequest();

        $actor = $user->getUserIdentifier();
        // One account type since ADR-068: signing in and out is always a user action, whatever the roles.
        $actorType = 'user';
        $ip = $request->getClientIp() ?? '0.0.0.0';

        $this->auditLogger->log($actor, $actorType, $ip, 'login', 'success');
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (!$this->isInteractiveFirewall($event->getFirewallName())) {
            return;
        }

        $request = $event->getRequest();
        $ip = $request->getClientIp() ?? '0.0.0.0';

        $actor = trim((string) $request->request->get('email', 'unknown')) ?: 'unknown';
        $actorType = 'user';

        $this->auditLogger->log($actor, $actorType, $ip, 'login', 'failure');
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if ($token === null) {
            return;
        }

        $request = $event->getRequest();
        $ip = $request->getClientIp() ?? '0.0.0.0';
        $user = $token->getUser();

        if ($user === null) {
            return;
        }

        $actor = $user->getUserIdentifier();
        $actorType = 'user';

        $this->auditLogger->log($actor, $actorType, $ip, 'logout', 'success');
    }
}
