<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Rent\RentBill;
use App\Entity\Rent\RentPayment;
use App\Entity\Rent\RentProperty;
use App\Entity\Rent\RentTenant;
use App\Repository\Rent\RentBillRepository;
use App\Repository\Rent\RentPaymentRepository;
use App\Repository\Rent\RentPropertyRepository;
use App\Repository\Rent\RentTenantRepository;
use App\Service\Rent\RentLedger;
use App\Service\Rent\RentMoney;
use App\Service\Rent\RentService;
use App\Service\Rent\RentSummary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * House rent (ADR-085), admins only: the summary (a month across all tenancies + who owes what), properties,
 * tenants with their ledger, monthly bills (one at a time or a whole month at once) and payments.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/rent')]
final class RentController extends AbstractWorkController
{
    public function __construct(
        private readonly RentService $rent,
        private readonly RentPropertyRepository $properties,
        private readonly RentTenantRepository $tenants,
        private readonly RentBillRepository $bills,
    ) {
    }

    #[Route('', name: 'app_rent_summary', methods: ['GET'])]
    public function summary(Request $request, RentSummary $summary): Response
    {
        $month = $this->monthFrom($request->query->getString('month'));

        return $this->render('rent/summary.html.twig', [
            'month'   => $month,
            'summary' => $summary->forMonth($month),
        ]);
    }

    // ── Properties ───────────────────────────────────────────────────────────

    #[Route('/properties', name: 'app_rent_properties', methods: ['GET'])]
    public function properties(): Response
    {
        return $this->render('rent/properties.html.twig', ['properties' => $this->properties->findAllOrdered()]);
    }

    #[Route('/properties/new', name: 'app_rent_property_new', methods: ['GET', 'POST'])]
    public function newProperty(Request $request): Response
    {
        return $this->propertyForm(null, $request);
    }

    #[Route('/properties/{id}/edit', name: 'app_rent_property_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editProperty(RentProperty $property, Request $request): Response
    {
        return $this->propertyForm($property, $request);
    }

    // ── Tenants ──────────────────────────────────────────────────────────────

    #[Route('/tenants', name: 'app_rent_tenants', methods: ['GET'])]
    public function tenants(Request $request, RentLedger $ledger): Response
    {
        $show = $request->query->getString('show', 'active');
        $list = $this->tenants->findAllOrdered($show === 'all' ? null : $show !== 'past');
        $balances = [];
        foreach ($list as $tenant) {
            $balances[(int) $tenant->getId()] = $ledger->forTenant($tenant)['totals'];
        }

        return $this->render('rent/tenants.html.twig', ['tenants' => $list, 'balances' => $balances, 'show' => $show]);
    }

    #[Route('/tenants/new', name: 'app_rent_tenant_new', methods: ['GET', 'POST'])]
    public function newTenant(Request $request): Response
    {
        return $this->tenantForm(null, $request);
    }

    #[Route('/tenants/{id}', name: 'app_rent_tenant', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function tenant(RentTenant $tenant, RentLedger $ledger): Response
    {
        return $this->render('rent/tenant.html.twig', ['tenant' => $tenant, 'ledger' => $ledger->forTenant($tenant),
            'kinds' => RentMoney::KINDS, 'methods' => RentMoney::METHODS]);
    }

    /** Kept for old links: a tenant's year is the Year View with that tenant chosen. */
    #[Route('/tenants/{id}/year', name: 'app_rent_tenant_year', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function tenantYear(RentTenant $tenant, Request $request): Response
    {
        return $this->redirectToRoute('app_rent_year', ['tenant' => $tenant->getId(), 'year' => $this->yearFrom($request)]);
    }

