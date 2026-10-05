<?php

declare(strict_types=1);

namespace App\EventListener;

/**
 * Shared guard for security-event listeners that must only react to interactive,
 * session-backed form logins — never to stateless bearer-token (PAT) authentication.
 *
 * The stateless `api` firewall resolves principals from the same `app_users` provider as the
 * interactive `user` firewall, so an `instanceof User` check does NOT distinguish a real login
 * from an API call. The firewall name does (see review finding C2 / FEATURE-097).
 */
trait InteractiveFirewallTrait
{
    /** Firewalls whose logins are real, interactive, session-backed logins. */
    private const INTERACTIVE_FIREWALLS = ['user'];

    private function isInteractiveFirewall(string $firewallName): bool
    {
        return \in_array($firewallName, self::INTERACTIVE_FIREWALLS, true);
    }
}
