<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Entity\Task;
use App\Service\Validation\InputValue;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A task form's fields as typed values, with work-platform's TaskWriteData rules and messages (ADR-070).
 * Dates in the form are Y-m-d; due date is stored as a unix timestamp (Yii2 column), the other two as DATE.
 */
final class TaskInput
{
    #[Assert\NotBlank(message: 'Task name is required.')]
    #[Assert\Length(max: 5000, maxMessage: 'Task name cannot be longer than {{ limit }} characters.')]
    public string $name = '';

    public ?int $projectId = null;

    public ?int $assigneeId = null;

    public ?int $reviewerUserId = null;

    public ?int $taskTypeId = null;

    public ?int $taskStatusId = null;

    public ?int $currencyId = null;

    #[Assert\Type(type: 'numeric', message: 'Payout must be a number.')]
    public ?string $totalAmount = null;

    #[Assert\Regex(pattern: '/^-?\d+$/', message: 'Billable Time must be a whole number.')]
    public ?string $timeBudget = null;

    public ?string $statusDetail = null;

    public ?string $description = null;

    public ?string $tutorial = null;

    public ?int $dueDate = null;

    public ?\DateTimeInterface $billableDate = null;

    public ?\DateTimeInterface $creationDate = null;


    public static function fromTask(Task $task): self
    {
        $input = new self();
        $input->name = $task->getName();
        $input->projectId = $task->getProject()?->getId();
        $input->assigneeId = $task->getAssignee()?->getId();
        $input->reviewerUserId = $task->getReviewerUserId();
        $input->taskTypeId = $task->getTaskTypeId();
        $input->taskStatusId = $task->getTaskStatusId();
        $input->currencyId = $task->getCurrencyId();
        $input->totalAmount = $task->getTotalAmount();
        $input->timeBudget = $task->getTimeBudget() !== null ? (string) $task->getTimeBudget() : null;
        $input->statusDetail = $task->getStatusDetail();
        $input->description = $task->getDescription();
        $input->tutorial = $task->getTutorial();
        $input->dueDate = $task->getDueDate();
        $input->billableDate = $task->getBillableDate();
        $input->creationDate = $task->getCreationDate();

        return $input;
    }

    /** @param array<string, mixed> $values posted form fields; absent keys keep their current value */
    public function overlay(array $values): self
    {
        foreach ($values as $field => $value) {
            match ($field) {
                'name'           => $this->name = InputValue::text($value) ?? '',
                'projectId'      => $this->projectId = InputValue::int($value),
                'assigneeId'     => $this->assigneeId = InputValue::int($value),
                'reviewerUserId' => $this->reviewerUserId = InputValue::int($value),
                'taskTypeId'     => $this->taskTypeId = InputValue::int($value),
                'taskStatusId'   => $this->taskStatusId = InputValue::int($value),
                'currencyId'     => $this->currencyId = InputValue::int($value),
                // Thousands separators are formatting, not part of the number ("1,250.50").
                'totalAmount'    => $this->totalAmount = ($amount = InputValue::text($value)) !== null ? str_replace(',', '', $amount) : null,
                'timeBudget'     => $this->timeBudget = InputValue::text($value),
                'statusDetail'   => $this->statusDetail = InputValue::text($value),
                'description'    => $this->description = InputValue::text($value),
                'tutorial'       => $this->tutorial = InputValue::text($value),
                'dueDate'        => $this->dueDate = InputValue::timestamp($value),
                'billableDate'   => $this->billableDate = InputValue::date($value),
                'creationDate'   => $this->creationDate = InputValue::date($value),
                default          => null,
            };
        }

        return $this;
    }
}
