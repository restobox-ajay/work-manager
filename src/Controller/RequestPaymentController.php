<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ClientRepository;
use App\Service\Invoice\RequestPaymentBoard;
use App\Service\Task\TaskLookups;
use App\Service\Validation\InputValue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Request Payment (ADR-103), admins only: every approved, not-yet-invoiced task, one block per client and currency.
 * "Generate invoice & email" opens the approved-tasks invoice form for the ticked tasks; once saved it goes
 * straight on to emailing the invoice.
 */
#[IsGranted('ROLE_ADMIN')]
final class RequestPaymentController extends AbstractWorkController
{
    #[Route('/request-payment', name: 'app_request_payment', methods: ['GET'])]
    public function __invoke(Request $request, RequestPaymentBoard $board, ClientRepository $clients, TaskLookups $lookups): Response
    {
        $clientId = InputValue::int($request->query->get('clientId'));
        $currency = InputValue::text($request->query->get('currency'));
        $currencies = $lookups->currencies();
        $currency = $currency !== null && in_array(strtoupper($currency), array_map('strtoupper', $currencies), true) ? strtoupper($currency) : null;

        return $this->render('request_payment/index.html.twig', $board->build($clientId, $currency) + [
            'clients'    => $clients->findSelectable(null),
            'currencies' => $currencies,
            'clientId'   => $clientId,
            'currency'   => $currency,
            'people'     => $lookups->people(),
        ]);
    }
}
