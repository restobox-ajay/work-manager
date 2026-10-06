<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Security\Voter\WorkVoter;
use App\Security\Work\WorkAccess;
use App\Service\Project\ProjectService;
use App\Service\Task\TaskInput;
use App\Service\Task\TaskLookups;
use App\Service\Task\TaskReportService;
use App\Service\Task\TaskService;
use App\Service\Validation\InputValue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The rest of work-platform's Tasks menu (ADR-072): Quick Add, Task By Date and Task Priority. (Task Created By
 * Manager and the Authorization Queue are gone with the queue itself, ADR-084.) Queries and rules: TaskReportService /
 * WorkAccess; writes: TaskService.
 */
#[Route('/task')]
final class TaskReportController extends AbstractWorkController
{
    /** Rows on an empty Quick Add form. */
    private const QUICK_ADD_ROWS = 5;
    /** The by-date report defaults to the last 7 days, as work-platform. */
    private const BY_DATE_DEFAULT_DAYS = 6;

    public function __construct(
        private readonly TaskReportService $reports,
        private readonly TaskLookups $lookups,
        private readonly WorkAccess $access,
    ) {
    }

    /** Several tasks at once: one row each, blank rows ignored. */
    #[Route('/quick-add', name: 'app_task_quick_add', methods: ['GET', 'POST'])]
    #[IsGranted(WorkVoter::WORK_MANAGE)]
    public function quickAdd(Request $request, TaskService $tasks, ProjectService $projects): Response
    {
        $viewer = $this->viewer();
        $rows = array_fill(0, self::QUICK_ADD_ROWS, []);
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'task_quick_add');
            $rows = array_values(array_filter(
                (array) ($request->request->all()['rows'] ?? []),
                static fn ($row) => is_array($row) && InputValue::text($row['name'] ?? null) !== null,
            ));
            $created = 0;
            $failed = [];
            foreach ($rows as $i => $row) {
                $result = $tasks->create((new TaskInput())->overlay(array_intersect_key($row, array_flip([
                    'name', 'projectId', 'assigneeId', 'taskTypeId', 'dueDate', 'totalAmount', 'currencyId', 'timeBudget',
                ]))), $viewer);
                if ($result->isSaved()) {
                    ++$created;
                } else {
                    $failed[] = $row;
                    $errors[] = sprintf('Row "%s": %s', mb_substr((string) $row['name'], 0, 60), implode(' ', $result->errors));
                }
            }
            if ($created > 0) {
                $this->addFlash('success', sprintf('%d task%s created.', $created, $created === 1 ? '' : 's'));
            }
            if ($failed === []) {
                return $this->redirectToRoute('app_task_index');
            }
            $rows = $failed;
        }

        $projectChoices = array_values(array_filter(
            $projects->selectable($viewer),
            fn ($project) => $this->access->canAddTaskTo($viewer, $project),
        ));

        return $this->render('task/quick_add.html.twig', [
            'rows'       => $rows,
            'projects'   => $projectChoices,
            'types'      => $this->lookups->activeTypes(),
            'people'     => $this->lookups->activePeople(),
            'currencies' => $this->lookups->currencies(),
            'showFees'   => $this->access->canSetFeesForAnyProject($viewer),
            'errors'     => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    /** Task By Date: a billing report grouped by billable (or creation) date, with each day's totals. */
    #[Route('/by-date', name: 'app_task_by_date', methods: ['GET'])]
    public function byDate(Request $request): Response
    {
        $field = $request->query->get('reportingBy') === 'creationDate' ? 'creationDate' : 'billableDate';
        $from = $this->date($request->query->get('startDate')) ?? new \DateTimeImmutable(sprintf('-%d days', self::BY_DATE_DEFAULT_DAYS));
        $to = $this->date($request->query->get('endDate')) ?? new \DateTimeImmutable('today');
        if ($from > $to) {
            $this->addFlash('error', 'Start date must be before the end date.');
            [$from, $to] = [new \DateTimeImmutable(sprintf('-%d days', self::BY_DATE_DEFAULT_DAYS)), new \DateTimeImmutable('today')];
        }

        $viewer = $this->viewer();
        $groups = $this->reports->byDate($viewer, $field, $from->setTime(0, 0), $to->setTime(0, 0));
        $fee = [];
        foreach ($groups as $group) {
            foreach ($group['tasks'] as $task) {
                $fee[(int) $task->getId()] = $this->access->canAccessFee($viewer, $task);
            }
        }

        return $this->render('task/by_date.html.twig', $this->lookupMaps() + [
            'groups'    => $groups,
            'field'     => $field,
            'startDate' => $from->format('Y-m-d'),
            'endDate'   => $to->format('Y-m-d'),
            'fee'       => $fee,
        ]);
    }

    /** Task Priority: one person's pending and review work, in their priority order. */
    #[Route('/priority', name: 'app_task_priority', methods: ['GET'])]
    public function priority(Request $request): Response
    {
        $viewer = $this->viewer();
        $targetId = InputValue::int($request->query->get('userId')) ?? (int) $viewer->getId();
        if (!$this->reports->canViewPriorities($viewer, $targetId)) {
            throw $this->createAccessDeniedException();
        }

        $tabs = $this->reports->priorityTabs($targetId);
        $fee = [];
        foreach ($tabs as $tab) {
            foreach ($tab['tasks'] as $task) {
                $fee[(int) $task->getId()] = $this->access->canAccessFee($viewer, $task);
            }
        }

        return $this->render('task/priority.html.twig', $this->lookupMaps() + [
            'tabs'       => $tabs,
            'targetId'   => $targetId,
            'peopleList' => $this->reports->priorityPeople($viewer),
            'canReorder' => $this->reports->canReorder($viewer),
            'fee'        => $fee,
        ]);
    }

    #[Route('/priority/{userId}/sort', name: 'app_task_priority_sort', requirements: ['userId' => '\d+'], methods: ['POST'])]
    public function sort(int $userId, Request $request): Response
    {
        if (!$this->reports->canReorder($this->viewer())) {
            throw $this->createAccessDeniedException();
        }
        $this->assertCsrf($request, 'task_priority_'.$userId);

        // The Top/Bottom buttons put the move on their formaction query string; the task ids come in the body.
        $move = (string) ($request->query->get('move') ?? $request->request->get('move', ''));
        $this->reports->reorder(
            $userId,
            InputValue::ints($request->request->all()['taskIds'] ?? []),
            InputValue::int($request->request->get('taskId')),
            in_array($move, ['top', 'bottom'], true) ? $move : null,
        );
        $this->addFlash('success', 'Task priority updated.');

        return $this->redirectToRoute('app_task_priority', ['userId' => $userId]);
    }

    /** @return array<string, array<int, string>> */
    private function lookupMaps(): array
    {
        return [
            'statuses'   => $this->lookups->statuses(),
            'types'      => $this->lookups->types(),
            'currencies' => $this->lookups->currencies(),
            'people'     => $this->lookups->people(),
        ];
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        $value = InputValue::text($value);
        $date = $value !== null ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
