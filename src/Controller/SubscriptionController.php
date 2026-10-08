<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Subscription\Subscription;
use App\Entity\Subscription\SubscriptionPayment;
use App\Enum\BillingCycle;
use App\Repository\Subscription\SubscriptionCategoryRepository;
use App\Repository\Subscription\SubscriptionPaymentRepository;
use App\Repository\Subscription\SubscriptionRepository;
use App\Service\Subscription\SubscriptionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Subscriptions (ADR-091), admins only: the list, upcoming renewals, cost summary, one subscription with its
 * payment history, and add/edit/delete. Categories are maintained under Config › Subscription Categories.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/subscription')]
final class SubscriptionController extends AbstractWorkController
{
    private const RENEWAL_WINDOWS = [7, 30, 60, 90];

    public function __construct(
        private readonly SubscriptionService $service,
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionCategoryRepository $categories,
        private readonly SubscriptionPaymentRepository $payments,
    ) {
    }

    #[Route('', name: 'app_subscription_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Default to active ones; ?status=all shows every status.
        $status = $request->query->getString('status', Subscription::STATUS_ACTIVE);
        $status = isset(Subscription::STATUSES[$status]) ? $status : 'all';
        $categoryId = $request->query->getInt('category') ?: null;
        $list = $this->subscriptions->findFiltered($status === 'all' ? null : $status, $categoryId);

        return $this->render('subscription/index.html.twig', [
            'subscriptions' => $list,
            'status'        => $status,
            'categoryId'    => $categoryId,
            'categories'    => $this->categories->findAllOrdered(),
            'costs'         => $this->service->costs($list),
            'states'        => $this->service->renewalStates($list),
        ]);
    }

    #[Route('/renewals', name: 'app_subscription_renewals', methods: ['GET'])]
    public function renewals(Request $request): Response
    {
        $days = $request->query->getInt('days', SubscriptionService::DUE_SOON_DAYS);
        $days = in_array($days, self::RENEWAL_WINDOWS, true) ? $days : SubscriptionService::DUE_SOON_DAYS;

        $due = $this->service->renewing($days);

        return $this->render('subscription/renewals.html.twig', [
            'subscriptions' => $due,
            'states'        => $this->service->renewalStates($due),
            'days'          => $days,
            'windows'       => self::RENEWAL_WINDOWS,
        ]);
    }

    #[Route('/summary', name: 'app_subscription_summary', methods: ['GET'])]
    public function summary(): Response
    {
        $all = $this->subscriptions->findFiltered(null, null);

        return $this->render('subscription/summary.html.twig', [
            'costs'    => $this->service->costs($all),
            'counts'   => array_count_values(array_map(static fn (Subscription $s) => $s->getStatus(), $all)),
            'oneTime'  => array_values(array_filter($all, static fn (Subscription $s) => $s->isActive() && !$s->cycle()->isRecurring())),
        ]);
    }

    #[Route('/new', name: 'app_subscription_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->form(null, $request, $this->service->defaults());
    }

    #[Route('/{id}', name: 'app_subscription_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Subscription $subscription): Response
    {
        return $this->renderShow($subscription, [], ['paidOn' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'amount' => $subscription->getAmount(), 'advance' => true]);
    }

    #[Route('/{id}/edit', name: 'app_subscription_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Subscription $subscription, Request $request): Response
    {
        return $this->form($subscription, $request, $this->service->valuesFrom($subscription));
    }

    #[Route('/{id}/delete', name: 'app_subscription_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Subscription $subscription, Request $request): Response
    {
        $this->assertCsrf($request, 'subscription_delete_'.$subscription->getId());
        $this->service->delete($subscription, $this->viewer());
        $this->addFlash('success', 'Subscription deleted.');

        return $this->redirectToRoute('app_subscription_index');
    }

    #[Route('/{id}/payments', name: 'app_subscription_payment_new', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function newPayment(Subscription $subscription, Request $request): Response
    {
        $this->assertCsrf($request, 'subscription_payment_'.$subscription->getId());
        $posted = $request->request->all();
        $errors = $this->service->recordPayment($subscription, $posted, $this->viewer());
        if ($errors !== []) {
            return $this->renderShow($subscription, $errors, $posted, 422);
        }
        $this->addFlash('success', 'Payment recorded.');

        return $this->redirectToRoute('app_subscription_show', ['id' => $subscription->getId()]);
    }

    #[Route('/payments/{id}/delete', name: 'app_subscription_payment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deletePayment(SubscriptionPayment $payment, Request $request): Response
    {
        $this->assertCsrf($request, 'subscription_payment_delete_'.$payment->getId());
        $subscriptionId = $payment->getSubscription()?->getId();
        $this->service->deletePayment($payment, $this->viewer());
        $this->addFlash('success', 'Payment deleted. The renewal date was not changed.');

        return $this->redirectToRoute('app_subscription_show', ['id' => $subscriptionId]);
    }

    /** @param list<string> $errors @param array<string, mixed> $paymentValues */
    private function renderShow(Subscription $subscription, array $errors, array $paymentValues, int $status = 200): Response
    {
        return $this->render('subscription/show.html.twig', [
            's'             => $subscription,
            'payments'      => $this->payments->findForSubscription($subscription),
            'errors'        => $errors,
            'paymentValues' => $paymentValues,
            'state'         => $this->service->renewalState($subscription),
            'monthly'       => $this->service->monthlyCost($subscription),
            'yearly'        => $this->service->yearlyCost($subscription),
        ], new Response(status: $status));
    }

    /** @param array<string, mixed> $values */
    private function form(?Subscription $subscription, Request $request, array $values): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'subscription_form');
            $values = $request->request->all();
            $result = $this->service->save($subscription, $values, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Subscription saved.');

                return $this->redirectToRoute('app_subscription_show', ['id' => $result->record->getId()]);
            }
            $errors = $result->errors;
        }

        return $this->render('subscription/form.html.twig', [
            's'          => $subscription,
            'values'     => $values,
            'errors'     => $errors,
            'categories' => $this->categories->findSelectable($subscription?->getCategory()?->getId()),
            'currencies' => $this->service->currencies(),
            'cycles'     => BillingCycle::choices(),
            'statuses'   => Subscription::STATUSES,
            'signupMethods' => Subscription::SIGNUP_METHODS,
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
