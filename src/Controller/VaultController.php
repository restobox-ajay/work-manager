<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Vault\VaultRequestRefused;
use App\Service\Vault\VaultService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Password Manager (ADR-092), admins only, each with their own vault. The page is a single screen that encrypts and
 * decrypts in the browser (public/js/vault.js); these JSON endpoints only move ciphertext. Every response is
 * no-store, and the page runs under a strict Content-Security-Policy so injected script cannot read the open vault
 * (inline styles stay allowed: the shell's markup uses style attributes, and CSS cannot read JavaScript memory).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/vault')]
final class VaultController extends AbstractWorkController
{
    private const CSRF_ID = 'vault_api';
    private const CSP = "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; "
        ."img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'";

    public function __construct(private readonly VaultService $vault)
    {
    }

    #[Route('', name: 'app_vault_index', methods: ['GET'])]
    public function index(): Response
    {
        $response = $this->render('vault/index.html.twig', ['csrfId' => self::CSRF_ID, 'minIterations' => VaultService::MIN_ITERATIONS]);
        $response->headers->set('Content-Security-Policy', self::CSP);

        return $this->noStore($response);
    }

    #[Route('/api/state', name: 'app_vault_state', methods: ['GET'])]
    public function state(): JsonResponse
    {
        return $this->noStore(new JsonResponse($this->vault->state($this->viewer())));
    }

    #[Route('/api/setup', name: 'app_vault_setup', methods: ['POST'])]
    public function setUp(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $data) {
            $this->vault->setUp($this->viewer(), $data);

            return ['ok' => true];
        });
    }

    #[Route('/api/master-password', name: 'app_vault_master_password', methods: ['POST'])]
    public function masterPassword(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $data) {
            $this->vault->rewrap($this->viewer(), $data);

            return ['ok' => true];
        });
    }

    #[Route('/api/entries', name: 'app_vault_entry_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->handle($request, fn (array $data) => $this->vault->create($this->viewer(), $data), 201);
    }

    #[Route('/api/entries/{id}', name: 'app_vault_entry_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        return $this->handle($request, fn (array $data) => $this->vault->update($this->viewer(), $id, $data));
    }

    #[Route('/api/entries/{id}', name: 'app_vault_entry_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        return $this->handle($request, function () use ($id) {
            $this->vault->delete($this->viewer(), $id);

            return ['ok' => true];
        });
    }

    /** Deleting the whole vault needs the account password, so a hijacked session cannot wipe it in one request. */
    #[Route('/api/reset', name: 'app_vault_reset', methods: ['POST'])]
    public function reset(Request $request, UserPasswordHasherInterface $hasher): JsonResponse
    {
        return $this->handle($request, function (array $data) use ($hasher) {
            $user = $this->viewer();
            if (!is_string($data['password'] ?? null) || !$hasher->isPasswordValid($user, $data['password'])) {
                throw VaultRequestRefused::invalid('That is not your account password.');
            }

            return ['ok' => true, 'deleted' => $this->vault->reset($user)];
        });
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $action */
    private function handle(Request $request, callable $action, int $status = 200): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->headers->get('X-CSRF-Token', ''))) {
            return $this->noStore(new JsonResponse(['error' => 'Your session expired. Reload the page.'], 403));
        }
        try {
            $data = $request->getContent() === '' ? [] : $request->toArray();
            $response = new JsonResponse($action($data), $status);
        } catch (\JsonException) {
            $response = new JsonResponse(['error' => 'The request was not valid JSON.'], 400);
        } catch (VaultRequestRefused $refused) {
            $response = new JsonResponse(['error' => $refused->getMessage()], $refused->getCode());
        }

        return $this->noStore($response);
    }

    /** @template T of Response @param T $response @return T */
    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
