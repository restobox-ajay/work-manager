<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Settings\WorkSettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings (ADR-070), laid out like the Maxeme Auto mockup's Settings page: one page, a tab per list (task
 * statuses, task types, currencies), a read-only table, and Add/Edit in a popup. The popup is rendered open by
 * the server (?add=1, ?edit={id}, or a refused save), so it works without JavaScript. Admins only.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/settings/{kind}', requirements: ['kind' => 'task-statuses|task-types|currencies'])]
final class WorkSettingsController extends AbstractWorkController
{
    public function __construct(private readonly WorkSettingsService $settings)
    {
    }

    #[Route('', name: 'app_settings_list', methods: ['GET'])]
    public function list(string $kind, Request $request): Response
    {
        $editId = $request->query->getInt('edit');
        $row = $editId > 0 ? ($this->settings->find($kind, $editId) ?? throw $this->createNotFoundException()) : null;
        $open = $row !== null || $request->query->getBoolean('add');

        return $this->renderPage($kind, $open, $row, $row !== null ? $this->settings->valuesFrom($row) : $this->settings->defaults($kind), []);
    }

    /** Saves one row; "new" adds one. A refused save re-opens the popup with what was typed. */
    #[Route('/{id}', name: 'app_settings_save', requirements: ['id' => '\d+|new'], methods: ['POST'])]
    public function save(string $kind, string $id, Request $request): Response
    {
        $this->assertCsrf($request, 'settings_'.$kind);

        $row = null;
        if ($id !== 'new') {
            $row = $this->settings->find($kind, (int) $id) ?? throw $this->createNotFoundException();
        }

        $values = $request->request->all();
        $errors = $this->settings->save($kind, $row, $values, $this->viewer());
        if ($errors !== []) {
            return $this->renderPage($kind, true, $row, $values, $errors, 422);
        }

        $this->addFlash('success', sprintf('%s saved.', WorkSettingsService::LABELS[$kind]['singular']));

        return $this->redirectToRoute('app_settings_list', ['kind' => $kind]);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $errors
     */
    private function renderPage(string $kind, bool $open, ?object $row, array $values, array $errors, int $status = 200): Response
    {
        return $this->render('settings/list.html.twig', [
            'kind'   => $kind,
            'labels' => WorkSettingsService::LABELS,
            'rows'   => $this->settings->rows($kind),
            'modal'  => ['open' => $open, 'row' => $row, 'values' => $values, 'errors' => $errors],
        ], new Response(status: $status));
    }
}
