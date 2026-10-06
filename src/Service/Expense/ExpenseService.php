<?php

declare(strict_types=1);

namespace App\Service\Expense;

use App\Entity\Expense\Expense;
use App\Entity\User;
use App\Repository\Expense\ExpenseCategoryRepository;
use App\Repository\Expense\ExpenseRepository;
use App\Repository\Rent\RentPropertyRepository;
use App\Service\Invoice\InvoiceMoney;
use App\Service\Rent\RentMoney;
use App\Service\Validation\InputValue;
use App\Service\Validation\WriteResult;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Monthly expenses (ADR-089): recording them, a month's list with its totals by category, and a year from January to
 * December by category. Amounts in paise (RentMoney / InvoiceMoney), shown in ₹; every change audited as expense.*.
 */
final class ExpenseService
{
    public const METHODS = RentMoney::METHODS;

    /**
     * What the optional payment-details field asks for, per "Paid by" method (ADR-097). A method that is not listed
     * (cash) has no details: the field is hidden and anything posted for it is dropped.
     */
    public const PAYMENT_DETAIL_LABELS = [
        'upi'    => 'UPI ID / transaction no.',
        'bank'   => 'Bank / transaction reference',
        'cheque' => 'Cheque no. and details (bank, date)',
        'other'  => 'Payment details',
    ];
    private const MAX_PAYMENT_DETAILS = 255;

