<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Security\Voter\WorkVoter;
use App\Security\Work\WorkAccess;
use App\Service\Task\TaskInput;
use App\Service\Task\TaskLookups;
use App\Service\Task\TaskService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Split a task (work-platform's /task/{id}/split, ADR-139): row 1 is the task itself, every row below it a new task
 * in the same project. Client and project are fixed; the rows carry the task form's own fields minus the ones hidden
 * everywhere (contractor, reviewer, billable time/date — ADR-100), which the new tasks take from the original.
 */
#[Route('/task/{id}/split', requirements: ['id' => '\d+'])]
final class TaskSplitController extends AbstractWorkController
{
    /** The fields a split row posts, as TaskInput names them. Anything else on the task is left as it is. */
    private const ROW_FIELDS = ['name', 'taskTypeId', 'taskStatusId', 'dueDate', 'statusDetail', 'currencyId', 'totalAmount'];

    /** New rows offered when the page opens (work-platform: the original plus one), and the most "Add row" gives. */
    private const DEFAULT_NEW_ROWS = 1;
    private const MAX_NEW_ROWS = 20;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskLookups $lookups,
        private readonly WorkAccess $access,
    ) {
    }

    #[Route('', name: 'app_task_split', methods: ['GET', 'POST'])]
    #[IsGranted(WorkVoter::TASK_SPLIT, 'task')]
    public function split(#[MapEntity(id: 'id')] Task $task, Request $request): Response
    {
        if ($task->isDeleted()) {
            throw $this->createNotFoundException();
        }

        if (!$request->isMethod('POST')) {
            // ?rows= is "Add row" without JavaScript.
            $newRows = max(1, min(self::MAX_NEW_ROWS, $request->query->getInt('rows', self::DEFAULT_NEW_ROWS)));

            return $this->renderForm($task, [$this->rowFrom($task, false), ...array_fill(0, $newRows, $this->rowFrom($task, true))], []);
        }

        $this->assertCsrf($request, 'task_split_'.$task->getId());
        $posted = array_values(array_slice(array_filter((array) $request->request->all('rows'), 'is_array'), 0, self::MAX_NEW_ROWS + 1));
        $posted = array_map(static fn (array $row) => array_intersect_key($row, array_flip(self::ROW_FIELDS)), $posted);

        $newRows = [];
        foreach (array_slice($posted, 1) as $i => $row) {
            $newRows[$i + 2] = (new TaskInput())->overlay($row);
        }
        [$created, $errors] = $this->tasks->split($task, TaskInput::fromTask($task)->overlay($posted[0] ?? []), $newRows, $this->viewer());

        if ($errors !== []) {
            return $this->renderForm($task, $posted, $errors);
        }

        $this->addFlash('success', sprintf('Task split into %d new task%s.', count($created), count($created) === 1 ? '' : 's'));

        return $this->redirectToRoute('app_task_view', ['id' => $created[0]->getId()]);
    }

    /**
     * A row's form values. A new row starts from the original's name, type, status and currency; due date, status
     * detail and payout start blank — the parts of a split-off piece that need their own decision (work-platform).
     *
     * @return array<string, string>
     */
    private function rowFrom(Task $task, bool $newRow): array
    {
        return [
            'name'         => $task->getName(),
            'taskTypeId'   => (string) $task->getTaskTypeId(),
            'taskStatusId' => (string) $task->getTaskStatusId(),
            'dueDate'      => !$newRow && $task->getDueDate() !== null ? date('Y-m-d', $task->getDueDate()) : '',
            'statusDetail' => $newRow ? '' : (string) $task->getStatusDetail(),
            'currencyId'   => (string) $task->getCurrencyId(),
            'totalAmount'  => $newRow ? '0.00' : ($task->getTotalAmount() ?? '0.00'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows row 0 is the task itself
     * @param list<string>               $errors
     */
    private function renderForm(Task $task, array $rows, array $errors): Response
    {
        return $this->render('task/split.html.twig', [
            'task'       => $task,
            'rows'       => $rows,
            'blankRow'   => $this->rowFrom($task, true),
            'showFees'   => $this->access->canSetFees($this->viewer(), $task->getProject()),
            'statuses'   => $this->lookups->assignableStatuses($task->getTaskStatusId()),
            'types'      => $this->lookups->activeTypes($task->getTaskTypeId()),
            'currencies' => $this->lookups->currencies(),
            'maxNewRows' => self::MAX_NEW_ROWS,
            'errors'     => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
