<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Security\Voter\WorkVoter;
use App\Security\Work\WorkAccess;
use App\Service\Client\ClientService;
use App\Service\Pagination\Paginated;
use App\Service\Project\ProjectService;
use App\Service\Task\TaskInput;
use App\Service\Task\TaskListService;
use App\Service\Task\TaskLookups;
use App\Service\Task\TaskReportService;
use App\Service\Task\TaskService;
use App\Service\Validation\InputValue;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Tasks (ADR-070). Rules: WorkAccess via WorkVoter; writes: TaskService; the list: TaskListService. */
#[Route('/task')]
final class TaskController extends AbstractWorkController
{
    /**
     * The form's fields, as TaskInput names them. Contractor, reviewer, billable time and billable date are off the
     * form (ADR-100): not posted, so an edit keeps a task's existing values.
     */
    private const FORM_FIELDS = [
        'name', 'projectId', 'taskTypeId', 'taskStatusId', 'currencyId',
        'totalAmount', 'statusDetail', 'description', 'tutorial', 'docUrl', 'dueDate', 'creationDate',
    ];

    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskLookups $lookups,
        private readonly ProjectService $projects,
        private readonly ClientService $clients,
        private readonly WorkAccess $access,
    ) {
    }

    /** Tasks By Client/Project (work-platform's /task/client): the list, with clients and projects to pick from. */
    #[Route('', name: 'app_task_index', methods: ['GET'])]
    public function index(Request $request, TaskListService $list): Response
    {
        return $this->renderList($request, $list, 'clients');
    }

    /** Tasks By Contractor (work-platform's /task/contractor): the same list, picked by contractor instead. */
    #[Route('/contractor', name: 'app_task_contractor', methods: ['GET'])]
    public function byContractor(Request $request, TaskListService $list, TaskReportService $reports): Response
    {
        return $this->renderList($request, $list, 'contractors', $reports->contractors($this->viewer()));
    }

    /** @param User[] $contractors */
    private function renderList(Request $request, TaskListService $list, string $sideList, array $contractors = []): Response
    {
        $viewer = $this->viewer();
        $filters = $list->filtersFrom($request->query->all());
        $sort = $request->query->getString('sort');
        $page = $list->search($viewer, $filters, Paginated::pageFrom($request->query->get('page')), $sort);
        $clientId = $filters['clientId'] > 0 ? $filters['clientId'] : null;

        return $this->render('task/index.html.twig', [
            'page'        => $page,
            'rows'        => $list->rowDetails($viewer, $page->items),
            'filters'     => $filters,
            'sort'        => $sort,
            'sideList'    => $sideList,
            'listRoute'   => $sideList === 'contractors' ? 'app_task_contractor' : 'app_task_index',
            'contractors' => $contractors,
            'clients'     => $this->clients->selectable($viewer),
            'projects'    => $this->projects->selectable($viewer, $clientId),
            'statuses'    => $this->lookups->statuses(),
            'types'       => $this->lookups->types(),
            'currencies'  => $this->lookups->currencies(),
            'people'      => $this->lookups->people(),
            'noneId'      => TaskRepository::NO_PROJECT_ID,
        ]);
    }

    #[Route('/new', name: 'app_task_create', methods: ['GET', 'POST'])]
    #[IsGranted(WorkVoter::TASK_CREATE)]
    public function create(Request $request): Response
    {
        $input = (new TaskInput())->overlay(['projectId' => $request->query->get('projectId')]);
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'task_form');
            // Every new task is a regular task: no Add Task / Add Budget choice and no authorization queue (ADR-083/084).
            $input->overlay($this->posted($request));
            $result = $this->tasks->create($input, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Task created.');

                return $this->redirectToRoute('app_task_view', ['id' => $result->record->getId()]);
            }
            $errors = $result->errors;
        }

        return $this->renderForm(null, $input, $this->access->canSetFeesForAnyProject($this->viewer()), $errors);
    }

    #[Route('/{id}', name: 'app_task_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(WorkVoter::TASK_VIEW, 'task')]
    public function view(#[MapEntity(id: 'id')] Task $task): Response
    {
        if ($task->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $this->render('task/view.html.twig', [
            'task'            => $task,
            'archivedReasons' => $task->getProject() !== null ? $this->projects->archivedReasons($task->getProject()) : [],
            'statuses'        => $this->lookups->statuses(),
            'types'           => $this->lookups->types(),
            'currencies'      => $this->lookups->currencies(),
            'people'          => $this->lookups->people(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_task_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(WorkVoter::TASK_EDIT, 'task')]
    public function edit(#[MapEntity(id: 'id')] Task $task, Request $request): Response
    {
        if ($task->isDeleted()) {
            throw $this->createNotFoundException();
        }
        $input = TaskInput::fromTask($task);
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'task_form');
            $input->overlay($this->posted($request));
            $result = $this->tasks->update($task, $input, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Task updated.');

                return $this->redirectToRoute('app_task_view', ['id' => $task->getId()]);
            }
            $errors = $result->errors;
        }

        return $this->renderForm($task, $input, $this->access->canSetFees($this->viewer(), $task->getProject()), $errors);
    }

    #[Route('/{id}/status-detail', name: 'app_task_status_detail', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(WorkVoter::TASK_STATUS_DETAIL, 'task')]
    public function statusDetail(#[MapEntity(id: 'id')] Task $task, Request $request): Response
    {
        $this->assertCsrf($request, 'task_status_detail_'.$task->getId());
        $this->tasks->updateStatusDetail($task, InputValue::text($request->request->get('statusDetail')), $this->viewer());
        $this->addFlash('success', 'Status detail saved.');

        return $this->redirectToRoute('app_task_view', ['id' => $task->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_task_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(WorkVoter::TASK_DELETE, 'task')]
    public function delete(#[MapEntity(id: 'id')] Task $task, Request $request): Response
    {
        $this->assertCsrf($request, 'task_delete_'.$task->getId());
        $this->tasks->delete($task, $this->viewer());
        $this->addFlash('success', sprintf('Task "%s" deleted.', mb_substr($task->getName(), 0, 80)));

        return $this->redirectToRoute('app_task_index');
    }

    /** @return array<string, mixed> */
    private function posted(Request $request): array
    {
        return array_intersect_key($request->request->all(), array_flip(self::FORM_FIELDS));
    }

    /** @param list<string> $errors */
    private function renderForm(?Task $task, TaskInput $input, bool $showFees, array $errors): Response
    {
        $viewer = $this->viewer();
        // Projects this user may file tasks under; an existing task's own project stays listed.
        $projects = array_values(array_filter(
            $this->projects->selectable($viewer),
            fn ($project) => $project->getId() === $task?->getProject()?->getId() || $this->access->canAddTaskTo($viewer, $project),
        ));

        return $this->render('task/form.html.twig', [
            'task'       => $task,
            'input'      => $input,
            'showFees'   => $showFees,
            'projects'   => $projects,
            'statuses'   => $this->lookups->assignableStatuses($task?->getTaskStatusId()),
            'types'      => $this->lookups->activeTypes($task?->getTaskTypeId()),
            'currencies' => $this->lookups->currencies(),
            'errors'     => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
