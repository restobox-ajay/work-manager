<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\ProjectStaff;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Security\Voter\WorkVoter;
use App\Service\Client\ClientService;
use App\Service\Pagination\Paginated;
use App\Service\Project\ProjectService;
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
    /** The project page lists this many of the project's newest tasks; the task list has the rest. */
    private const PROJECT_PAGE_TASKS = 50;

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectStaffService $staff,
        private readonly ClientService $clients,
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

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'project_form');
            $values = $request->request->all();
            $result = $this->projects->create($values, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Project created.');

                return $this->redirectToRoute('app_project_view', ['id' => $result->record->getId()]);
            }
            $errors = $result->errors;
        }

        return $this->renderForm(null, $values, $errors);
    }

    #[Route('/{id}', name: 'app_project_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(WorkVoter::PROJECT_VIEW, 'project')]
    public function view(#[MapEntity(id: 'id')] Project $project, TaskRepository $tasks, TaskLookups $lookups): Response
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
            'tasks'           => $tasks->findForProject((int) $project->getId(), self::PROJECT_PAGE_TASKS),
            'statuses'        => $lookups->statuses(),
            'canEdit'         => $canEdit,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_project_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(WorkVoter::PROJECT_EDIT, 'project')]
    public function edit(#[MapEntity(id: 'id')] Project $project, Request $request): Response
    {
        $values = $this->projects->valuesFrom($project);
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'project_form');
            $values = $request->request->all();
            $result = $this->projects->update($project, $values, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Project updated.');

                return $this->redirectToRoute('app_project_view', ['id' => $project->getId()]);
            }
            $errors = $result->errors;
        }

        return $this->renderForm($project, $values, $errors);
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
    private function renderForm(?Project $project, array $values, array $errors): Response
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
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
