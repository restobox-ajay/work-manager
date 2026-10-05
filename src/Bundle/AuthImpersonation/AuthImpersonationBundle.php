<?php

declare(strict_types=1);

namespace App\Bundle\AuthImpersonation;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * auth-impersonate-bundle — "sign in as this account" for account managers, packaged as an OPTIONAL Symfony
 * bundle (FEATURE-142). It owns the start/exit endpoints, the ImpersonationManager and its /config sub-page.
 * There is no table: impersonation is session-key based (ADR-014).
 *
 * Since ADR-068 there is one account type and one firewall, so impersonating is a token swap on that firewall
 * (the impersonator is restored by identifier on exit) rather than a hand-off between two firewalls.
 *
 * When it is NOT registered none of its services/routes/config sub-page exist (404), and the impersonate
 * buttons hide behind the bundle's `impersonation_available` Twig global. See ADR-041.
 */
final class AuthImpersonationBundle extends Bundle
{
}
