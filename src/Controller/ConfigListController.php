<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\Config\ConfigCrudService;
use App\Service\Config\ConfigField;
use App\Service\Config\ConfigListDefinition;
use App\Service\Config\ConfigListRegistry;
use App\Service\Task\TaskLookups;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Config (ADR-073/074): every reference list an admin maintains — task statuses, task types, currencies, tags,
 * payer entities, wallet entities, payment methods, email templates, countries — as plain CRUD pages:
 * /config/<list> (the table), /config/<list>/new and /config/<list>/{id}/edit (a form; POST saves),
 * /config/<list>/{id}/delete (POST, lists nothing references by id). Which fields each list has is
 * ConfigListRegistry's; this controller is the same for all of them. Admins only.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/config/{kind}', requirements: ['kind' => ConfigListRegistry::KINDS])]
final class ConfigListController extends AbstractWorkController
{
    public function __construct(
        private readonly ConfigListRegistry $registry,
        private readonly ConfigCrudService $crud,
    ) {
    }

    #[Route('', name: 'app_config_list', methods: ['GET'])]
    public function list(string $kind, TaskLookups $lookups): Response
    {
        $list = $this->registry->get($kind);
        $hasUserColumn = array_filter($list->listedFields(), static fn ($field) => $field->type === ConfigField::USER) !== [];

        return $this->render('config/list.html.twig', [
            'list'   => $list,
            'rows'   => $this->crud->rows($list),
            'people' => $hasUserColumn ? $lookups->people() : [],
        ]);
    }

    #[Route('/new', name: 'app_config_new', methods: ['GET', 'POST'])]
    public function new(string $kind, Request $request, UserRepository $users): Response
    {
        return $this->form($this->registry->get($kind), null, $request, $users);
    }

    #[Route('/{id}/edit', name: 'app_config_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(string $kind, int $id, Request $request, UserRepository $users): Response
    {
        $list = $this->registry->get($kind);

        return $this->form($list, $this->crud->find($list, $id) ?? throw $this->createNotFoundException(), $request, $users);
    }

    #[Route('/{id}/delete', name: 'app_config_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(string $kind, int $id, Request $request): Response
    {
        $list = $this->registry->get($kind);
        if (!$list->deletable) {
            throw $this->createAccessDeniedException(sprintf('%s are referenced by tasks: switch one off instead.', $list->plural));
        }
        $row = $this->crud->find($list, $id) ?? throw $this->createNotFoundException();
        $this->assertCsrf($request, 'config_delete_'.$kind.'_'.$id);

        $this->crud->delete($list, $row, $this->viewer());
        $this->addFlash('success', sprintf('%s deleted.', $list->singular));

        return $this->redirectToRoute('app_config_list', ['kind' => $kind]);
    }

    private function form(ConfigListDefinition $list, ?object $row, Request $request, UserRepository $users): Response
    {
        $values = $row !== null ? $this->crud->valuesFrom($list, $row) : $list->defaults;
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'config_'.$list->kind);
            $values = $request->request->all();
            $errors = $this->crud->save($list, $row, $values, $this->viewer());
            if ($errors === []) {
                $this->addFlash('success', sprintf('%s saved.', $list->singular));

                return $this->redirectToRoute('app_config_list', ['kind' => $list->kind]);
            }
        }

        return $this->render('config/form.html.twig', [
            'list'   => $list,
            'row'    => $row,
            'values' => $values,
            'errors' => $errors,
            'people' => $users->findActiveOrderedByName(),
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
