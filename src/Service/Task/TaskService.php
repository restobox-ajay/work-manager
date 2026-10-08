<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Entity\Project;
use App\Entity\Settings\TaskStatus;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\Settings\TaskTypeRepository;
use App\Repository\UserRepository;
use App\Security\Work\WorkAccess;
use App\Service\Validation\WriteResult;
use App\Service\Validation\WriteValidator;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single write path for a task (ADR-070), ported from work-platform's TaskWriteService and
 * TaskManagementService: validate, assign the fields, stamp, persist, audit.
 */
final class TaskService
{
    /** A blank currency falls back to this, matching the column's default. */
    private const DEFAULT_CURRENCY_ID = 1;

    /** Statuses the payment flow sets; a task form may not set them by hand. */
    public const PAYMENT_MANAGED_STATUS_IDS = [TaskStatus::PAID_ID];

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly UserRepository $users,
        private readonly TaskTypeRepository $taskTypes,
        private readonly WorkAccess $access,
        private readonly WriteValidator $validator,
        private readonly WorkAuditTrail $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return WriteResult<Task> */
    public function create(TaskInput $input, User $actor): WriteResult
    {
        [$project, $errors] = $this->validate($input, $actor, null);
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $task = new Task();
        $this->populate($task, $input, $project, $this->access->canSetFees($actor, $project));
        // No assignee chosen (the task form no longer asks, ADR-100): the task is its creator's (ADR-114).
        if ($task->getAssignee() === null) {
            $task->setAssignee($actor);
        }

        $now = time();
        $task->setCreatedAt($now)->setCreatedBy($actor->getId())->setUpdatedAt($now)->setUpdatedBy($actor->getId());
        $task->setCreationDate($task->getCreationDate() ?? new \DateTimeImmutable('today'));
        // Every task is regular work as soon as it is filed: there is no authorization queue (ADR-084).
        $task->setAuthorized(Task::AUTHORIZED_NO);

        $this->em->persist($task);
        $this->em->flush();

        $this->audit->record($actor, 'task.create', $this->describe($task));

        return WriteResult::saved($task);
    }

    /** @return WriteResult<Task> */
    public function update(Task $task, TaskInput $input, User $actor): WriteResult
    {
        [$project, $errors] = $this->validate($input, $actor, $task);
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $previousStatusId = $task->getTaskStatusId();
        // Fee fields are written only by someone who may set fees on the task's project — never its assignee
        // alone, who could otherwise set their own payout.
        $this->populate($task, $input, $project, $this->access->canSetFees($actor, $project));
        $task->setUpdatedAt(time())->setUpdatedBy($actor->getId());

        if ($task->getTaskStatusId() === TaskStatus::APPROVED_ID && $previousStatusId !== TaskStatus::APPROVED_ID) {
            $task->setApprovedDate(time())->setApprovedBy($actor->getId());
        }

        $this->em->flush();

        $this->audit->record($actor, 'task.update', $this->describe($task));

        return WriteResult::saved($task);
    }

    /** The assignee's progress note (work-platform's update-status-detail). */
    public function updateStatusDetail(Task $task, ?string $statusDetail, User $actor): void
    {
        $statusDetail = $statusDetail !== null ? trim($statusDetail) : null;
        $task->setStatusDetail($statusDetail === '' ? null : $statusDetail)
            ->setUpdatedAt(time())
            ->setUpdatedBy($actor->getId());
        $this->em->flush();

        $this->audit->record($actor, 'task.status_detail', $this->describe($task));
    }

    /** Soft delete, as work-platform: the row stays for payment history and is hidden everywhere. */
    public function delete(Task $task, User $actor): void
    {
        $task->setIsDeleted(1)->setUpdatedAt(time())->setUpdatedBy($actor->getId());
        $this->em->flush();

        $this->audit->record($actor, 'task.delete', $this->describe($task));
    }

    public function isAssignableStatus(?int $statusId): bool
    {
        return $statusId === null || !in_array($statusId, self::PAYMENT_MANAGED_STATUS_IDS, true);
    }

    /**
     * @return array{0: ?Project, 1: list<string>}
     */
    private function validate(TaskInput $input, User $actor, ?Task $task): array
    {
        $errors = $this->validator->checkObject($input);

        $project = $input->projectId !== null ? $this->projects->find($input->projectId) : null;
        if ($input->projectId !== null && $project === null) {
            $errors[] = 'The selected project no longer exists.';
        } elseif (($task === null || $project?->getId() !== $task->getProject()?->getId()) && !$this->access->canAddTaskTo($actor, $project)) {
            // Checked on create and when the project changes: an existing task stays editable by its
            // managers even if they could not file a new one there.
            $errors[] = $project === null ? 'Project is required.' : 'You cannot add tasks to this project.';
        }

        foreach (['assigneeId' => 'contractor', 'reviewerUserId' => 'reviewer'] as $field => $label) {
            if ($input->$field !== null && $this->users->find($input->$field) === null) {
                $errors[] = sprintf('The selected %s no longer exists.', $label);
            }
        }
        if ($input->taskTypeId !== null && $this->taskTypes->find($input->taskTypeId) === null) {
            $errors[] = 'The selected task type no longer exists.';
        }
        if (!$this->isAssignableStatus($input->taskStatusId) && $input->taskStatusId !== $task?->getTaskStatusId()) {
            $errors[] = 'Paid is set by the payment flow, not by editing the task.';
        }

        return [$project, $errors];
    }

    /** The only assignment of task fields from input. */
    private function populate(Task $task, TaskInput $input, ?Project $project, bool $canSetFees): void
    {
        $task->setName($input->name)
            ->setProject($project)
            ->setClientId($project?->getClient()?->getId())
            ->setAssignee($input->assigneeId !== null ? $this->users->find($input->assigneeId) : null)
            ->setReviewerUserId($input->reviewerUserId)
            ->setTaskTypeId($input->taskTypeId)
            ->setStatusDetail($input->statusDetail)
            ->setDescription($input->description)
            ->setTutorial($input->tutorial)
            ->setDocUrl($input->docUrl)
            ->setDueDate($input->dueDate)
            ->setCreationDate($input->creationDate ?? $task->getCreationDate());

        if ($this->isAssignableStatus($input->taskStatusId)) {
            $task->setTaskStatusId($input->taskStatusId ?? TaskStatus::PENDING_ID);
        }

        if ($canSetFees) {
            $task->setCurrencyId($input->currencyId ?? self::DEFAULT_CURRENCY_ID)
                ->setTotalAmount($input->totalAmount ?? '0.00')
                ->setTimeBudget($input->timeBudget !== null ? (int) $input->timeBudget : null)
                ->setBillableDate($input->billableDate);
        }
    }

    private function describe(Task $task): string
    {
        return sprintf('#%d %s', (int) $task->getId(), mb_substr($task->getName(), 0, 120));
    }
}