    /** The bucket for expenses saved without a category in the month and year totals. */
    public const UNCATEGORISED = 'Uncategorised';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExpenseRepository $expenses,
        private readonly ExpenseCategoryRepository $categories,
        private readonly RentPropertyRepository $properties,
        private readonly WorkAuditTrail $audit,
    ) {
    }

    /** @return array<string, mixed> the form's fields for an expense */
    public function valuesFrom(Expense $expense): array
    {
        return [
            'spentOn'     => $expense->getSpentOn()?->format('Y-m-d'),
            'categoryId'  => $expense->getCategory()?->getId(),
            'amount'      => $expense->getAmount(),
            'method'      => $expense->getMethod(),
            'description' => $expense->getDescription(),
            'propertyId'  => $expense->getProperty()?->getId(),
            'note'        => $expense->getNote(),
            'paymentDetails' => $expense->getPaymentDetails(),
        ];
    }

    /** @param array<string, mixed> $posted @return WriteResult<Expense> */
    public function save(?Expense $expense, array $posted, User $actor): WriteResult
    {
        $errors = [];
        $text = InputValue::text($posted['spentOn'] ?? null) ?? '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            $errors[] = 'Enter the date of the expense.';
            $date = null;
        }
        // Optional (e.g. upkeep of a rent property needs no category); a posted id must still exist.
        $category = null;
        if (($categoryId = InputValue::int($posted['categoryId'] ?? null)) !== null && ($category = $this->categories->find($categoryId)) === null) {
            $errors[] = 'Choose a valid category, or none.';
        }
        $amount = InvoiceMoney::toHundredths(InputValue::text($posted['amount'] ?? null) ?? '');
        if ($amount === null || $amount <= 0 || $amount >= 1_000_000_000_00) {
            $errors[] = 'Amount must be a number above 0, with at most 2 decimals.';
        }
        $method = InputValue::text($posted['method'] ?? null) ?? '';
        if (!isset(self::METHODS[$method])) {
            $errors[] = 'Choose how it was paid.';
        }
        $paymentDetails = isset(self::PAYMENT_DETAIL_LABELS[$method]) ? InputValue::text($posted['paymentDetails'] ?? null) : null;
        if ($paymentDetails !== null && mb_strlen($paymentDetails) > self::MAX_PAYMENT_DETAILS) {
            $errors[] = sprintf('Payment details can be at most %d characters.', self::MAX_PAYMENT_DETAILS);
        }
        $description = InputValue::text($posted['description'] ?? null) ?? '';
        if ($description === '' || mb_strlen($description) > 255) {
            $errors[] = 'Enter what it was for (at most 255 characters).';
        }
        $property = null;
        if (($propertyId = InputValue::int($posted['propertyId'] ?? null)) !== null && ($property = $this->properties->find($propertyId)) === null) {
            $errors[] = 'Choose a valid property, or none.';
        }
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $isNew = $expense === null;
        $expense ??= (new Expense())->setCreatedAt(time())->setCreatedBy($actor->getId());
        $note = is_scalar($posted['note'] ?? null) ? trim(str_replace("\r\n", "\n", (string) $posted['note'])) : '';
        $expense->setSpentOn($date)->setCategory($category)->setAmount(RentMoney::d((int) $amount))->setMethod($method)
            ->setPaymentDetails($paymentDetails)->setDescription($description)->setProperty($property)
            ->setNote($note !== '' ? $note : null)->setUpdatedAt(time());
        if ($isNew) {
            $this->em->persist($expense);
        }
        $this->em->flush();
        $this->audit->record($actor, 'expense.'.($isNew ? 'create' : 'update'), $this->describe($expense));

        return WriteResult::saved($expense);
    }

    public function delete(Expense $expense, User $actor): void
    {
        $label = $this->describe($expense);
        $this->em->remove($expense);
        $this->em->flush();
        $this->audit->record($actor, 'expense.delete', $label);
    }

    /**
     * One month: its expenses (optionally one category) and totals by category.
     *
     * @return array{expenses: Expense[], byCategory: array<string, int>, total: int}
     */
    public function month(\DateTimeImmutable $month, ?int $categoryId): array
    {
        $expenses = $this->expenses->findBetween($month, $month->modify('first day of next month'), $categoryId);
        $byCategory = [];
        $total = 0;
        foreach ($expenses as $expense) {
            $h = RentMoney::h($expense->getAmount());
            $name = $this->categoryName($expense);
            $byCategory[$name] = ($byCategory[$name] ?? 0) + $h;
            $total += $h;
        }
        arsort($byCategory);

        return ['expenses' => $expenses, 'byCategory' => $byCategory, 'total' => $total];
    }

    /**
     * A year, January to December: each month's total per category, the month total and count; the year's totals per
     * category. Columns are the categories with spending that year, biggest first.
     *
     * @return array{months: list<array{month: \DateTimeImmutable, byCategory: array<string, int>, total: int, count: int}>, categories: list<string>, totals: array<string, int>, total: int, average: int, highest: ?\DateTimeImmutable}
     */
    public function year(int $year): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
        $months = [];
        for ($m = 1; $m <= 12; ++$m) {
            $months[$m] = ['month' => $from->setDate($year, $m, 1), 'byCategory' => [], 'total' => 0, 'count' => 0];
        }
        $totals = [];
        foreach ($this->expenses->findBetween($from, $from->modify('+1 year')) as $expense) {
            $m = (int) $expense->getSpentOn()?->format('n');
            $h = RentMoney::h($expense->getAmount());
            $name = $this->categoryName($expense);
            $months[$m]['byCategory'][$name] = ($months[$m]['byCategory'][$name] ?? 0) + $h;
            $months[$m]['total'] += $h;
            ++$months[$m]['count'];
            $totals[$name] = ($totals[$name] ?? 0) + $h;
        }
        arsort($totals);
        $total = array_sum($totals);
        $withSpend = array_filter($months, static fn (array $m) => $m['total'] > 0);
        $highest = $withSpend === [] ? null : array_reduce($withSpend, static fn (?array $c, array $m) => $c === null || $m['total'] > $c['total'] ? $m : $c)['month'];

        return [
            'months'     => array_values($months),
            'categories' => array_keys($totals),
            'totals'     => $totals,
            'total'      => $total,
            'average'    => $withSpend === [] ? 0 : intdiv($total, count($withSpend)),
            'highest'    => $highest,
        ];
    }

    /** @return int[] */
    public function years(): array
    {
        $now = (int) date('Y');

        return array_values(array_unique([...range($now + 1, $now - 3), ...$this->expenses->findYears()]));
    }

    private function categoryName(Expense $expense): string
    {
        return $expense->getCategory()?->getName() ?? self::UNCATEGORISED;
    }

    private function describe(Expense $expense): string
    {
        return sprintf('#%d %s %s %s', (int) $expense->getId(), $expense->getSpentOn()?->format('Y-m-d'), $this->categoryName($expense),
            InvoiceMoney::display($expense->getAmount(), RentMoney::CURRENCY));
    }
}
