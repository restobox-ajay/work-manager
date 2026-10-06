<?php

declare(strict_types=1);

namespace App\Service\Rent;

use App\Entity\Rent\RentBill;
use App\Entity\Rent\RentPayment;
use App\Entity\Rent\RentTenant;
use App\Repository\Rent\RentBillRepository;
use App\Repository\Rent\RentPaymentRepository;

/**
 * A tenancy's account (ADR-085): month by month what was billed (rent, electricity units and amount, other) and what
 * was paid (by kind), with the running balance; and the totals — billed, paid and remaining for rent, electricity
 * and other, electricity units billed / paid / remaining, and the oldest month not yet fully paid.
 *
 * All sums are in hundredths (RentMoney). "Units paid" is the electricity paid ÷ the rate of the latest bill.
 */
final class RentLedger
{
    public function __construct(
        private readonly RentBillRepository $bills,
        private readonly RentPaymentRepository $payments,
    ) {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function forTenant(RentTenant $tenant): array
    {
        return $this->build($tenant, $this->bills->findForTenant($tenant), $this->payments->findForTenant($tenant));
    }

    /**
     * @param RentBill[]    $bills    the tenancy's bills, oldest first
     * @param RentPayment[] $payments the tenancy's payments, oldest first
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(RentTenant $tenant, array $bills, array $payments): array
    {
        $months = [];
        $blank = static fn (string $key) => ['month' => $key, 'bill' => null, 'payments' => [],
            'due' => ['rent' => 0, 'electricity' => 0, 'other' => 0], 'units' => 0,
            'paid' => ['rent' => 0, 'electricity' => 0, 'other' => 0]];
        foreach ($bills as $bill) {
            $key = $bill->getPeriod()?->format('Y-m') ?? '';
            $months[$key] ??= $blank($key);
            $months[$key]['bill'] = $bill;
            $months[$key]['due'] = [
                'rent'        => RentMoney::h($bill->getRentAmount()),
                'electricity' => RentMoney::h($bill->getElectricityAmount()),
                'other'       => RentMoney::h($bill->getOtherAmount()),
            ];
            $months[$key]['units'] = RentMoney::h($bill->getUnits());
        }
        foreach ($payments as $payment) {
            $key = $payment->getPaidOn()?->format('Y-m') ?? '';
            $months[$key] ??= $blank($key);
            $months[$key]['payments'][] = $payment;
            $kind = isset(RentMoney::KINDS[$payment->getKind()]) ? $payment->getKind() : 'other';
            $months[$key]['paid'][$kind] += RentMoney::h($payment->getAmount());
        }
        ksort($months);

        $totals = ['due' => ['rent' => 0, 'electricity' => 0, 'other' => 0], 'paid' => ['rent' => 0, 'electricity' => 0, 'other' => 0], 'units' => 0];
        $rows = [];
        foreach ($months as $row) {
            foreach (['rent', 'electricity', 'other'] as $kind) {
                $totals['due'][$kind] += $row['due'][$kind];
                $totals['paid'][$kind] += $row['paid'][$kind];
            }
            $totals['units'] += $row['units'];
            $row['dueTotal'] = array_sum($row['due']);
            $row['paidTotal'] = array_sum($row['paid']);
            $row['balance'] = array_sum($totals['due']) - array_sum($totals['paid']);
            $rows[] = $row;
        }

        $remaining = [];
        foreach (['rent', 'electricity', 'other'] as $kind) {
            $remaining[$kind] = $totals['due'][$kind] - $totals['paid'][$kind];
        }
        $lastBill = $bills !== [] ? $bills[array_key_last($bills)] : null;
        $rate = RentMoney::h($lastBill?->getRate() ?? $tenant->getProperty()?->getElectricityRate());
        $unitsPaid = min($totals['units'], RentMoney::divide($totals['paid']['electricity'], $rate));

        $totals += [
            'remaining'      => $remaining,
            'dueTotal'       => array_sum($totals['due']),
            'paidTotal'      => array_sum($totals['paid']),
            'remainingTotal' => array_sum($remaining),
            'unitsPaid'      => $unitsPaid,
            'unitsRemaining' => max(0, $totals['units'] - $unitsPaid),
            'rate'           => $rate,
            'oldestUnpaid'   => $this->oldestUnpaid($bills, array_sum($totals['paid'])),
            'lastBill'       => $lastBill,
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Which bills are paid (ADR-087): each kind of payment settles that kind's charges oldest bill first (rent payments
     * pay rent, electricity payments pay electricity, other pays other); money left over moves on to the next bill.
     * For each bill and kind: due, paid, status (paid / part / unpaid / none when nothing was due) and paidOn — the date
     * of the payment that completed it. A bill is paid when all its kinds are.
     *
     * @param RentBill[]    $bills    oldest first
     * @param RentPayment[] $payments oldest first
     *
     * @return array<int, array{kinds: array<string, array{due: int, paid: int, status: string, paidOn: ?\DateTimeImmutable}>, status: string, paidOn: ?\DateTimeImmutable, due: int, paid: int}>
     *         keyed by bill id
     */
    public function allocate(array $bills, array $payments): array
    {
        // A payment marked for one bill ("Mark paid", ADR-088) pays that bill first; the rest settle oldest first.
        $queues = ['rent' => [], 'electricity' => [], 'other' => []];
        $linked = [];
        foreach ($payments as $payment) {
            $kind = isset($queues[$payment->getKind()]) ? $payment->getKind() : 'other';
            $entry = ['left' => RentMoney::h($payment->getAmount()), 'date' => $payment->getPaidOn()];
            if ($payment->getBill() !== null) {
                $linked[(int) $payment->getBill()->getId()][$kind][] = $entry;
            } else {
                $queues[$kind][] = $entry;
            }
        }

        $result = [];
        foreach ($bills as $bill) {
            $due = [
                'rent'        => RentMoney::h($bill->getRentAmount()),
                'electricity' => RentMoney::h($bill->getElectricityAmount()),
                'other'       => RentMoney::h($bill->getOtherAmount()),
            ];
            $kinds = [];
            foreach ($due as $kind => $amount) {
                $paid = 0;
                $paidOn = null;
                foreach ($linked[(int) $bill->getId()][$kind] ?? [] as $entry) {
                    $take = min($amount - $paid, $entry['left']);
                    $paid += $take;
                    $paidOn = $entry['date'];
                    if ($entry['left'] > $take) {
                        // More than this bill needed: the rest joins the general pool for later bills.
                        $queues[$kind][] = ['left' => $entry['left'] - $take, 'date' => $entry['date']];
                    }
                }
                while ($paid < $amount && $queues[$kind] !== []) {
                    $take = min($amount - $paid, $queues[$kind][0]['left']);
                    $paid += $take;
                    $queues[$kind][0]['left'] -= $take;
                    $paidOn = $queues[$kind][0]['date'];
                    if ($queues[$kind][0]['left'] === 0) {
                        array_shift($queues[$kind]);
                    }
                }
                $kinds[$kind] = [
                    'marked' => ($linked[(int) $bill->getId()][$kind] ?? []) !== [],
                    'due'    => $amount,
                    'paid'   => $paid,
                    'status' => $amount === 0 ? 'none' : ($paid >= $amount ? 'paid' : ($paid > 0 ? 'part' : 'unpaid')),
                    'paidOn' => $amount > 0 && $paid >= $amount ? $paidOn : null,
                ];
            }
            $open = array_filter($kinds, static fn (array $k) => $k['status'] !== 'none');
            $statuses = array_unique(array_column($open, 'status'));
            $paidDates = array_filter(array_column($open, 'paidOn'));
            $result[(int) $bill->getId()] = [
                'kinds'  => $kinds,
                'status' => $open === [] || $statuses === ['paid'] ? 'paid' : (in_array('paid', $statuses, true) || in_array('part', $statuses, true) ? 'part' : 'unpaid'),
                'paidOn' => $open !== [] && $statuses === ['paid'] && $paidDates !== [] ? max($paidDates) : null,
                'due'    => array_sum($due),
                'paid'   => array_sum(array_column($kinds, 'paid')),
            ];
        }

        return $result;
    }

    /**
     * One tenancy's year (ADR-087): the twelve months, each with its bill (if any), how it was paid, and whether the
     * tenancy covered that month at all.
     *
     * @return array{months: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function year(RentTenant $tenant, int $year): array
    {
        $bills = $this->bills->findForTenant($tenant);
        return $this->yearFrom($tenant, $year, $bills, $this->allocate($bills, $this->payments->findForTenant($tenant)));
    }

    /**
     * @param RentBill[]                        $bills
     * @param array<int, array<string, mixed>>  $allocation from allocate()
     *
     * @return array{months: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function yearFrom(RentTenant $tenant, int $year, array $bills, array $allocation): array
    {
        $byMonth = [];
        foreach ($bills as $bill) {
            $byMonth[$bill->getPeriod()?->format('Y-m') ?? ''] = $bill;
        }
        $today = new \DateTimeImmutable('today');
        $months = [];
        $totals = ['rent' => 0, 'electricity' => 0, 'other' => 0, 'due' => 0, 'paid' => 0, 'units' => 0, 'billed' => 0, 'paidMonths' => 0];
        for ($m = 1; $m <= 12; ++$m) {
            $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
            $bill = $byMonth[$start->format('Y-m')] ?? null;
            $let = ($tenant->getStartDate() === null || $tenant->getStartDate() <= $start->modify('last day of this month'))
                && ($tenant->getEndDate() === null || $tenant->getEndDate() >= $start);
            $paid = $bill !== null ? $allocation[(int) $bill->getId()] ?? null : null;
            $months[] = [
                'month'  => $start,
                'bill'   => $bill,
                'paid'   => $paid,
                'let'    => $let,
                'future' => $start > $today,
                // paid / part / unpaid for a bill; otherwise not-billed (let, past) or none
                'status' => $paid['status'] ?? ($let && $start <= $today ? 'not-billed' : 'none'),
            ];
            if ($bill !== null) {
                $totals['rent'] += RentMoney::h($bill->getRentAmount());
                $totals['electricity'] += RentMoney::h($bill->getElectricityAmount());
                $totals['other'] += RentMoney::h($bill->getOtherAmount());
                $totals['units'] += RentMoney::h($bill->getUnits());
                $totals['due'] += $paid['due'] ?? 0;
                $totals['paid'] += $paid['paid'] ?? 0;
                ++$totals['billed'];
                $totals['paidMonths'] += ($paid['status'] ?? '') === 'paid' ? 1 : 0;
            }
        }
        $totals['owed'] = $totals['due'] - $totals['paid'];

        return ['months' => $months, 'totals' => $totals];
    }

    /**
     * The first month whose bill is not covered by everything paid so far (payments settle the oldest bills first).
     *
     * @param RentBill[] $bills
     */
    private function oldestUnpaid(array $bills, int $paid): ?\DateTimeImmutable
    {
        $billed = 0;
        foreach ($bills as $bill) {
            $billed += RentMoney::h($bill->getTotal());
            if ($billed > $paid) {
                return $bill->getPeriod();
            }
        }

        return null;
    }
}
