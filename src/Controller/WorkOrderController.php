<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\WorkOrder\WorkOrder;
use App\Entity\WorkOrder\WorkOrderMilestone;
use App\Repository\AttachmentRepository;
use App\Repository\WorkOrder\WorkOrderRepository;
use App\Service\Client\ClientService;
use App\Service\File\AttachmentStorage;
use App\Service\Validation\InputValue;
use App\Service\WorkOrder\WorkOrderService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Work orders (ADR-125), admins only: contracts and jobs won — the list, one work order with its milestones and
 * documents, add/edit/delete, and turning one into a project. Writes: WorkOrderService; files: AttachmentStorage.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/work-order')]
final class WorkOrderController extends AbstractWorkController
{
    public function __construct(
        private readonly WorkOrderService $service,
        private readonly WorkOrderRepository $workOrders,
        private readonly ClientService $clients,
        private readonly AttachmentStorage $files,
    ) {
    }

    #[Route('', name: 'app_work_order_index', methods: ['GET'])]
    public function index(Request $request, AttachmentRepository $attachments): Response
    {
        // "open" (default) = Draft, Active and On hold; "all" = everything; or one status.
        $status = $request->query->getString('status', 'open');
        if ($status !== 'all' && !isset(WorkOrder::STATUSES[$status])) {
            $status = 'open';
        }
        $statuses = match ($status) {
            'all'   => null,
            'open'  => WorkOrder::OPEN_STATUSES,
            default => [$status],
        };
        $clientId = InputValue::int($request->query->get('clientId'));
        $term = InputValue::text($request->query->get('q'));
        $list = $this->workOrders->findFiltered($statuses, $clientId, $term);

        return $this->render('work_order/index.html.twig', [
            'workOrders' => $list,
            'totals'     => $this->service->totals($list),
            'fileCounts' => $attachments->countByOwners(Attachment::OWNER_WORK_ORDER, array_map(static fn (WorkOrder $w) => (int) $w->getId(), $list)),
            'status'     => $status,
            'statuses'   => WorkOrder::STATUSES,
            'clientId'   => $clientId,
            'q'          => $term,
            'clients'    => $this->clients->selectable($this->viewer()),
        ]);
    }

