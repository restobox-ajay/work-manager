<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Invoice\RequestPaymentBoard;
use App\Service\Validation\InputValue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Request Payment (ADR-103, ADR-110), admins only: one client at a time — its approved, not-yet-invoiced tasks, one
 * block per currency. "Generate invoice & email" opens the approved-tasks invoice form for the ticked tasks; once
 * saved it goes straight on to emailing the invoice.
 */
#[IsGranted('ROLE_ADMIN')]
final class RequestPaymentController extends AbstractWorkController
{
    #[Route('/request-payment', name: 'app_request_payment', methods: ['GET'])]
    public function __invoke(Request $request, RequestPaymentBoard $board): Response
    {
        return $this->render('request_payment/index.html.twig', $board->forClient(InputValue::int($request->query->get('clientId'))));
    }
}
