<?php

declare(strict_types=1);

namespace App\Bundle\AuthImpersonation\Controller;

use App\Bundle\AuthImpersonation\Service\ImpersonationManager;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccountManagementPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The impersonation endpoints, owned by auth-impersonate-bundle (FEATURE-142 / ADR-041). Routes exist only
 * when the bundle is registered (App\Kernel::configureRoutes), so with the bundle absent they 404.
 */
class ImpersonationController extends AbstractController
{
    public function __construct(private readonly ImpersonationManager $impersonation)
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/admin/users/{id}/impersonate-start', name: 'app_admin_users_impersonate_start', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function start(int $id, Request $request, UserRepository $users, AccountManagementPolicy $policy): Response
    {
        $impersonator = $this->getUser();
        $target = $users->find($id);
        // A target the caller may not manage is indistinguishable from a missing one (ADR-050).
        if (!$impersonator instanceof User || $target === null || !$policy->canManage($impersonator, $target)) {
            throw $this->createNotFoundException('User not found.');
        }

        if (!$this->isCsrfTokenValid('admin_user_impersonate_' . $id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $refusal = $this->impersonation->start($impersonator, $target, $request->getSession(), $request->getClientIp() ?? '0.0.0.0');
        if ($refusal !== null) {
            $this->addFlash('error', $refusal);

            return $this->redirectToRoute('app_admin_users');
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/impersonate/exit', name: 'app_impersonate_exit', methods: ['POST'])]
    public function exit(Request $request): Response
    {
        $session = $request->getSession();
        // No impersonation in progress: a no-op, checked before CSRF because it changes nothing (review C26).
        if (!$this->impersonation->isImpersonating($session)) {
            return $this->redirectToRoute('app_dashboard');
        }

        if (!$this->isCsrfTokenValid('impersonate_exit', (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return $this->impersonation->exit($session, $request->getClientIp() ?? '0.0.0.0')
            ? $this->redirectToRoute('app_admin_users')
            : $this->redirectToRoute('app_login');
    }
}
