<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Invoice;
use App\Enum\InvoiceKind;
use App\Enum\InvoiceStatus;
use App\Repository\ClientRepository;
use App\Repository\InvoiceLogRepository;
use App\Repository\Settings\BillingProfileRepository;
use App\Service\Invoice\InvoiceHistory;
use App\Service\Invoice\InvoiceInput;
use App\Service\Invoice\InvoiceMailer;
use App\Service\Invoice\InvoicePdfRenderer;
use App\Service\Invoice\InvoicePdfUnavailable;
use App\Service\Invoice\InvoiceService;
use App\Service\Pagination\Paginated;
use App\Service\Settings\TaxRateSettings;
use App\Service\Task\TaskLookups;
use App\Service\Validation\InputValue;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Invoices (ADR-077), admins only. Two kinds, one menu entry each:
 * - Independent Invoice: lines typed by hand.
 * - Approved Tasks Invoice: pick a client and currency, tick approved tasks not yet invoiced, then the same form
 *   with one line per task.
 * Every invoice can be viewed, edited (until cancelled), downloaded as PDF, printed, emailed and cancelled; each
 * action is in the invoice's own history and the audit log.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/invoice')]
final class InvoiceController extends AbstractWorkController
{
    private const THEN_EMAIL = 'email';

    /** How a filtered invoice list can be grouped. */
    private const GROUPINGS = ['month', 'year'];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly BillingProfileRepository $profiles,
        private readonly ClientRepository $clients,
        private readonly TaskLookups $lookups,
        private readonly TaxRateSettings $taxRates,
    ) {
    }

    /** Every invoice, both kinds, with buttons to create either kind. */
    #[Route('', name: 'app_invoice_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->list(null, $request);
    }

    #[Route('/independent', name: 'app_invoice_independent', methods: ['GET'])]
    public function independent(Request $request): Response
    {
        return $this->list(InvoiceKind::Independent, $request);
    }

    #[Route('/tasks', name: 'app_invoice_tasks', methods: ['GET'])]
    public function tasks(Request $request): Response
    {
        return $this->list(InvoiceKind::Tasks, $request);
    }

    #[Route('/independent/new', name: 'app_invoice_new_independent', methods: ['GET', 'POST'])]
    public function newIndependent(Request $request): Response
    {
        return $this->form(InvoiceKind::Independent, null, $request, $this->invoices->blankInput());
    }

    /**
     * GET without taskIds: the picker (client, currency, approved tasks). GET with taskIds[]: the invoice form,
     * one line per task. POST: save.
     */
    #[Route('/tasks/new', name: 'app_invoice_new_tasks', methods: ['GET', 'POST'])]
    public function newForTasks(Request $request): Response
    {
        // ?then=email (from Request Payment, ADR-103): after saving, go straight on to emailing the invoice.
        $thenEmail = ($request->isMethod('POST') ? $request->request->getString('then') : $request->query->getString('then')) === self::THEN_EMAIL;
        if ($request->isMethod('POST')) {
            return $this->form(InvoiceKind::Tasks, null, $request, new InvoiceInput(), $thenEmail);
        }

        $query = $request->query;
        $clientId = InputValue::int($query->get('clientId'));
        $currency = strtoupper(InputValue::text($query->get('currency')) ?? 'USD');
        $client = $clientId !== null ? $this->clients->find($clientId) : null;
        $taskIds = InputValue::ints($query->all()['taskIds'] ?? []);

        if ($client !== null && $taskIds !== []) {
            $input = $this->invoices->inputForTasks($client, $currency, $taskIds);
            if ($input->lines !== []) {
                return $this->form(InvoiceKind::Tasks, null, $request, $input, $thenEmail);
            }
            $this->addFlash('error', 'Those tasks can no longer be invoiced: pick again.');
        }

        return $this->render('invoice/pick_tasks.html.twig', [
            'clients'    => $this->clients->findSelectable(null),
            'currencies' => $this->lookups->currencies(),
            'client'     => $client,
            'currency'   => $currency,
            'tasks'      => $client !== null ? $this->invoices->invoiceableTasks((int) $client->getId(), $currency) : [],
            'statuses'   => $this->lookups->statuses(),
        ]);
    }

    #[Route('/{id}', name: 'app_invoice_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(Invoice $invoice, Request $request, InvoiceLogRepository $logs, InvoicePdfRenderer $pdf): Response
    {
        $this->markKind($request, $invoice);
        return $this->render('invoice/view.html.twig', [
            'invoice'      => $invoice,
            'logs'         => $logs->findForInvoice((int) $invoice->getId()),
            'people'       => $this->lookups->people(),
            'actions'      => InvoiceHistory::LABELS,
            'pdfAvailable' => $pdf->isAvailable(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_invoice_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Invoice $invoice, Request $request): Response
    {
        $this->markKind($request, $invoice);
        if ($invoice->isCancelled()) {
            $this->addFlash('error', 'A cancelled invoice cannot be changed.');

            return $this->redirectToRoute('app_invoice_view', ['id' => $invoice->getId()]);
        }

        return $this->form($invoice->getKind(), $invoice, $request, InvoiceInput::fromInvoice($invoice));
    }

    #[Route('/{id}/pdf', name: 'app_invoice_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(Invoice $invoice, InvoicePdfRenderer $pdf, InvoiceHistory $history): Response
    {
        try {
            $content = $pdf->render($invoice);
        } catch (InvoicePdfUnavailable $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_invoice_view', ['id' => $invoice->getId()]);
        }
        $history->record($invoice, InvoiceHistory::PDF, null, $this->viewer());

        return new Response($content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $pdf->fileName($invoice)),
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    /** The invoice on its own page, for the browser's Print / Save as PDF. */
    #[Route('/{id}/print', name: 'app_invoice_print', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function print(Invoice $invoice, Request $request): Response
    {
        return $this->render('invoice/document.html.twig', ['invoice' => $invoice, 'forPdf' => false, 'embed' => $request->query->getBoolean('embed')]);
    }

    #[Route('/{id}/email', name: 'app_invoice_email', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function email(Invoice $invoice, Request $request, InvoiceMailer $mailer): Response
    {
        $this->markKind($request, $invoice);
        $values = $mailer->draft($invoice);
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'invoice_email_'.$invoice->getId());
            foreach (['to', 'cc', 'subject'] as $field) {
                $values[$field] = InputValue::text($request->request->get($field)) ?? '';
            }
            $values['message'] = trim(str_replace("\r\n", "\n", $request->request->getString('message')));
            $errors = $mailer->send($invoice, $values, $this->viewer());
            if ($errors === []) {
                $this->addFlash('success', sprintf('Invoice %s emailed.', $invoice->getNumber()));

                return $this->redirectToRoute('app_invoice_view', ['id' => $invoice->getId()]);
            }
        }

        return $this->render('invoice/email.html.twig', ['invoice' => $invoice, 'values' => $values, 'errors' => $errors],
            new Response(status: $errors === [] ? 200 : 422));
    }

    #[Route('/{id}/cancel', name: 'app_invoice_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(Invoice $invoice, Request $request): Response
    {
        $this->assertCsrf($request, 'invoice_cancel_'.$invoice->getId());
        $this->invoices->cancel($invoice, InputValue::text($request->request->get('reason')), $this->viewer());
        $this->addFlash('success', sprintf('Invoice %s cancelled. Its tasks can be invoiced again.', $invoice->getNumber()));

        return $this->redirectToRoute('app_invoice_view', ['id' => $invoice->getId()]);
    }

    /** Only an invoice that was never emailed can be deleted (ADR-097); the list hides the button for sent ones. */
    #[Route('/{id}/delete', name: 'app_invoice_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Invoice $invoice, Request $request): Response
    {
        $this->assertCsrf($request, 'invoice_delete_'.$invoice->getId());
        $number = $invoice->getNumber();
        $listRoute = $invoice->getKind() === InvoiceKind::Tasks ? 'app_invoice_tasks' : 'app_invoice_independent';
        if (!$this->invoices->delete($invoice, $this->viewer())) {
            $this->addFlash('error', sprintf('Invoice %s has been sent to the client, so it cannot be deleted. Cancel it instead.', $number));

            return $this->redirectToRoute('app_invoice_view', ['id' => $invoice->getId()]);
        }
        $this->addFlash('success', sprintf('Invoice %s deleted.', $number));

        return $this->redirectToRoute($listRoute);
    }

    /** @return string[] the GST rates an invoice's lines were saved with (still offered when editing it) */
    private static function savedGstRates(Invoice $invoice): array
    {
        return array_map(static fn ($item) => $item->getGstRate(), $invoice->getItems()->toArray());
    }

    /** Tells the menu which Invoices entry an invoice's own page belongs under. */
    private function markKind(Request $request, Invoice $invoice): void
    {
        $request->attributes->set('_invoice_kind', $invoice->getKind()->value);
    }

    private function list(?InvoiceKind $kind, Request $request): Response
    {
        $status = InvoiceStatus::tryFrom($request->query->getString('status'));
        $year = InputValue::int($request->query->get('year'));
        $month = InputValue::int($request->query->get('month'));
        $groupBy = in_array($request->query->getString('group'), self::GROUPINGS, true) ? $request->query->getString('group') : null;
        $filters = [
            'term'     => InputValue::text($request->query->get('q')),
            'clientId' => InputValue::int($request->query->get('clientId')),
            'status'   => $status?->value,
            'year'     => $year !== null && $year >= 2000 && $year <= 2100 ? $year : null,
            'month'    => $month !== null && $month >= 1 && $month <= 12 ? $month : null,
        ];
        // Any filter or grouping shows every matching row (no paging), so group totals cover them all.
        $filtered = $groupBy !== null || array_filter($filters, static fn ($value) => $value !== null) !== [];

        return $this->render('invoice/index.html.twig', [
            'kind'     => $kind,
            'filters'  => $filters,
            'groupBy'  => $groupBy,
            'groups'   => $filtered ? $this->invoices->grouped($kind, $filters, $groupBy) : null,
            'page'     => $filtered ? null : $this->invoices->search($kind, $filters, Paginated::pageFrom($request->query->get('page'))),
            'years'    => $this->invoices->years($kind),
            'clients'  => $this->clients->findSelectable(null),
            'statuses' => InvoiceStatus::cases(),
        ]);
    }

    private function form(InvoiceKind $kind, ?Invoice $invoice, Request $request, InvoiceInput $input, bool $thenEmail = false): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'invoice_form');
            $input = InvoiceInput::fromPosted($request->request->all());
            $result = $invoice === null
                ? $this->invoices->create($kind, $input, $this->viewer())
                : $this->invoices->update($invoice, $input, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', sprintf('Invoice %s saved.', $result->record->getNumber()));

                return $this->redirectToRoute($thenEmail ? 'app_invoice_email' : 'app_invoice_view', ['id' => $result->record->getId()]);
            }
            $errors = $result->errors;
        }

        $clients = $this->clients->findSelectable(null);

        return $this->render('invoice/form.html.twig', [
            'kind'       => $kind,
            'invoice'    => $invoice,
            'thenEmail'  => $thenEmail,
            'input'      => $input,
            'errors'     => $errors,
            'profiles'   => $this->profiles->findSelectable($invoice?->getBillingProfileId()),
            'clients'    => $clients,
            // What choosing a client fills in (the form's script reads it).
            'clientCards' => array_map(static fn (Client $client) => [
                'id'        => $client->getId(),
                'toName'    => InvoiceService::billedToName($client),
                'toAddress' => InvoiceService::clientAddress($client),
                'toEmail'   => (string) $client->getEmail(),
                'toPhone'   => (string) $client->getPhone(),
            ], $clients),
            'currencies' => $this->lookups->currencies(),
            'gstChoices' => $this->taxRates->invoiceChoices($invoice !== null ? self::savedGstRates($invoice) : []),
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