    /**
     * Year View (ADR-087): choose a tenant and a year; one row per month, January to December, with that month's rent,
     * electricity and other charges, whether each is paid, and when.
     */
    #[Route('/year', name: 'app_rent_year', methods: ['GET'])]
    public function year(Request $request, RentLedger $ledger): Response
    {
        $year = $this->yearFrom($request);
        $tenants = $this->tenants->findAllOrdered();
        $chosen = null;
        foreach ($tenants as $tenant) {
            if ($tenant->getId() === $request->query->getInt('tenant')) {
                $chosen = $tenant;
            }
        }
        // No (or an unknown) tenant chosen: the first current tenant.
        $chosen ??= $tenants[0] ?? null;

        return $this->render('rent/year.html.twig', [
            'year'    => $year,
            'years'   => $this->yearChoices($chosen?->getStartDate()),
            'tenants' => $tenants,
            'tenant'  => $chosen,
            'view'    => $chosen !== null ? $ledger->year($chosen, $year) : null,
        ]);
    }

    #[Route('/tenants/{id}/edit', name: 'app_rent_tenant_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editTenant(RentTenant $tenant, Request $request): Response
    {
        return $this->tenantForm($tenant, $request);
    }

    // ── Bills ────────────────────────────────────────────────────────────────

    #[Route('/tenants/{id}/bills/new', name: 'app_rent_bill_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function newBill(RentTenant $tenant, Request $request): Response
    {
        return $this->billForm($tenant, null, $request);
    }

    #[Route('/bills/{id}/edit', name: 'app_rent_bill_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editBill(RentBill $bill, Request $request): Response
    {
        return $this->billForm($bill->getTenant(), $bill, $request);
    }

    #[Route('/bills/{id}/delete', name: 'app_rent_bill_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteBill(RentBill $bill, Request $request): Response
    {
        $this->assertCsrf($request, 'rent_bill_delete_'.$bill->getId());
        $tenantId = $bill->getTenant()?->getId();
        $this->rent->deleteBill($bill, $this->viewer());
        $this->addFlash('success', 'Bill deleted.');

        return $this->redirectToRoute('app_rent_tenant', ['id' => $tenantId]);
    }

