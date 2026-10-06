<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TaskRepository;
use App\Service\Client\ClientService;
use App\Service\Dashboard\DashboardService;
use App\Service\Project\ProjectService;
use App\Service\Subscription\SubscriptionService;
use App\Service\Task\TaskListService;
use App\Service\Task\TaskLookups;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The dashboard (ADR-070): the manager view or the contractor view — DashboardService decides which. */
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractWorkController
{
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(
        Request $request,
        DashboardService $dashboard,
        TaskListService $taskList,
        TaskLookups $lookups,
        ClientService $clients,
        ProjectService $projects,
        SubscriptionService $subscriptions,
    ): Response {
        $viewer = $this->viewer();
        $lookupMaps = [
            'statuses'   => $lookups->statuses(),
            'currencies' => $lookups->currencies(),
            'people'     => $lookups->people(),
        ];

        if (!$dashboard->showsManagerView($viewer, $request->query->get('view') === 'mine')) {
            $work = $dashboard->myWork($viewer);

            return $this->render('dashboard/mine.html.twig', $lookupMaps + [
                'work'        => $work,
                'canManage'   => $this->isGranted('TASK_CREATE'),
                'rows'        => $taskList->rowDetails($viewer, [...$work['pending'], ...$work['review'], ...$work['approved']]),
            ]);
        }

        $filters = $taskList->filtersFrom($request->query->all());
        $page = $taskList->search($viewer, $filters, 1, $request->query->getString('sort'), DashboardService::MANAGER_TASK_LIMIT);
        $clientId = $filters['clientId'];

        // Admins see subscriptions renewing soon (ADR-091); nobody else manages them.
        $renewing = $this->isGranted('ROLE_ADMIN') ? $subscriptions->renewing() : [];

        return $this->render('dashboard/index.html.twig', $lookupMaps + [
            'renewing'       => $renewing,
            'renewingStates' => $subscriptions->renewalStates($renewing),
            'page'     => $page,
            'rows'     => $taskList->rowDetails($viewer, $page->items),
            'filters'  => $filters,
            'clients'  => $dashboard->liveClients($clients->selectable($viewer)),
            'projects' => $clientId > 0 ? $projects->selectable($viewer, $clientId) : [],
            'noneId'   => TaskRepository::NO_PROJECT_ID,
        ]);
    }
}
