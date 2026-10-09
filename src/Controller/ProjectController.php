<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Note;
use App\Entity\Project;
use App\Repository\TaskRepository;
use App\Security\Voter\NoteVoter;
use App\Security\Voter\WorkVoter;
use App\Security\Work\WorkAccess;
use App\Service\Client\ClientService;
use App\Service\Note\NoteService;
use App\Service\Pagination\Paginated;
use App\Service\Project\ProjectService;
use App\Service\Project\ProjectTaskSummary;
use App\Service\Project\ProjectTaskGrid;
use App\Service\Task\TaskListService;
use App\Service\Task\TaskLookups;
use App\Service\Validation\InputValue;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\Work\WorkDeleter;

/** Projects (ADR-070). Rules: WorkAccess via WorkVoter; writes: ProjectService. Project staff removed (ADR-124). */
#[Route('/project')]
final class ProjectController extends AbstractWorkController
{
    /** Empty task rows on the New Project form. */
    private const NEW_PROJECT_TASK_ROWS = 3;

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ClientService $clients,
        private readonly ProjectTaskGrid $taskGrid,
        private readonly TaskLookups $lookups,
        private readonly WorkAccess $access,
        private readonly NoteService $notes,
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
    public function view(#[MapEntity(id: 'id')] Project $project, TaskRepository $tasks, TaskListService $taskList, ProjectTaskSummary $summary): Response
    {
        return $this->renderProjectPage($project, $tasks, $taskList, $summary);
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

    /** Permanent delete (ADR-109), admins only: GET shows what goes with the project, POST (name typed) deletes it. */
    #[Route('/{id}/delete', name: 'app_project_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(#[MapEntity(id: 'id')] Project $project, Request $request, WorkDeleter $deleter): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'project_delete_'.$project->getId());
            if (mb_strtolower(trim($request->request->getString('confirm'))) === mb_strtolower(trim($project->getName()))) {
                $name = $project->getName();
                $done = $deleter->deleteProject($project, $this->viewer());
                $this->addFlash('success', sprintf('Project "%s" deleted with %d tasks.', $name, $done['tasks']));

                return $this->redirectToRoute('app_project_index');
            }
            $errors[] = 'Type the project\'s name exactly to confirm.';
        }
        $impact = $deleter->projectImpact($project);

        return $this->render('_work/confirm_delete.html.twig', [
            'kind' => 'project', 'name' => $project->getName(), 'errors' => $errors, 'kept' => null,
            'impact' => ['tasks' => $impact['tasks'], 'notes' => $impact['notes']],
            'action' => $this->generateUrl('app_project_delete', ['id' => $project->getId()]), 'cancel' => $this->generateUrl('app_project_view', ['id' => $project->getId()]),
            'token' => 'project_delete_'.$project->getId(),
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $errors
     */
    /** The project page: details and every one of its tasks (tasks are added on the edit page, ADR-083). */
    private function renderProjectPage(Project $project, TaskRepository $tasks, TaskListService $taskList, ProjectTaskSummary $summary): Response
    {
        $projectTasks = $tasks->findForProject((int) $project->getId());
        // Fee visibility is per task (ADR-107): the page shows a payout only where the viewer may see it.
        $rows = $taskList->rowDetails($this->viewer(), $projectTasks);
        $statuses = $this->lookups->statuses();
        $currencies = $this->lookups->currencies();

        $canEdit = $this->isGranted(WorkVoter::PROJECT_EDIT, $project);

        return $this->render('project/view.html.twig', [
            'project'         => $project,
            'archivedReasons' => $this->projects->archivedReasons($project),
            'tasks'           => $projectTasks,
            'rows'            => $rows,
            'summary'         => $summary->summarise($projectTasks, $rows, $statuses, $currencies),
            'statuses'        => $statuses,
            'currencies'      => $currencies,
            'types'           => $this->lookups->types(),
            'people'          => $this->lookups->people(),
            'canEdit'         => $canEdit,
            'notes'           => $this->notes->forSubject($project),
            // Seeing the project does not mean seeing each of its tasks, so each task note is checked on its own.
            'taskNotes'       => array_values(array_filter(
                $this->notes->onTasksOf($project),
                fn (Note $note) => $this->isGranted(NoteVoter::VIEW, $note),
            )),
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
