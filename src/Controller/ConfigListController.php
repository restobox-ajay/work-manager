<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Settings\WorkSettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Config (ADR-070/073): the task reference lists — statuses, types, currencies — laid out like the Maxeme Auto
 * mockup's Settings page: a tab per list, a read-only table, Add/Edit in a popup. Under /config, so "settings" stays
 * free for other things.
 *
 * Each list has its own routes: /config/<list> (the table), /config/<list>/new and /config/<list>/{id}/edit (the
 * table with the popup open; POST saves). The popup is rendered open by the server, so it works without JavaScript.
 * Admins only.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/config')]
final class ConfigListController extends AbstractWorkController
{
    public function __construct(private readonly WorkSettingsService $settings)
    {
    }

    #[Route('/task-statuses', name: 'app_config_task_statuses', defaults: ['kind' => 'task-statuses'], methods: ['GET'])]
    #[Route('/task-types', name: 'app_config_task_types', defaults: ['kind' => 'task-types'], methods: ['GET'])]
    #[Route('/currencies', name: 'app_config_currencies', defaults: ['kind' => 'currencies'], methods: ['GET'])]
    public function list(string $kind): Response
    {
        return $this->renderPage($kind, false, null, [], []);
    }

    #[Route('/task-statuses/new', name: 'app_config_task_statuses_new', defaults: ['kind' => 'task-statuses'], methods: ['GET', 'POST'])]
    #[Route('/task-types/new', name: 'app_config_task_types_new', defaults: ['kind' => 'task-types'], methods: ['GET', 'POST'])]
    #[Route('/currencies/new', name: 'app_config_currencies_new', defaults: ['kind' => 'currencies'], methods: ['GET', 'POST'])]
    public function new(string $kind, Request $request): Response
    {
        return $this->form($kind, null, $request);
    }

    #[Route('/task-statuses/{id}/edit', name: 'app_config_task_statuses_edit', requirements: ['id' => '\d+'], defaults: ['kind' => 'task-statuses'], methods: ['GET', 'POST'])]
    #[Route('/task-types/{id}/edit', name: 'app_config_task_types_edit', requirements: ['id' => '\d+'], defaults: ['kind' => 'task-types'], methods: ['GET', 'POST'])]
    #[Route('/currencies/{id}/edit', name: 'app_config_currencies_edit', requirements: ['id' => '\d+'], defaults: ['kind' => 'currencies'], methods: ['GET', 'POST'])]
    public function edit(string $kind, int $id, Request $request): Response
    {
        return $this->form($kind, $this->settings->find($kind, $id) ?? throw $this->createNotFoundException(), $request);
    }

    /** The list's route name: app_config_task_statuses, …, with an optional suffix (_new, _edit). */
    public static function routeName(string $kind, string $suffix = ''): string
    {
        return 'app_config_'.str_replace('-', '_', $kind).$suffix;
    }

    private function form(string $kind, ?object $row, Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->renderPage($kind, true, $row, $row !== null ? $this->settings->valuesFrom($row) : $this->settings->defaults($kind), []);
        }

        $this->assertCsrf($request, 'settings_'.$kind);
        $values = $request->request->all();
        $errors = $this->settings->save($kind, $row, $values, $this->viewer());
        if ($errors !== []) {
            return $this->renderPage($kind, true, $row, $values, $errors, 422);
        }

        $this->addFlash('success', sprintf('%s saved.', WorkSettingsService::LABELS[$kind]['singular']));

        return $this->redirectToRoute(self::routeName($kind));
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $errors
     */
    private function renderPage(string $kind, bool $open, ?object $row, array $values, array $errors, int $status = 200): Response
    {
        return $this->render('config/list.html.twig', [
            'kind'   => $kind,
            'labels' => WorkSettingsService::LABELS,
            'rows'   => $this->settings->rows($kind),
            'modal'  => ['open' => $open, 'row' => $row, 'values' => $values, 'errors' => $errors],
        ], new Response(status: $status));
    }
}