    #[Route('/new', name: 'app_work_order_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->form(null, $request, $this->service->defaults(InputValue::int($request->query->get('clientId'))));
    }

    #[Route('/{id}', name: 'app_work_order_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(WorkOrder $workOrder): Response
    {
        return $this->renderShow($workOrder, [], []);
    }

    #[Route('/{id}/edit', name: 'app_work_order_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(WorkOrder $workOrder, Request $request): Response
    {
        return $this->form($workOrder, $request, $this->service->valuesFrom($workOrder));
    }

    #[Route('/{id}/delete', name: 'app_work_order_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(WorkOrder $workOrder, Request $request): Response
    {
        $this->assertCsrf($request, 'work_order_delete_'.$workOrder->getId());
        $number = $workOrder->getNumber();
        $this->service->delete($workOrder, $this->viewer());
        $this->addFlash('success', sprintf('Work order %s deleted, with its milestones and documents.', $number));

        return $this->redirectToRoute('app_work_order_index');
    }

    #[Route('/{id}/files', name: 'app_work_order_files', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function upload(WorkOrder $workOrder, Request $request): Response
    {
        // A request bigger than post_max_size arrives with no fields at all — not even the CSRF token.
        if ($request->request->count() === 0 && $request->files->count() === 0) {
            $this->addFlash('error', sprintf('The upload was too large for the server (at most %s per request). Try fewer or smaller files.', AttachmentStorage::humanSize($this->files->maxBytes())));

            return $this->redirect($this->generateUrl('app_work_order_show', ['id' => $workOrder->getId()]).'#files');
        }
        $this->assertCsrf($request, 'work_order_files_'.$workOrder->getId());
        $files = $request->files->all()['files'] ?? [];
        $result = $this->files->upload(is_array($files) ? $files : [$files], Attachment::OWNER_WORK_ORDER, (int) $workOrder->getId(),
            InputValue::text($request->request->get('label')), $this->viewer());
        foreach ($result['errors'] as $error) {
            $this->addFlash('error', $error);
        }
        if ($result['saved'] > 0) {
            $this->addFlash('success', $result['saved'] === 1 ? 'File uploaded.' : sprintf('%d files uploaded.', $result['saved']));
        }

        return $this->redirect($this->generateUrl('app_work_order_show', ['id' => $workOrder->getId()]).'#files');
    }

    #[Route('/{id}/milestones', name: 'app_work_order_milestone_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addMilestone(WorkOrder $workOrder, Request $request): Response
    {
        $this->assertCsrf($request, 'work_order_milestone_'.$workOrder->getId());
        $errors = $this->service->addMilestone($workOrder, $request->request->all(), $this->viewer());
        if ($errors !== []) {
            return $this->renderShow($workOrder, $errors, $request->request->all(), 422);
        }
        $this->addFlash('success', 'Milestone added.');

        return $this->redirect($this->generateUrl('app_work_order_show', ['id' => $workOrder->getId()]).'#milestones');
    }

    #[Route('/milestones/{id}/toggle', name: 'app_work_order_milestone_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleMilestone(#[MapEntity(id: 'id')] WorkOrderMilestone $milestone, Request $request): Response
    {
        $this->assertCsrf($request, 'work_order_milestone_row_'.$milestone->getId());
        $this->service->toggleMilestone($milestone, $this->viewer());

        return $this->redirect($this->generateUrl('app_work_order_show', ['id' => $milestone->getWorkOrder()?->getId()]).'#milestones');
    }

    #[Route('/milestones/{id}/delete', name: 'app_work_order_milestone_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteMilestone(#[MapEntity(id: 'id')] WorkOrderMilestone $milestone, Request $request): Response
    {
        $this->assertCsrf($request, 'work_order_milestone_row_'.$milestone->getId());
        $workOrderId = $milestone->getWorkOrder()?->getId();
        $this->service->deleteMilestone($milestone, $this->viewer());
        $this->addFlash('success', 'Milestone removed.');

        return $this->redirect($this->generateUrl('app_work_order_show', ['id' => $workOrderId]).'#milestones');
    }

    #[Route('/{id}/convert', name: 'app_work_order_convert', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function convert(WorkOrder $workOrder, Request $request): Response
    {
        $this->assertCsrf($request, 'work_order_convert_'.$workOrder->getId());
        $result = $this->service->convertToProject($workOrder, $this->viewer());
        if (!$result->isSaved()) {
            foreach ($result->errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_work_order_show', ['id' => $workOrder->getId()]);
        }
        $this->addFlash('success', sprintf('Project created from %s. Add its tasks from the project page.', $workOrder->getNumber()));

        return $this->redirectToRoute('app_project_view', ['id' => $result->record->getId()]);
    }

    /** @param list<string> $errors @param array<string, mixed> $milestoneValues */
    private function renderShow(WorkOrder $workOrder, array $errors, array $milestoneValues, int $status = 200): Response
    {
        return $this->render('work_order/show.html.twig', [
            'w'               => $workOrder,
            'milestones'      => $this->service->milestonesOf($workOrder),
            'attachments'     => $this->files->forOwner(Attachment::OWNER_WORK_ORDER, (int) $workOrder->getId()),
            'errors'          => $errors,
            'milestoneValues' => $milestoneValues,
        ], new Response(status: $status));
    }

    /** @param array<string, mixed> $values */
    private function form(?WorkOrder $workOrder, Request $request, array $values): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'work_order_form');
            $values = $request->request->all();
            $result = $this->service->save($workOrder, $values, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', $workOrder === null
                    ? sprintf('Work order %s created. Add its documents and milestones below.', $result->record->getNumber())
                    : 'Work order saved.');

                return $this->redirectToRoute('app_work_order_show', ['id' => $result->record->getId()]);
            }
            $errors = $result->errors;
        }

        return $this->render('work_order/form.html.twig', [
            'w'          => $workOrder,
            'values'     => $values,
            'errors'     => $errors,
            'clients'    => $this->clients->selectable($this->viewer()),
            'currencies' => $this->service->currencies(),
            'statuses'   => WorkOrder::STATUSES,
            'nextNumber' => $workOrder === null ? $this->service->nextNumber() : null,
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