    /** A whole month at once: every tenancy let that month without a bill yet, one row each. */
    #[Route('/bills', name: 'app_rent_month_bills', methods: ['GET', 'POST'])]
    public function monthBills(Request $request): Response
    {
        $month = $this->monthFrom($request->query->getString('month') ?: $request->request->getString('month'));
        $errors = [];
        $posted = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'rent_month_bills');
            $posted = (array) ($request->request->all()['rows'] ?? []);
            $created = 0;
            foreach ($posted as $tenantId => $row) {
                $tenant = is_array($row) && trim((string) ($row['meterCurrent'] ?? '')) !== '' ? $this->tenants->find((int) $tenantId) : null;
                if ($tenant === null) {
                    continue; // rows left without a current reading are skipped
                }
                $result = $this->rent->saveBill($tenant, null, ['period' => $month->format('Y-m')] + $row, $this->viewer());
                if ($result->isSaved()) {
                    ++$created;
                    unset($posted[$tenantId]);
                } else {
                    foreach ($result->errors as $message) {
                        $errors[] = $tenant->getName().': '.$message;
                    }
                }
            }
            if ($created > 0) {
                $this->addFlash('success', sprintf('%d bill(s) created for %s.', $created, $month->format('F Y')));
            }
            if ($errors === []) {
                return $this->redirectToRoute('app_rent_month_bills', ['month' => $month->format('Y-m')]);
            }
        }

        $rows = [];
        foreach ($this->tenants->findAllOrdered(true) as $tenant) {
            $values = $this->rent->nextBillValues($tenant, $month);
            $rows[] = ['tenant' => $tenant, 'bill' => $this->bills->findOneForPeriod($tenant, $month), 'values' => ($posted[(int) $tenant->getId()] ?? []) + $values];
        }

        return $this->render('rent/month_bills.html.twig', ['month' => $month, 'rows' => $rows, 'errors' => $errors],
            new Response(status: $errors === [] ? 200 : 422));
    }

    /** Year View: pay what is still owed on the rent (or electricity) of one month, on a chosen date (ADR-088). */
    #[Route('/bills/{id}/mark-paid', name: 'app_rent_bill_mark_paid', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markPaid(RentBill $bill, Request $request, RentLedger $ledger, RentPaymentRepository $payments): Response
    {
        $this->assertCsrf($request, 'rent_mark_'.$bill->getId());
        $tenant = $bill->getTenant();
        $kind = $request->request->getString('kind');
        $allocation = $ledger->allocate($this->bills->findForTenant($tenant), $payments->findForTenant($tenant));
        $state = $allocation[(int) $bill->getId()]['kinds'][$kind] ?? null;
        $errors = $this->rent->markPaid($bill, $kind, $state !== null ? $state['due'] - $state['paid'] : 0, $request->request->get('paidOn'), $this->viewer());
        $errors === []
            ? $this->addFlash('success', sprintf('%s for %s marked paid.', RentMoney::KINDS[$kind], $bill->getPeriod()?->format('F Y')))
            : $this->addFlash('error', implode(' ', $errors));

        return $this->redirectToRoute('app_rent_year', ['tenant' => $tenant?->getId(), 'year' => $bill->getPeriod()?->format('Y')]);
    }

    /** Year View: undo "Mark paid" for one month's rent (or electricity). */
    #[Route('/bills/{id}/mark-unpaid', name: 'app_rent_bill_mark_unpaid', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markUnpaid(RentBill $bill, Request $request): Response
    {
        $this->assertCsrf($request, 'rent_mark_'.$bill->getId());
        $kind = $request->request->getString('kind');
        $removed = isset(RentMoney::KINDS[$kind]) ? $this->rent->markUnpaid($bill, $kind, $this->viewer()) : 0;
        $removed > 0
            ? $this->addFlash('success', sprintf('%s for %s marked not paid.', RentMoney::KINDS[$kind], $bill->getPeriod()?->format('F Y')))
            : $this->addFlash('error', 'That month was paid by a recorded payment: delete or change the payment on the tenant page instead.');

        return $this->redirectToRoute('app_rent_year', ['tenant' => $bill->getTenant()?->getId(), 'year' => $bill->getPeriod()?->format('Y')]);
    }

    // ── Payments ─────────────────────────────────────────────────────────────

    #[Route('/tenants/{id}/payments/new', name: 'app_rent_payment_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function newPayment(RentTenant $tenant, Request $request, RentLedger $ledger): Response
    {
        $remaining = $ledger->forTenant($tenant)['totals']['remaining'];
        $kind = $request->query->getString('kind') ?: ($remaining['rent'] > 0 ? 'rent' : ($remaining['electricity'] > 0 ? 'electricity' : 'rent'));
        $values = ['paidOn' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'kind' => $kind, 'method' => 'cash',
            'amount' => isset($remaining[$kind]) && $remaining[$kind] > 0 ? RentMoney::d($remaining[$kind]) : '', 'note' => ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'rent_payment');
            $values = $request->request->all();
            $result = $this->rent->savePayment($tenant, $values, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Payment recorded.');

                return $this->redirectToRoute('app_rent_tenant', ['id' => $tenant->getId()]);
            }
            $errors = $result->errors;
        }

        return $this->render('rent/payment_form.html.twig', ['tenant' => $tenant, 'values' => $values, 'errors' => $errors,
            'remaining' => $remaining, 'kinds' => RentMoney::KINDS, 'methods' => RentMoney::METHODS],
            new Response(status: $errors === [] ? 200 : 422));
    }

    #[Route('/payments/{id}/delete', name: 'app_rent_payment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deletePayment(RentPayment $payment, Request $request): Response
    {
        $this->assertCsrf($request, 'rent_payment_delete_'.$payment->getId());
        $tenantId = $payment->getTenant()?->getId();
        $this->rent->deletePayment($payment, $this->viewer());
        $this->addFlash('success', 'Payment deleted.');

        return $this->redirectToRoute('app_rent_tenant', ['id' => $tenantId]);
    }

    // ── Forms ────────────────────────────────────────────────────────────────

    private function propertyForm(?RentProperty $property, Request $request): Response
    {
        $values = $property !== null ? [
            'name' => $property->getName(), 'address' => $property->getAddress(), 'electricityRate' => $property->getElectricityRate(),
            'defaultRent' => $property->getDefaultRent(), 'isActive' => $property->isActive(), 'notes' => $property->getNotes(),
        ] : ['isActive' => true, 'electricityRate' => '', 'defaultRent' => ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'rent_property');
            $values = $request->request->all();
            $result = $this->rent->saveProperty($property, $values, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Property saved.');

                return $this->redirectToRoute('app_rent_properties');
            }
            $errors = $result->errors;
        }

        return $this->render('rent/property_form.html.twig', ['property' => $property, 'values' => $values, 'errors' => $errors],
            new Response(status: $errors === [] ? 200 : 422));
    }

    private function tenantForm(?RentTenant $tenant, Request $request): Response
    {
        $values = $tenant !== null ? [
            'propertyId' => $tenant->getProperty()?->getId(), 'name' => $tenant->getName(), 'phone' => $tenant->getPhone(),
            'email' => $tenant->getEmail(), 'monthlyRent' => $tenant->getMonthlyRent(), 'deposit' => $tenant->getDeposit(),
            'openingMeter' => $tenant->getOpeningMeter(), 'startDate' => $tenant->getStartDate()?->format('Y-m-d'),
            'endDate' => $tenant->getEndDate()?->format('Y-m-d'), 'isActive' => $tenant->isActive(), 'notes' => $tenant->getNotes(),
        ] : ['isActive' => true, 'propertyId' => $request->query->getInt('propertyId') ?: null,
            'startDate' => (new \DateTimeImmutable('today'))->format('Y-m-d')];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'rent_tenant');
            $values = $request->request->all();
            $result = $this->rent->saveTenant($tenant, $values, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Tenant saved.');

                return $this->redirectToRoute('app_rent_tenant', ['id' => $result->record->getId()]);
            }
            $errors = $result->errors;
        }

        return $this->render('rent/tenant_form.html.twig', ['tenant' => $tenant, 'values' => $values, 'errors' => $errors,
            'properties' => $this->properties->findAllOrdered()], new Response(status: $errors === [] ? 200 : 422));
    }

    private function billForm(?RentTenant $tenant, ?RentBill $bill, Request $request): Response
    {
        if ($tenant === null) {
            throw $this->createNotFoundException();
        }
        // ?month=YYYY-MM (from the year view) starts a bill for that month.
        $month = $bill === null ? \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('month').'-01') : false;
        $values = $bill !== null ? $this->rent->billValues($bill) : $this->rent->nextBillValues($tenant, $month instanceof \DateTimeImmutable ? $month : null);
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'rent_bill');
            $values = $request->request->all();
            $result = $this->rent->saveBill($tenant, $bill, $values, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Bill saved.');

                return $this->redirectToRoute('app_rent_tenant', ['id' => $tenant->getId()]);
            }
            $errors = $result->errors;
        }

        return $this->render('rent/bill_form.html.twig', ['tenant' => $tenant, 'bill' => $bill, 'values' => $values, 'errors' => $errors],
            new Response(status: $errors === [] ? 200 : 422));
    }

    private function yearFrom(Request $request): int
    {
        $year = $request->query->getInt('year');

        return $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');
    }

    /** @return int[] the years offered: from the move-in year (or 3 years back) to next year */
    private function yearChoices(?\DateTimeImmutable $since): array
    {
        $now = (int) date('Y');

        return range($now + 1, min($now - 3, (int) ($since?->format('Y') ?? $now - 3)));
    }

    /** "2026-10" → 2026-10-01; anything else → this month. */
    private function monthFrom(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value.'-01');

        return $date !== false && $date->format('Y-m') === $value ? $date : new \DateTimeImmutable('first day of this month midnight');
    }
}
