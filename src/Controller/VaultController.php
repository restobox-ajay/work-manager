<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Vault\VaultRequestRefused;
use App\Service\Vault\VaultService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Password Manager (ADR-092), admins only, each with their own vault. The page is a single screen that encrypts and
 * decrypts in the browser (public/js/vault.js); these JSON endpoints only move ciphertext. Every response is
 * no-store, and the page runs under a strict Content-Security-Policy so injected script cannot read the open vault
 * (inline styles stay allowed: the shell's markup uses style attributes, and CSS cannot read JavaScript memory).
 * Cross-Origin-Opener-Policy cuts the page off from whatever opened it, so script on another page of the app cannot
 * window.open() the vault and read it. Requests that change the vault carry the master password's auth key in the
 * X-Vault-Auth header (ADR-094).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/vault')]
final class VaultController extends AbstractWorkController
{
    private const CSRF_ID = 'vault_api';
    private const AUTH_HEADER = 'X-Vault-Auth';
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
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        return $this->noStore($response);
    }

    /** Plain-language guide: why the vault has a master password and how to use it. Static, no secrets. */
    #[Route('/help', name: 'app_vault_help', methods: ['GET'])]
    public function help(): Response
    {
        return $this->render('vault/help.html.twig', ['minIterations' => VaultService::MIN_ITERATIONS]);
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

    /** A vault from before ADR-094 registers its auth key right after its first unlock. */
    #[Route('/api/auth', name: 'app_vault_auth', methods: ['POST'])]
    public function registerAuth(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $data) {
            $this->vault->registerAuth($this->viewer(), $data);

            return ['ok' => true];
        });
    }

    #[Route('/api/master-password', name: 'app_vault_master_password', methods: ['POST'])]
    public function masterPassword(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $data) use ($request) {
            $this->vault->rekey($this->viewer(), $this->auth($request), $data);

            return ['ok' => true];
        });
    }

    #[Route('/api/entries', name: 'app_vault_entry_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->handle($request, fn (array $data) => $this->vault->create($this->viewer(), $this->auth($request), $data), 201);
    }

    #[Route('/api/entries/{id}', name: 'app_vault_entry_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        return $this->handle($request, fn (array $data) => $this->vault->update($this->viewer(), $this->auth($request), $id, $data));
    }

    #[Route('/api/entries/{id}', name: 'app_vault_entry_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        return $this->handle($request, function () use ($id, $request) {
            $this->vault->delete($this->viewer(), $this->auth($request), $id);

            return ['ok' => true];
        });
    }

    /** Deleting the whole vault needs the account password, so a hijacked session cannot wipe it in one request. */
    #[Route('/api/reset', name: 'app_vault_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        return $this->handle($request, fn (array $data) => ['ok' => true, 'deleted' => $this->vault->reset($this->viewer(), $data['password'] ?? null)]);
    }

    private function auth(Request $request): ?string
    {
        return $request->headers->get(self::AUTH_HEADER);
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
