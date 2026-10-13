<?php

declare(strict_types=1);

namespace App\Service\WorkOrder;

use App\Entity\Attachment;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\WorkOrder\WorkOrder;
use App\Entity\WorkOrder\WorkOrderMilestone;
use App\Repository\ClientRepository;
use App\Repository\WorkOrder\WorkOrderMilestoneRepository;
use App\Repository\WorkOrder\WorkOrderRepository;
use App\Service\File\AttachmentStorage;
use App\Service\Invoice\InvoiceMoney;
use App\Service\Project\ProjectService;
use App\Service\Task\TaskLookups;
use App\Service\Text\RichText;
use App\Service\Validation\InputValue;
use App\Service\Validation\WriteResult;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Work orders (ADR-125): contracts and jobs won — saving and checking one, its WO-YYYY-NNN number, milestones,
 * the money totals of a list, removal (with its files) and turning one into a project.
 */
final class WorkOrderService
{
    public const NUMBER_PREFIX = 'WO';
    private const NOTES_MAX = 20000;
    private const PROJECT_NAME_MAX = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkOrderRepository $workOrders,
        private readonly WorkOrderMilestoneRepository $milestones,
        private readonly ClientRepository $clients,
        private readonly ProjectService $projects,
        private readonly AttachmentStorage $files,
        private readonly TaskLookups $lookups,
        private readonly WorkAuditTrail $audit,
    ) {
    }

    /** @return list<string> currencies offered: the ones with a symbol plus any added under Config › Currencies */
    public function currencies(): array
    {
        return array_values(array_unique([...InvoiceMoney::knownCurrencies(), ...array_map('strtoupper', array_values($this->lookups->currencies()))]));
    }

    /** @return array<string, mixed> a new work order's defaults */
    public function defaults(?int $clientId = null): array
    {
        return ['status' => WorkOrder::STATUS_ACTIVE, 'currency' => 'INR', 'clientId' => $clientId,
            'signedDate' => (new \DateTimeImmutable('today'))->format('Y-m-d')];
    }

    /** @return array<string, mixed> the form's fields for a work order */
    public function valuesFrom(WorkOrder $w): array
    {
        return [
            'title' => $w->getTitle(), 'status' => $w->getStatus(), 'clientId' => $w->getClient()?->getId(), 'clientName' => $w->getClientName(),
            'contactName' => $w->getContactName(), 'contactPhone' => $w->getContactPhone(), 'contactEmail' => $w->getContactEmail(),
            'reference' => $w->getReference(), 'value' => $w->getValue(), 'currency' => $w->getCurrency(), 'paymentTerms' => $w->getPaymentTerms(),
            'advanceReceived' => $w->getAdvanceReceived(), 'signedDate' => $w->getSignedDate()?->format('Y-m-d'),
            'startDate' => $w->getStartDate()?->format('Y-m-d'), 'endDate' => $w->getEndDate()?->format('Y-m-d'),
            'description' => $w->getDescription(), 'notes' => $w->getNotes(),
        ];
    }

    /** @param array<string, mixed> $posted @return WriteResult<WorkOrder> */
    public function save(?WorkOrder $workOrder, array $posted, User $actor): WriteResult
    {
        $errors = [];
        $title = InputValue::text($posted['title'] ?? null) ?? '';
        if ($title === '' || mb_strlen($title) > 200) {
            $errors[] = 'Enter a title (at most 200 characters).';
        }
        $status = InputValue::text($posted['status'] ?? null) ?? '';
        if (!isset(WorkOrder::STATUSES[$status])) {
            $errors[] = 'Choose a status.';
        }
        $client = null;
        if (($clientId = InputValue::int($posted['clientId'] ?? null)) !== null) {
            $client = $this->clients->find($clientId);
            if ($client === null || ($client->isDeleted() && $client->getId() !== $workOrder?->getClient()?->getId())) {
                $errors[] = 'Choose a client from the list, or none.';
                $client = null;
            }
        }
        $texts = [];
        foreach (['clientName' => [150, 'Client name'], 'contactName' => [120, 'Contact person'], 'contactPhone' => [40, 'Contact phone'],
            'contactEmail' => [180, 'Contact email'], 'reference' => [80, 'Their reference / PO number'], 'paymentTerms' => [255, 'Payment terms']] as $field => [$max, $label]) {
            $texts[$field] = InputValue::text($posted[$field] ?? null);
            if ($texts[$field] !== null && mb_strlen($texts[$field]) > $max) {
                $errors[] = sprintf('%s cannot be longer than %d characters.', $label, $max);
            }
        }
        if ($texts['contactEmail'] !== null && filter_var($texts['contactEmail'], \FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Contact email is not a valid email address.';
        }
        $money = [];
        foreach (['value' => 'Contract value', 'advanceReceived' => 'Advance received'] as $field => $label) {
            $text = InputValue::text($posted[$field] ?? null);
            $hundredths = $text === null ? null : InvoiceMoney::toHundredths(str_replace(',', '', $text));
            if ($text !== null && ($hundredths === null || $hundredths < 0 || $hundredths >= 1_000_000_000_000_00)) {
                $errors[] = sprintf('%s must be a number of 0 or more, with at most 2 decimals.', $label);
            }
            $money[$field] = $hundredths === null ? null : InvoiceMoney::toDecimal($hundredths);
        }
        $currency = strtoupper(InputValue::text($posted['currency'] ?? null) ?? '');
        if (!in_array($currency, $this->currencies(), true)) {
            $errors[] = 'Choose a currency.';
        }
        $dates = [];
        foreach (['signedDate', 'startDate', 'endDate'] as $field) {
            [$dates[$field], $ok] = InputValue::ymd($posted[$field] ?? null);
            if (!$ok) {
                $errors[] = 'Dates must be valid dates.';
                break;
            }
        }
        if (($dates['startDate'] ?? null) !== null && ($dates['endDate'] ?? null) !== null && $dates['endDate'] < $dates['startDate']) {
            $errors[] = 'The end date cannot be before the start date.';
        }
        $notes = InputValue::text($posted['notes'] ?? null);
        if ($notes !== null && mb_strlen($notes) > self::NOTES_MAX) {
            $errors[] = sprintf('Internal notes cannot be longer than %d characters.', self::NOTES_MAX);
        }
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $isNew = $workOrder === null;
        $workOrder ??= (new WorkOrder())->setCreatedAt(time())->setCreatedBy($actor->getId());
        $workOrder->setTitle($title)->setStatus($status)->setClient($client)
            ->setClientName($client === null ? $texts['clientName'] : null)
            ->setContactName($texts['contactName'])->setContactPhone($texts['contactPhone'])->setContactEmail($texts['contactEmail'])
            ->setReference($texts['reference'])->setPaymentTerms($texts['paymentTerms'])
            ->setValue($money['value'])->setAdvanceReceived($money['advanceReceived'])->setCurrency($currency)
            ->setSignedDate($dates['signedDate'])->setStartDate($dates['startDate'])->setEndDate($dates['endDate'])
            ->setDescription(RichText::clean(InputValue::text($posted['description'] ?? null)))
            ->setNotes($notes)
            ->setUpdatedAt(time());

        if ($isNew) {
            $this->persistWithNumber($workOrder);
        } else {
            $this->em->flush();
        }
        $this->audit->record($actor, $isNew ? 'work_order.create' : 'work_order.update', $this->describe($workOrder));

        return WriteResult::saved($workOrder);
    }

    public function delete(WorkOrder $workOrder, User $actor): void
    {
        $label = $this->describe($workOrder);
        $files = $this->files->deleteAllFor(Attachment::OWNER_WORK_ORDER, (int) $workOrder->getId());
        $this->em->remove($workOrder); // milestones go with it (ON DELETE CASCADE)
        $this->em->flush();
        $this->audit->record($actor, 'work_order.delete', sprintf('%s (and %d files)', $label, $files));
    }

    /** @return WorkOrderMilestone[] */
    public function milestonesOf(WorkOrder $workOrder): array
    {
        return $this->milestones->findForWorkOrder($workOrder);
    }

    /** @param array<string, mixed> $posted @return list<string> refusals */
    public function addMilestone(WorkOrder $workOrder, array $posted, User $actor): array
    {
        $title = InputValue::text($posted['title'] ?? null) ?? '';
        [$due, $dateOk] = InputValue::ymd($posted['dueDate'] ?? null);
        $amountText = InputValue::text($posted['amount'] ?? null);
        $amount = $amountText === null ? null : InvoiceMoney::toHundredths(str_replace(',', '', $amountText));
        $errors = [];
        if ($title === '' || mb_strlen($title) > 200) {
            $errors[] = 'Enter what the milestone is (at most 200 characters).';
        }
        if (!$dateOk) {
            $errors[] = 'The due date must be a valid date.';
        }
        if ($amountText !== null && ($amount === null || $amount < 0)) {
            $errors[] = 'The amount must be a number of 0 or more, with at most 2 decimals.';
        }
        if ($errors !== []) {
            return $errors;
        }
        $milestone = (new WorkOrderMilestone())->setWorkOrder($workOrder)->setTitle($title)->setDueDate($due)
            ->setAmount($amount === null ? null : InvoiceMoney::toDecimal($amount));
        $this->em->persist($milestone);
        $workOrder->setUpdatedAt(time());
        $this->em->flush();
        $this->audit->record($actor, 'work_order.milestone_add', sprintf('%s: %s', $this->describe($workOrder), $title));

        return [];
    }

    public function toggleMilestone(WorkOrderMilestone $milestone, User $actor): void
    {
        $milestone->setDone(!$milestone->isDone());
        $this->em->flush();
        $this->audit->record($actor, 'work_order.milestone_'.($milestone->isDone() ? 'done' : 'reopen'),
            sprintf('%s: %s', $this->describe($milestone->getWorkOrder()), $milestone->getTitle()));
    }

    public function deleteMilestone(WorkOrderMilestone $milestone, User $actor): void
    {
        $this->audit->record($actor, 'work_order.milestone_delete', sprintf('%s: %s', $this->describe($milestone->getWorkOrder()), $milestone->getTitle()));
        $this->em->remove($milestone);
        $this->em->flush();
    }

    /**
     * Creates a project (for the work order's client, if it has one) from its title and scope, and links the two.
     *
     * @return WriteResult<Project>
     */
    public function convertToProject(WorkOrder $workOrder, User $actor): WriteResult
    {
        if ($workOrder->getProject() !== null) {
            return WriteResult::failed(['This work order already has a project.']);
        }
        $description = $workOrder->getDescription() === null ? null
            : trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h3>', '</h4>'], "\n", $workOrder->getDescription())), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $result = $this->projects->create([
            'clientId'    => $workOrder->getClient()?->getId(), // none is fine (ADR-128)
            'name'        => mb_substr($workOrder->getTitle(), 0, self::PROJECT_NAME_MAX),
            'description' => trim(sprintf("From work order %s.\n\n%s", $workOrder->getNumber(), $description ?? '')),
            'status'      => Project::STATUS_ACTIVE,
        ], $actor);
        if (!$result->isSaved()) {
            return $result;
        }
        $workOrder->setProject($result->record)->setUpdatedAt(time());
        $this->em->flush();
        $this->audit->record($actor, 'work_order.convert', sprintf('%s → project #%d', $this->describe($workOrder), $result->record->getId()));

        return $result;
    }

    /**
     * Value, advance and still-to-receive totals of a list, per currency.
     *
     * @param WorkOrder[] $list
     *
     * @return array<string, array{value: int, advance: int, count: int}> amounts in hundredths
     */
    public function totals(array $list): array
    {
        $totals = [];
        foreach ($list as $w) {
            $t = $totals[$w->getCurrency()] ??= ['value' => 0, 'advance' => 0, 'count' => 0];
            $t['value'] += (int) InvoiceMoney::toHundredths($w->getValue() ?? '0');
            $t['advance'] += (int) InvoiceMoney::toHundredths($w->getAdvanceReceived() ?? '0');
            ++$t['count'];
            $totals[$w->getCurrency()] = $t;
        }
        ksort($totals);

        return $totals;
    }

    /** Milestone progress of one work order: done / total. @return array{done: int, total: int} */
    public function progress(WorkOrder $workOrder): array
    {
        $all = $this->milestonesOf($workOrder);

        return ['done' => count(array_filter($all, static fn (WorkOrderMilestone $m) => $m->isDone())), 'total' => count($all)];
    }

    /** "WO-2026-001": the year's next number. */
    public function nextNumber(?\DateTimeImmutable $today = null): string
    {
        $prefix = sprintf('%s-%s-', self::NUMBER_PREFIX, ($today ?? new \DateTimeImmutable('today'))->format('Y'));

        return $prefix.str_pad((string) ($this->workOrders->lastSequenceForYear($prefix) + 1), 3, '0', \STR_PAD_LEFT);
    }

    private function persistWithNumber(WorkOrder $workOrder): void
    {
        // The unique index on number is the backstop should two saves ever pick the same one at the same moment.
        $workOrder->setNumber($this->nextNumber());
        $this->em->persist($workOrder);
        $this->em->flush();
    }

    private function describe(?WorkOrder $workOrder): string
    {
        return $workOrder === null ? 'work order' : sprintf('%s %s', $workOrder->getNumber(), $workOrder->getTitle());
    }
}
