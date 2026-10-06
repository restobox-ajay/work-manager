<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Entity\Client;
use App\Entity\Invoice;
use App\Entity\InvoiceItem;
use App\Entity\Settings\BillingProfile;
use App\Entity\Settings\Currency;
use App\Entity\Settings\TaskStatus;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\InvoiceKind;
use App\Enum\InvoiceStatus;
use App\Repository\ClientRepository;
use App\Repository\InvoiceItemRepository;
use App\Repository\InvoiceRepository;
use App\Repository\Settings\BillingProfileRepository;
use App\Repository\Settings\CurrencyRepository;
use App\Repository\TaskRepository;
use App\Service\Pagination\Paginated;
use App\Service\Settings\TaxRateSettings;
use App\Service\Validation\WriteResult;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creating, changing and cancelling invoices (ADR-077).
 *
 * - The number is the billing profile's prefix + next number, taken under a row lock on the profile so two
 *   invoices saved at once never get the same number.
 * - An approved-tasks invoice bills tasks that are approved, belong to its client, are in its currency and are not
 *   already on another invoice that isn't cancelled. The task rows are locked while that is checked, so two people
 *   cannot bill the same task at the same moment.
 * - From/To fields left blank are filled from the billing profile / client, then copied onto the invoice.
 */
final class InvoiceService
{
    public const PAGE_SIZE = 20;
    private const MAX_LINES = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceItemRepository $items,
        private readonly BillingProfileRepository $profiles,
        private readonly ClientRepository $clients,
        private readonly TaskRepository $tasks,
        private readonly CurrencyRepository $currencies,
        private readonly InvoiceCalculator $calculator,
        private readonly InvoiceHistory $history,
        private readonly TaxRateSettings $taxRates,
    ) {
    }

    /**
     * @param array{term?: ?string, clientId?: ?int, status?: ?string, year?: ?int, month?: ?int} $filters
     *
     * @return Paginated<Invoice>
     */
    public function search(?InvoiceKind $kind, array $filters, int $page): Paginated
    {
        return new Paginated($this->invoices->search($kind, $filters, $page, self::PAGE_SIZE), $this->invoices->countSearch($kind, $filters), $page, self::PAGE_SIZE);
    }

    /**
     * A filtered list: every matching invoice (no paging), in groups by month or year (or one group), each with its
     * totals per currency. Cancelled invoices are listed but left out of the totals.
     *
     * @param array{term?: ?string, clientId?: ?int, status?: ?string, year?: ?int, month?: ?int} $filters
     * @param 'month'|'year'|null $groupBy
     *
     * @return list<array{key: string, label: string, invoices: Invoice[], totals: array<string, int>, count: int}>
     */
    public function grouped(?InvoiceKind $kind, array $filters, ?string $groupBy): array
    {
        $groups = [];
        $monthOnly = ($filters['year'] ?? null) === null ? ($filters['month'] ?? null) : null;
        foreach ($this->invoices->findAllMatching($kind, $filters) as $invoice) {
            $date = $invoice->getInvoiceDate();
            if ($monthOnly !== null && (int) $date?->format('n') !== $monthOnly) {
                continue; // e.g. every March, whatever the year
            }
            [$key, $label] = match ($groupBy) {
                'month' => [$date?->format('Y-m') ?? '', $date?->format('F Y') ?? 'No date'],
                'year'  => [$date?->format('Y') ?? '', $date?->format('Y') ?? 'No date'],
                default => ['all', 'All'],
            };
            $groups[$key] ??= ['key' => $key, 'label' => $label, 'invoices' => [], 'totals' => [], 'count' => 0];
            $groups[$key]['invoices'][] = $invoice;
            if (!$invoice->isCancelled()) {
                $groups[$key]['count']++;
                $groups[$key]['totals'][$invoice->getCurrency()] = ($groups[$key]['totals'][$invoice->getCurrency()] ?? 0)
                    + (int) InvoiceMoney::toHundredths($invoice->getTotal());
            }
        }

        return array_values($groups);
    }

    /** @return int[] */
    public function years(?InvoiceKind $kind): array
    {
        return $this->invoices->findYears($kind);
    }

    /** A blank form: today's date, the only active billing profile pre-selected when there is just one. */
    public function blankInput(): InvoiceInput
    {
        $input = new InvoiceInput();
        $input->invoiceDate = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $profiles = $this->profiles->findSelectable();
        if (count($profiles) === 1) {
            $this->fillFrom($input, $profiles[0], overwrite: true);
        }

        return $input;
    }

    /**
     * Approved tasks of a client not yet on a live invoice, optionally in one currency.
     *
     * @return Task[]
     */
    public function invoiceableTasks(int $clientId, ?string $currency): array
    {
        $tasks = $this->tasks->findAllMatching([
            'clientId'        => $clientId,
            'taskStatusId'    => TaskStatus::APPROVED_ID,
            'includeArchived' => true,
        ], 't.id', 'ASC');
        $onInvoice = $this->items->findLiveInvoiceNumbersForTasks(array_map(static fn (Task $task) => (int) $task->getId(), $tasks));
        $currencyId = $currency !== null ? $this->currencyId($currency) : null;

        return array_values(array_filter($tasks, static fn (Task $task) => !isset($onInvoice[(int) $task->getId()])
            && ($currencyId === null || $task->getCurrencyId() === $currencyId)));
    }

    /**
     * The form for an approved-tasks invoice: the client's address as Billed To and one line per chosen task.
     *
     * @param int[] $taskIds
     */
    public function inputForTasks(Client $client, string $currency, array $taskIds): InvoiceInput
    {
        $input = $this->blankInput();
        $input->clientId = (int) $client->getId();
        $input->currency = $currency;
        $this->fillTo($input, $client, overwrite: true);

        $chosen = array_flip($taskIds);
        foreach ($this->invoiceableTasks((int) $client->getId(), $currency) as $task) {
            if (isset($chosen[(int) $task->getId()])) {
                $input->lines[] = $this->lineForTask($task);
            }
        }

        return $input;
    }

    /** @return WriteResult<Invoice> */
    public function create(InvoiceKind $kind, InvoiceInput $input, User $actor): WriteResult
    {
        $errors = $this->validate($kind, $input, null);
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $result = $this->em->wrapInTransaction(function () use ($kind, $input, $actor): Invoice|array {
            $errors = $this->checkTasks($input, null, lock: true);
            if ($errors !== []) {
                return $errors;
            }
            $profile = $this->em->find(BillingProfile::class, (int) $input->billingProfileId, LockMode::PESSIMISTIC_WRITE);
            if (!$profile instanceof BillingProfile) {
                return ['Choose who the invoice is billed by.'];
            }

            $invoice = (new Invoice())
                ->setKind($kind)
                ->setNumber($this->takeNumber($profile))
                ->setCreatedBy($actor->getId())
                ->setCreatedAt(time());
            $this->apply($invoice, $input, $actor);
            $this->em->persist($invoice);
            $this->em->flush();

            return $invoice;
        });

        if (is_array($result)) {
            return WriteResult::failed($result);
        }
        $this->history->record($result, InvoiceHistory::CREATED, sprintf('%s, %d line(s), total %s', $kind->label(), $result->getItems()->count(),
            InvoiceMoney::display($result->getTotal(), $result->getCurrency())), $actor);

        return WriteResult::saved($result);
    }

    /** @return WriteResult<Invoice> */
    public function update(Invoice $invoice, InvoiceInput $input, User $actor): WriteResult
    {
        if ($invoice->isCancelled()) {
            return WriteResult::failed(['A cancelled invoice cannot be changed.']);
        }
        $errors = $this->validate($invoice->getKind(), $input, $invoice);
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }
        $before = InvoiceMoney::display($invoice->getTotal(), $invoice->getCurrency());

        $result = $this->em->wrapInTransaction(function () use ($invoice, $input, $actor): Invoice|array {
            $errors = $this->checkTasks($input, $invoice, lock: true);
            if ($errors !== []) {
                return $errors;
            }
            $this->apply($invoice, $input, $actor);
            $this->em->flush();

            return $invoice;
        });

        if (is_array($result)) {
            $this->em->refresh($invoice);

            return WriteResult::failed($result);
        }
        $after = InvoiceMoney::display($invoice->getTotal(), $invoice->getCurrency());
        $this->history->record($invoice, InvoiceHistory::UPDATED,
            sprintf('%d line(s), total %s', $invoice->getItems()->count(), $before === $after ? $after : $before.' → '.$after), $actor);

        return WriteResult::saved($invoice);
    }

    /**
     * Deletes an invoice that was never sent (ADR-097): its lines go with it (so its tasks can be invoiced again) and
     * its number is not handed out again. A sent invoice is refused — it is checked under a row lock, so an email
     * going out at the same moment cannot slip between the check and the delete.
     *
     * @return bool false when the invoice has been sent and was kept
     */
    public function delete(Invoice $invoice, User $actor): bool
    {
        return $this->em->wrapInTransaction(function () use ($invoice, $actor): bool {
            $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            if ($invoice->isSent()) {
                return false;
            }
            $this->history->forget($invoice, $actor);
            $this->em->remove($invoice);
            $this->em->flush();

            return true;
        });
    }

    /** Cancelling keeps the invoice and its number but releases its tasks for another invoice. */
    public function cancel(Invoice $invoice, ?string $reason, User $actor): void
    {
        if ($invoice->isCancelled()) {
            return;
        }
        $invoice->setStatus(InvoiceStatus::Cancelled)->setCancelledAt(time())->setUpdatedBy($actor->getId())->setUpdatedAt(time());
        $this->em->flush();
        $this->history->record($invoice, InvoiceHistory::CANCELLED, $reason, $actor);
    }

    /** @return list<string> */
    private function validate(InvoiceKind $kind, InvoiceInput $input, ?Invoice $invoice): array
    {
        $errors = [];
        $profile = $input->billingProfileId !== null ? $this->profiles->find($input->billingProfileId) : null;
        $keepsProfile = $invoice !== null && $invoice->getBillingProfileId() === $input->billingProfileId;
        if ($profile === null || (!$profile->isActive() && !$keepsProfile)) {
            $errors[] = 'Choose who the invoice is billed by.';
        } else {
            $this->fillFrom($input, $profile, overwrite: false);
        }

        $client = $input->clientId !== null ? $this->clients->find($input->clientId) : null;
        if ($input->clientId !== null && $client === null) {
            $errors[] = 'Choose a valid client.';
        } elseif ($client !== null) {
            $this->fillTo($input, $client, overwrite: false);
        }
        if ($kind === InvoiceKind::Tasks && $client === null) {
            $errors[] = 'An approved-tasks invoice needs its client.';
        }

        $savedRates = $invoice !== null ? array_map(static fn (InvoiceItem $item) => $item->getGstRate(), $invoice->getItems()->toArray()) : [];
        $errors = [...$errors, ...$this->validateHeader($input), ...$this->validateLines($kind, $input, array_keys($this->taxRates->invoiceChoices($savedRates)))];

        return $errors !== [] ? $errors : $this->checkTasks($input, $invoice, lock: false);
    }

    /** @return list<string> */
    private function validateHeader(InvoiceInput $input): array
    {
        $errors = [];
        $date = $this->parseDate($input->invoiceDate);
        if ($date === null) {
            $errors[] = 'Enter the invoice date.';
        }
        if ($input->dueDate !== '' && ($due = $this->parseDate($input->dueDate)) === null) {
            $errors[] = 'Enter a valid due date, or leave it blank.';
        } elseif ($input->dueDate !== '' && $date !== null && $due < $date) {
            $errors[] = 'The due date cannot be before the invoice date.';
        }
        if ($this->currencyId($input->currency) === null) {
            $errors[] = 'Choose a currency from the list.';
        }
        foreach (['fromName' => 'Billed By name', 'toName' => 'Billed To name'] as $field => $label) {
            if ($input->{$field} === '') {
                $errors[] = sprintf('%s is required.', $label);
            } elseif (mb_strlen($input->{$field}) > 255) {
                $errors[] = sprintf('%s cannot be longer than 255 characters.', $label);
            }
        }
        foreach (['fromEmail' => 'Billed By email', 'toEmail' => 'Billed To email'] as $field => $label) {
            if ($input->{$field} !== '' && (filter_var($input->{$field}, FILTER_VALIDATE_EMAIL) === false || mb_strlen($input->{$field}) > 255)) {
                $errors[] = sprintf('%s is not a valid email address.', $label);
            }
        }
        foreach (['fromPhone' => 30, 'toPhone' => 30, 'fromTaxNumber' => 50] as $field => $max) {
            if (mb_strlen($input->{$field}) > $max) {
                $errors[] = sprintf('%s cannot be longer than %d characters.', $field === 'fromTaxNumber' ? 'Tax number' : 'Phone', $max);
            }
        }

        return $errors;
    }

    /** @return list<string> */
    /** @param list<string|int> $gstRates the allowed GST rates ("18.00"), from Settings › Tax Rates */
    private function validateLines(InvoiceKind $kind, InvoiceInput $input, array $gstRates): array
    {
        if ($input->lines === []) {
            return ['Add at least one item.'];
        }
        if (count($input->lines) > self::MAX_LINES) {
            return [sprintf('An invoice can have at most %d items.', self::MAX_LINES)];
        }
        if ($kind === InvoiceKind::Independent && $input->taskIds() !== []) {
            return ['An independent invoice cannot bill tasks: use Approved Tasks Invoice.'];
        }
        if ($kind === InvoiceKind::Tasks && $input->taskIds() === []) {
            return ['An approved-tasks invoice needs at least one approved task.'];
        }

        $errors = [];
        foreach ($input->lines as $index => $line) {
            $row = $index + 1;
            if ($line->name === '') {
                $errors[] = sprintf('Item %d: enter the item name.', $row);
            } elseif (mb_strlen($line->name) > 255) {
                $errors[] = sprintf('Item %d: the name cannot be longer than 255 characters.', $row);
            }
            // Quantity and Rate may be left empty; then the Amount is what the line bills.
            $quantity = $line->quantity !== '' ? InvoiceMoney::toHundredths($line->quantity) : null;
            if ($line->quantity !== '' && ($quantity === null || $quantity <= 0 || $quantity >= 100_000_000_00)) {
                $errors[] = sprintf('Item %d: quantity must be a number above 0, with at most 2 decimals, or left empty.', $row);
            }
            $rate = $line->rate !== '' ? InvoiceMoney::toHundredths($line->rate) : null;
            if ($line->rate !== '' && ($rate === null || $rate < 0 || $rate >= 1_000_000_000_00)) {
                $errors[] = sprintf('Item %d: rate must be a number of 0 or more, with at most 2 decimals, or left empty.', $row);
            }
            if ($line->amount === '' && ($line->quantity === '' || $line->rate === '')) {
                $errors[] = sprintf('Item %d: enter an amount, or a quantity and a rate.', $row);
            }
            $gst = InvoiceMoney::toHundredths($line->gstRate);
            $allowed = array_map(static fn ($rate) => InvoiceMoney::toHundredths((string) $rate), $gstRates);
            if ($gst === null || !in_array($gst, $allowed, true)) {
                $errors[] = sprintf('Item %d: choose a GST rate from Settings › Tax Rates.', $row);
            }
            $amount = $line->amount !== '' ? InvoiceMoney::toHundredths($line->amount) : null;
            if ($line->amount !== '' && ($amount === null || $amount < 0)) {
                $errors[] = sprintf('Item %d: amount must be a number of 0 or more, with at most 2 decimals.', $row);
            } elseif (($amount ?? ($quantity !== null && $rate !== null ? InvoiceMoney::multiply($quantity, $rate) : 0)) >= 1_000_000_000_00) {
                $errors[] = sprintf('Item %d: the amount is too large.', $row);
            }
        }
        if (count($input->taskIds()) !== count(array_filter($input->lines, static fn (InvoiceLineInput $line) => $line->taskId !== null))) {
            $errors[] = 'A task can appear only once on an invoice.';
        }

        return $errors;
    }

    /**
     * The tasks on the form must be approved, the invoice client's, in the invoice currency and on no other live
     * invoice. With $lock the task rows are read FOR UPDATE (inside the save transaction).
     *
     * @return list<string>
     */
    private function checkTasks(InvoiceInput $input, ?Invoice $invoice, bool $lock): array
    {
        $taskIds = $input->taskIds();
        if ($taskIds === []) {
            return [];
        }
        $query = $this->em->createQuery('SELECT t FROM '.Task::class.' t WHERE t.id IN (:ids)')->setParameter('ids', $taskIds);
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        /** @var Task[] $found */
        $found = $query->getResult();
        $byId = [];
        foreach ($found as $task) {
            $byId[(int) $task->getId()] = $task;
        }
        $onInvoice = $this->items->findLiveInvoiceNumbersForTasks($taskIds, $invoice?->getId());
        $currencyId = $this->currencyId($input->currency);
        $keptTaskIds = $invoice?->taskIds() ?? [];

        $errors = [];
        foreach ($taskIds as $taskId) {
            $task = $byId[$taskId] ?? null;
            if ($task === null) {
                $errors[] = sprintf('Task #%d no longer exists.', $taskId);
                continue;
            }
            $label = sprintf('Task #%d "%s"', $taskId, $task->getName());
            if (isset($onInvoice[$taskId])) {
                $errors[] = sprintf('%s is already on invoice %s.', $label, $onInvoice[$taskId]);
            }
            // A task already on this invoice may have moved on (e.g. to Paid) since: it stays billable here.
            if ($task->getTaskStatusId() !== TaskStatus::APPROVED_ID && !in_array($taskId, $keptTaskIds, true)) {
                $errors[] = sprintf('%s is not approved.', $label);
            }
            if ($this->clientIdOf($task) !== $input->clientId) {
                $errors[] = sprintf('%s belongs to another client.', $label);
            }
            if ($task->getCurrencyId() !== $currencyId) {
                $errors[] = sprintf('%s is not in %s.', $label, $input->currency);
            }
        }

        return $errors;
    }

    private function apply(Invoice $invoice, InvoiceInput $input, User $actor): void
    {
        $invoice->setBillingProfileId($input->billingProfileId)
            ->setClientId($input->clientId)
            ->setInvoiceDate($this->parseDate($input->invoiceDate))
            ->setDueDate($this->parseDate($input->dueDate))
            ->setCurrency($input->currency)
            ->setFromName($input->fromName)
            ->setFromAddress($this->nullable($input->fromAddress))
            ->setFromEmail($this->nullable($input->fromEmail))
            ->setFromPhone($this->nullable($input->fromPhone))
            ->setFromTaxNumber($this->nullable($input->fromTaxNumber))
            ->setToName($input->toName)
            ->setToAddress($this->nullable($input->toAddress))
            ->setToEmail($this->nullable($input->toEmail))
            ->setToPhone($this->nullable($input->toPhone))
            ->setDescription($this->nullable($input->description))
            ->setUpdatedBy($actor->getId())
            ->setUpdatedAt(time());

        $items = [];
        foreach ($input->lines as $line) {
            $items[] = $this->calculator->priceLine((new InvoiceItem())
                ->setTaskId($line->taskId)
                ->setName($line->name)
                ->setDescription($this->nullable($line->description))
                ->setGstRate(InvoiceMoney::toDecimal((int) InvoiceMoney::toHundredths($line->gstRate)))
                ->setQuantity($line->quantity !== '' ? InvoiceMoney::toDecimal((int) InvoiceMoney::toHundredths($line->quantity)) : null)
                ->setRate($line->rate !== '' ? InvoiceMoney::toDecimal((int) InvoiceMoney::toHundredths($line->rate)) : null),
                $line->amount !== '' ? InvoiceMoney::toHundredths($line->amount) : null);
        }
        $this->calculator->totalInvoice($invoice->replaceItems(...$items));
    }

    /** Prefix + next number, skipping any number already taken (e.g. by a prefix changed back). */
    private function takeNumber(BillingProfile $profile): string
    {
        $next = max(1, $profile->getNextNumber());
        while ($this->invoices->numberExists($profile->getInvoicePrefix().$next)) {
            ++$next;
        }
        $profile->setNextNumber($next + 1)->setUpdatedAt(time());

        return $profile->getInvoicePrefix().$next;
    }

    private function lineForTask(Task $task): InvoiceLineInput
    {
        $project = $task->getProject()?->getName();

        return new InvoiceLineInput(
            (int) $task->getId(),
            $task->getName(),
            trim(sprintf('Task #%d%s', (int) $task->getId(), $project !== null ? ' · '.$project : '')),
            '0',
            '1',
            InvoiceMoney::toDecimal((int) InvoiceMoney::toHundredths($task->getTotalAmount() ?? '0')),
        );
    }

    private function fillFrom(InvoiceInput $input, BillingProfile $profile, bool $overwrite): void
    {
        $input->billingProfileId = (int) $profile->getId();
        $this->fill($input, [
            'fromName'      => $profile->getName(),
            'fromAddress'   => $profile->addressText(),
            'fromEmail'     => (string) $profile->getEmail(),
            'fromPhone'     => (string) $profile->getPhone(),
            'fromTaxNumber' => (string) $profile->getTaxNumber(),
        ], $overwrite);
    }

    private function fillTo(InvoiceInput $input, Client $client, bool $overwrite): void
    {
        $this->fill($input, [
            'toName'    => self::billedToName($client),
            'toAddress' => self::clientAddress($client),
            'toEmail'   => (string) $client->getEmail(),
            'toPhone'   => (string) $client->getPhone(),
        ], $overwrite);
    }

    /** @param array<string, string> $values */
    private function fill(InvoiceInput $input, array $values, bool $overwrite): void
    {
        foreach ($values as $field => $value) {
            if ($overwrite || $input->{$field} === '') {
                $input->{$field} = $value;
            }
        }
    }

    /** The name printed as Billed To: the client's company name when it has one. */
    public static function billedToName(Client $client): string
    {
        return $client->getCompanyName() ?? $client->getName();
    }

    /** A client's address as printed under Billed To. */
    public static function clientAddress(Client $client): string
    {
        $join = static fn (string $glue, array $parts) => implode($glue, array_filter($parts, static fn (?string $part) => $part !== null && trim($part) !== ''));

        return $join("\n", [
            $client->getStreetAddress1(),
            $client->getStreetAddress2(),
            $join(', ', [$client->getCity(), $client->getProvince() ?? $client->getState()]),
            $join(' - ', [$client->getCountry(), $client->getZipCode()]),
        ]);
    }

    private function clientIdOf(Task $task): ?int
    {
        return $task->getProject()?->getClient()?->getId() ?? $task->getClientId();
    }

    private function currencyId(string $code): ?int
    {
        $currency = $this->currencies->findOneBy(['shortName' => $code]);

        return $currency instanceof Currency ? $currency->getId() : null;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function nullable(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}
