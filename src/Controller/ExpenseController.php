<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Expense\Expense;
use App\Repository\Expense\ExpenseCategoryRepository;
use App\Repository\Rent\RentPropertyRepository;
use App\Service\Expense\ExpenseService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Expenses (ADR-089), admins only: a month's expenses with totals by category, add/edit/delete, and the year view
 * (January to December by category). Categories are maintained under Config › Expense Categories.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/expense')]
final class ExpenseController extends AbstractWorkController
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly ExpenseCategoryRepository $categories,
        private readonly RentPropertyRepository $properties,
    ) {
    }

    #[Route('', name: 'app_expense_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $month = $this->monthFrom($request->query->getString('month'));
        $categoryId = $request->query->getInt('category') ?: null;

        return $this->render('expense/index.html.twig', [
            'month'      => $month,
            'categoryId' => $categoryId,
            'data'       => $this->expenses->month($month, $categoryId),
            'categories' => $this->categories->findAllOrdered(),
            'methods'    => ExpenseService::METHODS,
        ]);
    }

    #[Route('/year', name: 'app_expense_year', methods: ['GET'])]
    public function year(Request $request): Response
    {
        $year = $request->query->getInt('year');
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');

        return $this->render('expense/year.html.twig', ['year' => $year, 'years' => $this->expenses->years(), 'data' => $this->expenses->year($year)]);
    }

    #[Route('/new', name: 'app_expense_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        // ?month=YYYY-MM (from a month's list) dates a new expense in that month: today if it is this month, else the 1st.
        $month = $this->monthFrom($request->query->getString('month'));
        $today = new \DateTimeImmutable('today');
        $date = $month->format('Y-m') === $today->format('Y-m') ? $today : $month;

        return $this->form(null, $request, ['spentOn' => $date->format('Y-m-d'), 'method' => 'cash']);
    }

    #[Route('/{id}/edit', name: 'app_expense_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Expense $expense, Request $request): Response
    {
        return $this->form($expense, $request, $this->expenses->valuesFrom($expense));
    }

    #[Route('/{id}/delete', name: 'app_expense_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Expense $expense, Request $request): Response
    {
        $this->assertCsrf($request, 'expense_delete_'.$expense->getId());
        $month = $expense->getSpentOn()?->format('Y-m');
        $this->expenses->delete($expense, $this->viewer());
        $this->addFlash('success', 'Expense deleted.');

        return $this->redirectToRoute('app_expense_index', ['month' => $month]);
    }

    /** @param array<string, mixed> $values */
    private function form(?Expense $expense, Request $request, array $values): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'expense_form');
            $values = $request->request->all();
            $result = $this->expenses->save($expense, $values, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Expense saved.');
                if ($request->request->getBoolean('another')) {
                    return $this->redirectToRoute('app_expense_new', ['month' => $result->record->getSpentOn()?->format('Y-m')]);
                }

                return $this->redirectToRoute('app_expense_index', ['month' => $result->record->getSpentOn()?->format('Y-m')]);
            }
            $errors = $result->errors;
        }

        return $this->render('expense/form.html.twig', [
            'expense'    => $expense,
            'values'     => $values,
            'errors'     => $errors,
            'categories' => $this->categories->findSelectable($expense?->getCategory()?->getId()),
            'properties' => $this->properties->findAllOrdered(),
            'methods'    => ExpenseService::METHODS,
            'paymentDetailLabels' => ExpenseService::PAYMENT_DETAIL_LABELS,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    private function monthFrom(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value.'-01');

        return $date !== false && $date->format('Y-m') === $value ? $date : new \DateTimeImmutable('first day of this month midnight');
    }
}
