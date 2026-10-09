<?php

declare(strict_types=1);

namespace App\Service\Rent;

use App\Entity\Rent\RentTenant;
use App\Repository\Rent\RentBillRepository;
use App\Repository\Rent\RentPaymentRepository;
use App\Repository\Rent\RentTenantRepository;

/**
 * The rent overview (ADR-085): one month across every tenancy (billed, electricity units, collected, still owed) and
 * the dues list (who owes what now, the longest-waiting first). Built from each tenancy's ledger, so the figures
 * always agree with the tenant pages.
 */
final class RentSummary
{
    public function __construct(
        private readonly RentTenantRepository $tenants,
        private readonly RentBillRepository $bills,
        private readonly RentPaymentRepository $payments,
        private readonly RentLedger $ledger,
    ) {
    }

    /**
     * A $tenantId narrows every figure (rows, totals, dues) to that one tenancy (ADR-123).
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>, dues: list<array<string, mixed>>, duesTotal: array<string, int>}
     */
    public function forMonth(\DateTimeImmutable $month, ?int $tenantId = null): array
    {
        $key = $month->format('Y-m');
        $billsByTenant = [];
        foreach ($this->bills->findAllWithTenants() as $bill) {
            $billsByTenant[(int) $bill->getTenant()?->getId()][] = $bill;
        }
        $paymentsByTenant = [];
        foreach ($this->payments->findAllWithTenants() as $payment) {
            $paymentsByTenant[(int) $payment->getTenant()?->getId()][] = $payment;
        }

        $rows = [];
        $dues = [];
        $zero = ['rentDue' => 0, 'units' => 0, 'electricityDue' => 0, 'otherDue' => 0, 'dueTotal' => 0,
            'rentPaid' => 0, 'electricityPaid' => 0, 'otherPaid' => 0, 'paidTotal' => 0, 'owedAtMonthEnd' => 0];
        $totals = $zero;
        $duesTotal = ['rent' => 0, 'electricity' => 0, 'other' => 0, 'total' => 0];

        foreach ($this->tenants->findAllOrdered() as $tenant) {
            $id = (int) $tenant->getId();
            if ($tenantId !== null && $id !== $tenantId) {
                continue;
            }
            $ledger = $this->ledger->build($tenant, $billsByTenant[$id] ?? [], $paymentsByTenant[$id] ?? []);

            $row = null;
            $owed = 0;
            foreach ($ledger['rows'] as $month) {
                if ($month['month'] <= $key) {
                    $owed = $month['balance'];
                }
                if ($month['month'] === $key) {
                    $row = $month;
                }
            }
            if ($row !== null || ($tenant->isActive() && $this->isLetIn($tenant, $month))) {
                $line = [
                    'tenant'          => $tenant,
                    'bill'            => $row['bill'] ?? null,
                    'rentDue'         => $row['due']['rent'] ?? 0,
                    'units'           => $row['units'] ?? 0,
                    'electricityDue'  => $row['due']['electricity'] ?? 0,
                    'otherDue'        => $row['due']['other'] ?? 0,
                    'dueTotal'        => $row['dueTotal'] ?? 0,
                    'rentPaid'        => $row['paid']['rent'] ?? 0,
                    'electricityPaid' => $row['paid']['electricity'] ?? 0,
                    'otherPaid'       => $row['paid']['other'] ?? 0,
                    'paidTotal'       => $row['paidTotal'] ?? 0,
                    'owedAtMonthEnd'  => $owed,
                ];
                foreach ($zero as $field => $unused) {
                    $totals[$field] += $line[$field];
                }
                $rows[] = $line;
            }

            if ($ledger['totals']['remainingTotal'] > 0) {
                $dues[] = ['tenant' => $tenant, 'totals' => $ledger['totals']];
                foreach (['rent', 'electricity', 'other'] as $kind) {
                    $duesTotal[$kind] += $ledger['totals']['remaining'][$kind];
                }
                $duesTotal['total'] += $ledger['totals']['remainingTotal'];
            }
        }

        // Longest waiting first; a tenancy with no unpaid bill but a negative kind balance sorts last.
        usort($dues, static fn (array $a, array $b) => [$a['totals']['oldestUnpaid']?->format('Y-m') ?? '9999', -$a['totals']['remainingTotal']]
            <=> [$b['totals']['oldestUnpaid']?->format('Y-m') ?? '9999', -$b['totals']['remainingTotal']]);

        return ['rows' => $rows, 'totals' => $totals, 'dues' => $dues, 'duesTotal' => $duesTotal];
    }

    /** Whether the tenancy covers any day of that month. */
    private function isLetIn(RentTenant $tenant, \DateTimeImmutable $month): bool
    {
        $start = $tenant->getStartDate();
        $end = $tenant->getEndDate();
        $monthEnd = $month->modify('last day of this month');

        return ($start === null || $start <= $monthEnd) && ($end === null || $end >= $month);
    }
}
