<?php

declare(strict_types=1);

namespace App\Service\Rent;

use App\Entity\Rent\RentBill;
use App\Entity\Rent\RentPayment;
use App\Entity\Rent\RentProperty;
use App\Entity\Rent\RentTenant;
use App\Entity\User;
use App\Repository\Rent\RentBillRepository;
use App\Repository\Rent\RentPropertyRepository;
use App\Service\Invoice\InvoiceMoney;
use App\Service\Validation\InputValue;
use App\Service\Validation\WriteResult;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Every write of the rent module (ADR-085): properties, tenancies, monthly bills and payments. Amounts are checked as
 * two-decimal numbers; a bill's electricity is (current − previous reading) × the rate, computed here, never typed.
 * Every change goes to the audit log as rent.<record>_<action>.
 */
final class RentService
{
    private const MAX_AMOUNT = 1_000_000_000_00;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RentBillRepository $bills,
        private readonly RentPropertyRepository $properties,
        private readonly WorkAuditTrail $audit,
    ) {
    }

    // ── Properties ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $posted @return WriteResult<RentProperty> */
    public function saveProperty(?RentProperty $property, array $posted, User $actor): WriteResult
    {
        $name = InputValue::text($posted['name'] ?? null) ?? '';
        $errors = [];
        $this->requireText($errors, $name, 'Name', 120);
        $rate = $this->amount($errors, $posted['electricityRate'] ?? null, 'Electricity rate per unit', true);
        $rent = $this->amount($errors, $posted['defaultRent'] ?? null, 'Monthly rent', true);
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $isNew = $property === null;
        $property ??= (new RentProperty())->setCreatedAt(time());
        $property->setName($name)
            ->setAddress($this->multiline($posted['address'] ?? null))
            ->setElectricityRate(RentMoney::d($rate))
            ->setDefaultRent(RentMoney::d($rent))
            ->setIsActive(InputValue::flag($posted['isActive'] ?? null))
            ->setNotes($this->multiline($posted['notes'] ?? null))
            ->setUpdatedAt(time());

        return $this->persist($property, $isNew, 'property', $property->getName(), $actor);
    }

    // ── Tenants ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $posted @return WriteResult<RentTenant> */
    public function saveTenant(?RentTenant $tenant, array $posted, User $actor): WriteResult
    {
        $errors = [];
        $name = InputValue::text($posted['name'] ?? null) ?? '';
        $this->requireText($errors, $name, 'Tenant name', 120);
        $property = ($id = InputValue::int($posted['propertyId'] ?? null)) !== null ? $this->properties->find($id) : null;
        if ($property === null) {
            $errors[] = 'Choose the property.';
        }
        $email = InputValue::text($posted['email'] ?? null);
        if ($email !== null && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 180)) {
            $errors[] = 'Enter a valid email address.';
        }
        $phone = InputValue::text($posted['phone'] ?? null);
        if ($phone !== null && mb_strlen($phone) > 30) {
            $errors[] = 'Phone cannot be longer than 30 characters.';
        }
        $rent = $this->amount($errors, $posted['monthlyRent'] ?? null, 'Monthly rent', true);
        $deposit = $this->amount($errors, $posted['deposit'] ?? null, 'Security deposit', false);
        $meter = $this->amount($errors, $posted['openingMeter'] ?? null, 'Opening meter reading', false);
        $start = $this->date($errors, $posted['startDate'] ?? null, 'Move-in date', true);
        $end = $this->date($errors, $posted['endDate'] ?? null, 'Move-out date', false);
        if ($start !== null && $end !== null && $end < $start) {
            $errors[] = 'The move-out date cannot be before the move-in date.';
        }
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $isNew = $tenant === null;
        $tenant ??= (new RentTenant())->setCreatedAt(time());
        $tenant->setProperty($property)
            ->setName($name)
            ->setPhone($phone)
            ->setEmail($email)
            ->setMonthlyRent(RentMoney::d($rent))
            ->setDeposit(RentMoney::d($deposit))
            ->setOpeningMeter(RentMoney::d($meter))
            ->setStartDate($start)
            ->setEndDate($end)
            ->setIsActive(InputValue::flag($posted['isActive'] ?? null))
            ->setNotes($this->multiline($posted['notes'] ?? null))
            ->setUpdatedAt(time());

        return $this->persist($tenant, $isNew, 'tenant', $tenant->getName(), $actor);
    }

    // ── Bills ────────────────────────────────────────────────────────────────

    /**
     * The form's starting values for a tenant's next bill (or the bill for $month): the month after their last bill (or
     * their move-in month),
     * the last bill's current reading (or the opening reading) as the previous one, their rent and the property's rate.
     *
     * @return array<string, string>
     */
    public function nextBillValues(RentTenant $tenant, ?\DateTimeImmutable $month = null): array
    {
        // For a given month (the month-at-once page) the previous reading is the last bill before that month.
        $last = $month !== null ? $this->bills->findPreviousBill($tenant, $month) : $this->bills->findOneBy(['tenant' => $tenant], ['period' => 'DESC']);
        $period = $month ?? $last?->getPeriod()?->modify('first day of next month')
            ?? ($tenant->getStartDate() ?? new \DateTimeImmutable('today'))->modify('first day of this month');

        return [
            'period'        => $period->format('Y-m'),
            'rentAmount'    => $tenant->getMonthlyRent(),
            'meterPrevious' => $last?->getMeterCurrent() ?? $tenant->getOpeningMeter(),
            'meterCurrent'  => '',
            'rate'          => $tenant->getProperty()?->getElectricityRate() ?? '0.00',
            'otherAmount'   => '',
            'otherNote'     => '',
        ];
    }

    /** @return array<string, string> */
    public function billValues(RentBill $bill): array
    {
        return [
            'period'        => $bill->getPeriod()?->format('Y-m') ?? '',
            'rentAmount'    => $bill->getRentAmount(),
            'meterPrevious' => $bill->getMeterPrevious(),
            'meterCurrent'  => $bill->getMeterCurrent(),
            'rate'          => $bill->getRate(),
            'otherAmount'   => $bill->getOtherAmount() === '0.00' ? '' : $bill->getOtherAmount(),
            'otherNote'     => (string) $bill->getOtherNote(),
        ];
    }

    /** @param array<string, mixed> $posted @return WriteResult<RentBill> */
    public function saveBill(RentTenant $tenant, ?RentBill $bill, array $posted, User $actor): WriteResult
    {
        $errors = [];
        $period = $this->month($errors, $posted['period'] ?? null);
        $rent = $this->amount($errors, $posted['rentAmount'] ?? null, 'Rent', true);
        $previous = $this->amount($errors, $posted['meterPrevious'] ?? null, 'Previous meter reading', true);
        $current = $this->amount($errors, $posted['meterCurrent'] ?? null, 'Current meter reading', true);
        $rate = $this->amount($errors, $posted['rate'] ?? null, 'Rate per unit', true);
        $other = $this->amount($errors, $posted['otherAmount'] ?? null, 'Other charges', false);
        $otherNote = InputValue::text($posted['otherNote'] ?? null);
        if ($otherNote !== null && mb_strlen($otherNote) > 255) {
            $errors[] = 'The note for other charges cannot be longer than 255 characters.';
        }
        if ($errors === [] && $current < $previous) {
            $errors[] = 'The current meter reading cannot be lower than the previous one.';
        }
        if ($period !== null) {
            $existing = $this->bills->findOneForPeriod($tenant, $period);
            if ($existing !== null && $existing !== $bill) {
                $errors[] = sprintf('%s already has a bill for %s: edit that one instead.', $tenant->getName(), $period->format('F Y'));
            }
        }
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $units = $current - $previous;
        $electricity = RentMoney::times($units, $rate);
        $isNew = $bill === null;
        $bill ??= (new RentBill())->setTenant($tenant)->setCreatedAt(time())->setCreatedBy($actor->getId());
        $bill->setPeriod($period)
            ->setRentAmount(RentMoney::d($rent))
            ->setMeterPrevious(RentMoney::d($previous))
            ->setMeterCurrent(RentMoney::d($current))
            ->setUnits(RentMoney::d($units))
            ->setRate(RentMoney::d($rate))
            ->setElectricityAmount(RentMoney::d($electricity))
            ->setOtherAmount(RentMoney::d($other))
            ->setOtherNote($otherNote)
            ->setTotal(RentMoney::d($rent + $electricity + $other))
            ->setUpdatedAt(time());

        return $this->persist($bill, $isNew, 'bill', sprintf('%s %s total %s', $tenant->getName(), $period->format('Y-m'),
            InvoiceMoney::display($bill->getTotal(), RentMoney::CURRENCY)), $actor);
    }

    /** Deletes a tenancy with all its bills and payments (ADR-109). @return array{bills: int, payments: int} */
    public function deleteTenant(RentTenant $tenant, User $actor): array
    {
        $label = sprintf('#%d %s', (int) $tenant->getId(), $tenant->getName());
        $counts = $this->removeTenancies([(int) $tenant->getId()]);
        $this->audit->record($actor, 'rent.tenant_delete', sprintf('%s with %d bills and %d payments', $label, $counts['bills'], $counts['payments']));

        return $counts;
    }

    /**
     * Deletes a property with its tenancies, their bills and payments (ADR-109). Expenses recorded against it stay,
     * without the property.
     *
     * @return array{tenants: int, bills: int, payments: int}
     */
    public function deleteProperty(RentProperty $property, User $actor): array
    {
        $id = (int) $property->getId();
        $label = sprintf('#%d %s', $id, $property->getName());
        $tenantIds = array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT id FROM rent_tenant WHERE property_id = ?', [$id]));
        $counts = ['tenants' => count($tenantIds)] + $this->removeTenancies($tenantIds, $id);
        $this->audit->record($actor, 'rent.property_delete', sprintf('%s with %d tenants, %d bills and %d payments', $label, $counts['tenants'], $counts['bills'], $counts['payments']));

        return $counts;
    }

    /** @param list<int> $tenantIds @return array{bills: int, payments: int} */
    private function removeTenancies(array $tenantIds, ?int $propertyId = null): array
    {
        $this->em->clear(); // rows go behind the ORM's back
        $db = $this->em->getConnection();

        return $db->transactional(static function () use ($db, $tenantIds, $propertyId): array {
            $ints = ArrayParameterType::INTEGER;
            $counts = ['bills' => 0, 'payments' => 0];
            if ($tenantIds !== []) {
                $counts['payments'] = $db->executeStatement('DELETE FROM rent_payment WHERE tenant_id IN (?)', [$tenantIds], [$ints]);
                $counts['bills'] = $db->executeStatement('DELETE FROM rent_bill WHERE tenant_id IN (?)', [$tenantIds], [$ints]);
                $db->executeStatement('DELETE FROM rent_tenant WHERE id IN (?)', [$tenantIds], [$ints]);
            }
            if ($propertyId !== null) {
                $db->executeStatement('DELETE FROM rent_property WHERE id = ?', [$propertyId]); // expenses: FK sets property_id NULL
            }

            return $counts;
        });
    }

    public function deleteBill(RentBill $bill, User $actor): void
    {
        $label = sprintf('%s %s', $bill->getTenant()?->getName(), $bill->getPeriod()?->format('Y-m'));
        $this->em->remove($bill);
        $this->em->flush();
        $this->audit->record($actor, 'rent.bill_delete', $label);
    }

    // ── Payments ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $posted @return WriteResult<RentPayment> */
    public function savePayment(RentTenant $tenant, array $posted, User $actor): WriteResult
    {
        $errors = [];
        $paidOn = $this->date($errors, $posted['paidOn'] ?? null, 'Payment date', true);
        $amount = $this->amount($errors, $posted['amount'] ?? null, 'Amount', true);
        if ($errors === [] && $amount <= 0) {
            $errors[] = 'Amount must be more than 0.';
        }
        $kind = InputValue::text($posted['kind'] ?? null) ?? '';
        if (!isset(RentMoney::KINDS[$kind])) {
            $errors[] = 'Choose what the payment is for.';
        }
        $method = InputValue::text($posted['method'] ?? null) ?? '';
        if (!isset(RentMoney::METHODS[$method])) {
            $errors[] = 'Choose how it was paid.';
        }
        $note = InputValue::text($posted['note'] ?? null);
        if ($note !== null && mb_strlen($note) > 255) {
            $errors[] = 'The note cannot be longer than 255 characters.';
        }
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $payment = (new RentPayment())->setTenant($tenant)->setPaidOn($paidOn)->setAmount(RentMoney::d($amount))
            ->setKind($kind)->setMethod($method)->setNote($note)->setCreatedAt(time())->setCreatedBy($actor->getId());

        return $this->persist($payment, true, 'payment', sprintf('%s %s %s on %s', $tenant->getName(), RentMoney::KINDS[$kind],
            InvoiceMoney::display($payment->getAmount(), RentMoney::CURRENCY), $paidOn->format('Y-m-d')), $actor);
    }

    public function deletePayment(RentPayment $payment, User $actor): void
    {
        $label = sprintf('%s %s %s on %s', $payment->getTenant()?->getName(), $payment->getKind(), $payment->getAmount(), $payment->getPaidOn()?->format('Y-m-d'));
        $this->em->remove($payment);
        $this->em->flush();
        $this->audit->record($actor, 'rent.payment_delete', $label);
    }

    // ── Mark paid / not paid (Year View, ADR-088) ──────────────────────────

    /**
     * Records a payment of what is still owed on one kind of one bill, dated $paidOn and tied to that bill.
     *
     * @return list<string> errors; empty when done
     */
    public function markPaid(RentBill $bill, string $kind, int $owed, mixed $paidOn, User $actor): array
    {
        $errors = [];
        $date = $this->date($errors, $paidOn, 'Paid on', true);
        if (!isset(RentMoney::KINDS[$kind])) {
            $errors[] = 'Choose rent or electricity.';
        }
        if ($errors === [] && $owed <= 0) {
            $errors[] = 'Nothing is owed on that.';
        }
        if ($errors !== []) {
            return $errors;
        }

        $payment = (new RentPayment())->setTenant($bill->getTenant())->setBill($bill)->setPaidOn($date)->setAmount(RentMoney::d($owed))
            ->setKind($kind)->setMethod('cash')->setNote(sprintf('Marked paid: %s %s', RentMoney::KINDS[$kind], $bill->getPeriod()?->format('M Y')))
            ->setCreatedAt(time())->setCreatedBy($actor->getId());
        $this->em->persist($payment);
        $this->em->flush();
        $this->audit->record($actor, 'rent.mark_paid', sprintf('%s %s %s %s on %s', $bill->getTenant()?->getName(), $bill->getPeriod()?->format('Y-m'),
            $kind, InvoiceMoney::display($payment->getAmount(), RentMoney::CURRENCY), $date->format('Y-m-d')));

        return [];
    }

    /** Undoes "Mark paid": removes the payments made that way for this kind of this bill. Returns how many. */
    public function markUnpaid(RentBill $bill, string $kind, User $actor): int
    {
        $removed = 0;
        foreach ($this->em->getRepository(RentPayment::class)->findBy(['bill' => $bill, 'kind' => $kind]) as $payment) {
            $this->em->remove($payment);
            ++$removed;
        }
        $this->em->flush();
        if ($removed > 0) {
            $this->audit->record($actor, 'rent.mark_unpaid', sprintf('%s %s %s', $bill->getTenant()?->getName(), $bill->getPeriod()?->format('Y-m'), $kind));
        }

        return $removed;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return WriteResult<object> */
    private function persist(object $record, bool $isNew, string $what, string $label, User $actor): WriteResult
    {
        if ($isNew) {
            $this->em->persist($record);
        }
        $this->em->flush();
        $this->audit->record($actor, sprintf('rent.%s_%s', $what, $isNew ? 'create' : 'update'), $label);

        return WriteResult::saved($record);
    }

    /** @param list<string> $errors */
    private function requireText(array &$errors, string $value, string $label, int $max): void
    {
        if ($value === '') {
            $errors[] = sprintf('%s is required.', $label);
        } elseif (mb_strlen($value) > $max) {
            $errors[] = sprintf('%s cannot be longer than %d characters.', $label, $max);
        }
    }

    /**
     * A non-negative two-decimal number in hundredths; blank is 0 unless required.
     *
     * @param list<string> $errors
     */
    private function amount(array &$errors, mixed $value, string $label, bool $required): int
    {
        $text = InputValue::text($value);
        if ($text === null) {
            if ($required) {
                $errors[] = sprintf('%s is required.', $label);
            }

            return 0;
        }
        $hundredths = InvoiceMoney::toHundredths($text);
        if ($hundredths === null || $hundredths < 0 || $hundredths >= self::MAX_AMOUNT) {
            $errors[] = sprintf('%s must be a number of 0 or more, with at most 2 decimals.', $label);

            return 0;
        }

        return $hundredths;
    }

    /** @param list<string> $errors */
    private function date(array &$errors, mixed $value, string $label, bool $required): ?\DateTimeImmutable
    {
        $text = InputValue::text($value);
        if ($text === null) {
            if ($required) {
                $errors[] = sprintf('%s is required.', $label);
            }

            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            $errors[] = sprintf('%s is not a valid date.', $label);

            return null;
        }

        return $date;
    }

    /** "2026-10" → 2026-10-01. @param list<string> $errors */
    private function month(array &$errors, mixed $value): ?\DateTimeImmutable
    {
        $text = InputValue::text($value) ?? '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text.'-01');
        if ($date === false || $date->format('Y-m') !== $text) {
            $errors[] = 'Choose the month the bill is for.';

            return null;
        }

        return $date;
    }

    private function multiline(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim(str_replace("\r\n", "\n", (string) $value)) : '';

        return $text !== '' ? $text : null;
    }
}
