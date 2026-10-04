<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AdminPasswordResetToken;
use App\Repository\AdminPasswordResetTokenRepository;
use App\Repository\AdminRepository;
use App\Repository\DbConsoleSessionRepository;
use App\Security\EndpointRateLimiterInterface;
use App\Security\PasswordPolicyManagerInterface;
use App\Service\AdminPasswordResetService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Self-service password reset for Admin accounts (ADR-009), on the `admin` firewall.
 * Mirrors the hardened user reset: rate-limited (above CSRF), CSRF-protected, anti-
 * enumeration (always the same confirmation), single-use 1-hour hashed tokens, sibling
 * tokens invalidated on success. The password change invalidates any live admin session
 * via Admin::isEqualTo.
 */
class AdminPasswordResetController extends AbstractController
{
    #[Route('/admin/forgot-password', name: 'app_admin_forgot_password', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        AdminRepository $adminRepository,
        AdminPasswordResetService $resetService,
        AuditLogger $auditLogger,
        EndpointRateLimiterInterface $rateLimiter,
    ): Response {
        if ($request->isMethod('POST')) {
            $ip = $request->getClientIp() ?? '0.0.0.0';
            // Throttle ABOVE the CSRF check so a missing token can't bypass it.
            if ($rateLimiter->tooManyAttempts('admin_forgot_password', $ip)) {
                return $this->render('admin/security/forgot_password.html.twig', [
                    'error' => 'Too many requests. Please try again later.',
                ]);
            }

            if (!$this->isCsrfTokenValid('admin_forgot_password', (string) $request->request->get('_token', ''))) {
                return $this->render('admin/security/forgot_password.html.twig', [
                    'error' => 'Invalid security token. Please try again.',
                ]);
            }

            $email = (string) $request->request->get('email', '');
            $admin = $adminRepository->findByEmail($email);

            if ($admin !== null) {
                $resetService->sendResetLink($admin);
                $auditLogger->log($email, 'admin', $ip, 'admin.password_reset_request', 'success');
            }

            // Always the same confirmation, regardless of whether the admin exists.
            return $this->redirectToRoute('app_admin_forgot_password_check');
        }

        return $this->render('admin/security/forgot_password.html.twig');
    }

    #[Route('/admin/forgot-password/check', name: 'app_admin_forgot_password_check', methods: ['GET'])]
    public function check(): Response
    {
        return $this->render('admin/security/forgot_password_check.html.twig');
    }

    #[Route('/admin/reset-password/{token}', name: 'app_admin_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        string $token,
        Request $request,
        EntityManagerInterface $em,
        AdminRepository $adminRepository,
        UserPasswordHasherInterface $passwordHasher,
        PasswordPolicyManagerInterface $passwordPolicy,
        AdminPasswordResetTokenRepository $resetTokenRepository,
        AuditLogger $auditLogger,
        DbConsoleSessionRepository $consoles,
    ): Response {
        $tokenHash = hash('sha256', $token);
        $resetToken = $resetTokenRepository->findOneBy(['tokenHash' => $tokenHash]);

        if ($resetToken === null || !$resetToken->isValid()) {
            return $this->render('admin/security/reset_password.html.twig', [
                'error' => $resetToken?->isExpired()
                    ? 'This password reset link has expired. Please request a new one.'
                    : 'This password reset link is invalid or has already been used.',
                'token' => null,
            ]);
        }

        // Reject consumption for a deactivated / soft-deleted admin (FEATURE-102 / ADR-020):
        // a token issued before deactivation cannot reset the password of an inactive admin.
        // Covers both the GET form and the POST submit; deactivation also proactively kills tokens.
        $account = $adminRepository->findByEmail($resetToken->getEmail());
        if ($account === null || !$account->isActive()) {
            return $this->render('admin/security/reset_password.html.twig', [
                'error' => 'This password reset link is no longer valid.',
                'token' => null,
            ]);
        }

        if ($request->isMethod('POST')) {
            $newPassword = (string) $request->request->get('password', '');

            $policyErrors = $newPassword === '' ? ['Password is required.'] : $passwordPolicy->validate($newPassword);
            if ($policyErrors !== []) {
                return $this->render('admin/security/reset_password.html.twig', [
                    'error' => implode(' ', $policyErrors),
                    'token' => $token,
                ]);
            }

            $admin = $adminRepository->findByEmail($resetToken->getEmail());
            if ($admin === null) {
                return $this->render('admin/security/reset_password.html.twig', [
                    'error' => 'Admin account not found.',
                    'token' => null,
                ]);
            }

            $admin->setPassword($passwordHasher->hashPassword($admin, $newPassword));
            $resetToken->markUsed();
            $em->flush();

            // Invalidate sibling tokens from earlier requests.
            $resetTokenRepository->invalidateOtherUnusedTokens($resetToken->getEmail(), $resetToken->getId());

            $auditLogger->log($admin->getEmail(), 'admin', $request->getClientIp() ?? '0.0.0.0', 'admin.password_reset', 'success');
            // A reset is what you do after a compromise: close any open DB console too (issue #48).
            $consoles->deleteAllByAdminId((int) $admin->getId());

            $this->addFlash('success', 'Your password has been reset. You can now log in.');

            return $this->redirectToRoute('app_admin_login');
        }

        return $this->render('admin/security/reset_password.html.twig', [
            'error' => null,
            'token' => $token,
        ]);
    }
}
