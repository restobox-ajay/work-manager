<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\ProjectStaff;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Security\Voter\WorkVoter;
use App\Security\Work\WorkAccess;
use App\Service\Client\ClientService;
use App\Service\Pagination\Paginated;
use App\Service\Project\ProjectService;
use App\Service\Project\ProjectTaskGrid;
use App\Service\Project\ProjectStaffService;
use App\Service\Task\TaskLookups;
use App\Service\Validation\InputValue;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Projects and their staff (ADR-070). Rules: WorkAccess via WorkVoter; writes: ProjectService/ProjectStaffService. */
#[Route('/project')]
final class ProjectController extends AbstractWorkController
{
    /** Empty task rows on the New Project form. */
    private const NEW_PROJECT_TASK_ROWS = 3;

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectStaffService $staff,
        private readonly ClientService $clients,
        private readonly ProjectTaskGrid $taskGrid,
        private readonly TaskLookups $lookups,
        private readonly WorkAccess $access,
    ) {
    }

    #[Route('', name: 'app_project_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = $request->query->getString('status');
        $filters = [
            'term'     => InputValue::text($request->query->get('q')),
            'clientId' => InputValue::int($request->query->get('clientId')),
            'status'   => match ($status) {
                'active'   => Project::STATUS_ACTIVE,
                'archived' => Project::STATUS_ARCHIVE,
                default    => null,
            },
        ];
        $sort = $request->query->getString('sort');
        $page = $this->projects->search($this->viewer(), $filters, Paginated::pageFrom($request->query->get('page')), $sort);

        return $this->render('project/index.html.twig', [
            'page'        => $page,
            'contractors' => $this->projects->contractorsOf($page->items),
            'clients'     => $this->clients->selectable($this->viewer()),
            'filters'     => $filters,
            'status'      => $status,
            'sort'        => $sort,
        ]);
    }

    #[Route('/new', name: 'app_project_create', methods: ['GET', 'POST'])]
    #[IsGranted(WorkVoter::PROJECT_CREATE)]
    public function create(Request $request): Response
    {
        $values = ['clientId' => InputValue::int($request->query->get('clientId')), 'status' => Project::STATUS_ACTIVE];
        $errors = [];
        // Tasks can be added with the project (ADR-083): a few empty rows to start with.
        $taskRows = array_fill(0, self::NEW_PROJECT_TASK_ROWS, $this->taskGrid->blankRow($this->viewer()));

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'project_form');
            $values = $request->request->all();
            // New tasks only: a posted task id is ignored rather than trusted.
            $taskRows = array_map(static fn (array $row) => ['existingTaskId' => null] + $row, $this->taskGrid->parse($values['taskRows'] ?? []));
            // The rows are checked first, so a refused row never leaves a project created without its tasks.
            $errors = $this->taskGrid->checkNewRows($taskRows, $this->access->canSetFeesForAnyProject($this->viewer()));
            $result = $errors === [] ? $this->projects->create($values, $this->viewer()) : null;
            if ($result?->isSaved()) {
                $project = $result->record;
                $grid = $this->taskGrid->save($project, $taskRows, $this->viewer());
                if ($grid['errors'] !== []) {
                    // Rare (e.g. a contractor removed meanwhile): the project exists, so continue on its edit page.
                    $this->addFlash('error', 'Project created, but its tasks were not saved: '.implode(' ', $grid['errors']));

                    return $this->redirectToRoute('app_project_edit', ['id' => $project->getId()]);
                }
                $this->addFlash('success', 'Project created.'.($grid['created'] > 0 ? sprintf(' %d task(s) added.', $grid['created']) : ''));

                return $this->redirectToRoute('app_project_view', ['id' => $project->getId()]);
            }
            $errors = [...($result?->errors ?? []), ...$errors];
        }

        return $this->renderForm(null, $values, $errors, $taskRows);
    }

    #[Route('/{id}', name: 'app_project_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(WorkVoter::PROJECT_VIEW, 'project')]
    public function view(#[MapEntity(id: 'id')] Project $project, TaskRepository $tasks): Response
    {
        return $this->renderProjectPage($project, $tasks);
    }

    #[Route('/{id}/edit', name: 'app_project_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(WorkVoter::PROJECT_EDIT, 'project')]
    public function edit(#[MapEntity(id: 'id')] Project $project, Request $request): Response
    {
        $values = $this->projects->valuesFrom($project);
        $errors = [];

        $taskRows = $this->taskGrid->existingRows($project);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'project_form');
            $values = $request->request->all();
            $taskRows = $this->taskGrid->parse($values['taskRows'] ?? []);
            $result = $this->projects->update($project, $values, $this->viewer());
            if ($result->isSaved()) {
                // The project is saved first; its task rows are then saved all together or not at all.
                $grid = $this->taskGrid->save($project, $taskRows, $this->viewer());
                if ($grid['errors'] === []) {
                    $this->addFlash('success', 'Project updated.'.($grid['created'] + $grid['updated'] > 0
                        ? sprintf(' Tasks: %d added, %d updated.', $grid['created'], $grid['updated']) : ''));

                    return $this->redirectToRoute('app_project_view', ['id' => $project->getId()]);
                }
                $errors = ['The project was saved, but its tasks were not:', ...$grid['errors']];
            } else {
                $errors = $result->errors;
            }
        }

        return $this->renderForm($project, $values, $errors, $taskRows);
    }

    #[Route('/{id}/remove', name: 'app_project_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(WorkVoter::PROJECT_EDIT, 'project')]
    public function remove(#[MapEntity(id: 'id')] Project $project, Request $request): Response
    {
        $this->assertCsrf($request, 'project_remove_'.$project->getId());
        $this->projects->remove($project, $this->viewer());
        $this->addFlash('success', sprintf('Project "%s" archived and removed from the list.', $project->getName()));

        return $this->redirectToRoute('app_project_index');
    }

    #[Route('/{id}/staff', name: 'app_project_staff_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(WorkVoter::PROJECT_EDIT, 'project')]
    public function addStaff(#[MapEntity(id: 'id')] Project $project, Request $request, UserRepository $users): Response
    {
        $this->assertCsrf($request, 'project_staff_'.$project->getId());
        $userId = InputValue::int($request->request->get('userId'));
        $refusal = $this->staff->add(
            $project,
            $userId !== null ? $users->find($userId) : null,
            $request->request->getString('permission'),
            $request->request->getBoolean('canAccessTaskFee'),
            $this->viewer(),
        );
        $this->addFlash($refusal === null ? 'success' : 'error', $refusal ?? 'Staff member added.');

        return $this->redirectToRoute('app_project_view', ['id' => $project->getId(), '_fragment' => 'staff']);
    }

    #[Route('/staff/{staffId}', name: 'app_project_staff_update', requirements: ['staffId' => '\d+'], methods: ['POST'])]
    public function updateStaff(#[MapEntity(id: 'staffId')] ProjectStaff $row, Request $request): Response
    {
        $this->denyAccessUnlessGranted(WorkVoter::PROJECT_EDIT, $row->getProject());
        $this->assertCsrf($request, 'project_staff_row_'.$row->getId());

        $refusal = $request->request->has('remove')
            ? $this->staff->remove($row, $this->viewer())
            : $this->staff->update($row, $request->request->getString('permission'), $request->request->getBoolean('canAccessTaskFee'), $this->viewer());
        $this->addFlash($refusal === null ? 'success' : 'error', $refusal ?? 'Staff updated.');

        return $this->redirectToRoute('app_project_view', ['id' => $row->getProject()->getId(), '_fragment' => 'staff']);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $errors
     */
    /** The project page: details, every one of its tasks (tasks are added on the edit page, ADR-083) and its staff. */
    private function renderProjectPage(Project $project, TaskRepository $tasks): Response
    {
        $canEdit = $this->isGranted(WorkVoter::PROJECT_EDIT, $project);
        if ($canEdit) {
            // A Client Manager granted after the project was made becomes staff the next time anyone looks.
            $this->staff->ensureAutomaticStaff($project, $this->viewer());
        }

        return $this->render('project/view.html.twig', [
            'project'         => $project,
            'archivedReasons' => $this->projects->archivedReasons($project),
            'staff'           => $this->staff->staffOf($project),
            'selectableUsers' => $canEdit ? $this->staff->selectableUsers($project) : [],
            'permissions'     => ProjectStaffService::ASSIGNABLE_PERMISSIONS,
            'tasks'           => $tasks->findForProject((int) $project->getId()),
            'statuses'        => $this->lookups->statuses(),
            'canEdit'         => $canEdit,
        ]);
    }

    /** @return array<string, mixed> what templates/project/_task_grid.html.twig needs besides its rows */
    private function gridContext(?Project $project): array
    {
        return [
            // A new project has no staff yet to grant fee access: the fee columns follow the create-task form's rule.
            'gridFees'  => $project !== null ? $this->taskGrid->canSetFees($this->viewer(), $project) : $this->access->canSetFeesForAnyProject($this->viewer()),
            'gridBlank' => $this->taskGrid->blankRow($this->viewer()),
            'grid'      => [
                'types'       => $this->lookups->activeTypes(),
                'statuses'    => $this->lookups->assignableStatuses(),
                'currencies'  => $this->lookups->currencies(),
                'allTypes'    => $this->lookups->types(),
                'allStatuses' => $this->lookups->statuses(),
            ],
        ];
    }

    /** @param list<array<string, mixed>> $taskRows */
    private function renderForm(?Project $project, array $values, array $errors, array $taskRows = []): Response
    {
        // Only the clients this user may file projects under; an existing project's own client stays listed.
        $clients = array_values(array_filter(
            $this->clients->selectable($this->viewer()),
            fn ($client) => $client->getId() === $project?->getClient()?->getId() || $this->isGranted(WorkVoter::PROJECT_CREATE, $client),
        ));

        return $this->render('project/form.html.twig', [
            'project' => $project,
            'values'  => $values,
            'clients' => $clients,
            'errors'  => $errors,
            'taskRows' => $taskRows,
            'gridLocked' => $project !== null ? $this->taskGrid->lockedTaskIds($taskRows, $this->viewer()) : [],
            ...$this->gridContext($project),
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
