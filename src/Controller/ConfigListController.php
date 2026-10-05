<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Settings\WorkSettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Config (ADR-070/073): the task reference lists — statuses, types, currencies — each a plain CRUD: /config/<list>
 * (the table), /config/<list>/new and /config/<list>/{id}/edit (a form page; POST saves). Under /config, so
 * "settings" stays free for other things. Rows are not deleted: tasks point at them by id. Admins only.
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
        return $this->render('config/list.html.twig', $this->common($kind) + ['rows' => $this->settings->rows($kind)]);
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
        $values = $row !== null ? $this->settings->valuesFrom($row) : $this->settings->defaults($kind);
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'settings_'.$kind);
            $values = $request->request->all();
            $errors = $this->settings->save($kind, $row, $values, $this->viewer());
            if ($errors === []) {
                $this->addFlash('success', sprintf('%s saved.', WorkSettingsService::LABELS[$kind]['singular']));

                return $this->redirectToRoute(self::routeName($kind));
            }
        }

        return $this->render('config/form.html.twig', $this->common($kind) + [
            'row'    => $row,
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    /** @return array{kind: string, label: array{plural: string, singular: string}, routeBase: string} */
    private function common(string $kind): array
    {
        return ['kind' => $kind, 'label' => WorkSettingsService::LABELS[$kind], 'routeBase' => self::routeName($kind)];
    }
}
