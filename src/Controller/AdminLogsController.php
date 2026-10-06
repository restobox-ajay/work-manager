<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Log\EmailLog;
use App\Entity\Log\ErrorLog;
use App\Repository\AuditLogRepository;
use App\Repository\Log\EmailLogRepository;
use App\Repository\Log\ErrorLogRepository;
use App\Service\AuditLogFilterParser;
use App\Service\Log\ActivityAreas;
use App\Service\WorkAuditTrail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Logs menu (ADR-093), admins only: an activity entry in full, the activity CSV export, the Email Log and the
 * Error Log, and clearing old email / error entries. (The Activity Log list itself is AdminAuditLogController.)
 */
#[IsGranted('ROLE_ADMIN')]
final class AdminLogsController extends AbstractWorkController
{
    private const PAGE_SIZE = 50;
    private const EXPORT_LIMIT = 20000;
    private const PURGE_DAYS = [30, 90, 180];

    #[Route('/admin/audit-log/{id}', name: 'app_admin_audit_log_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function activity(AuditLog $entry): Response
    {
        return $this->render('admin/logs/activity_show.html.twig', ['e' => $entry, 'area' => ActivityAreas::label($entry->getAction())]);
    }

    #[Route('/admin/audit-log/export', name: 'app_admin_audit_log_export', methods: ['GET'])]
    public function exportActivity(Request $request, AuditLogRepository $repository, AuditLogFilterParser $parser): StreamedResponse
    {
        $filters = $parser->parse($request)->filters;
        $response = new StreamedResponse(static function () use ($repository, $filters) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When (UTC)', 'User', 'User type', 'IP', 'Area', 'Action', 'Outcome', 'Details'], escape: '');
            foreach ($repository->iterateFiltered($filters, self::EXPORT_LIMIT) as $e) {
                fputcsv($out, array_map(self::csvSafe(...), [$e->getCreatedAt()->format('Y-m-d H:i:s'), $e->getActor(), $e->getActorType(), $e->getIp(),
                    ActivityAreas::label($e->getAction()), $e->getAction(), $e->getOutcome(), (string) $e->getContext()]), escape: '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="activity-log-'.date('Y-m-d').'.csv"');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/admin/logs/email', name: 'app_admin_email_log', methods: ['GET'])]
    public function emails(Request $request, EmailLogRepository $repository): Response
    {
        $filters = $this->dateFilters($request) + $this->textFilter($request);
        $status = $request->query->getString('status');
        if (isset(EmailLog::STATUSES[$status])) {
            $filters['status'] = $status;
        }
        $page = max(1, $request->query->getInt('page', 1));
        $result = $repository->search($filters, $page, self::PAGE_SIZE);

        return $this->render('admin/logs/email.html.twig', [
            'items'    => $result['items'],
            'page'     => $this->page($page, $result['total']),
            'query'    => $this->query($request, ['status', 'q', 'from', 'to']),
            'counts'   => $repository->countByStatus(),
            'statuses' => EmailLog::STATUSES,
            'purgeDays' => self::PURGE_DAYS,
        ]);
    }

    #[Route('/admin/logs/email/{id}', name: 'app_admin_email_log_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function email(EmailLog $email): Response
    {
        return $this->render('admin/logs/email_show.html.twig', ['m' => $email]);
    }

    #[Route('/admin/logs/errors', name: 'app_admin_error_log', methods: ['GET'])]
    public function errors(Request $request, ErrorLogRepository $repository): Response
    {
        $filters = $this->dateFilters($request) + $this->textFilter($request);
        $source = $request->query->getString('source');
        if (isset(ErrorLog::SOURCES[$source])) {
            $filters['source'] = $source;
        }
        $page = max(1, $request->query->getInt('page', 1));
        $result = $repository->search($filters, $page, self::PAGE_SIZE);

        return $this->render('admin/logs/errors.html.twig', [
            'items'     => $result['items'],
            'page'      => $this->page($page, $result['total']),
            'query'     => $this->query($request, ['source', 'q', 'from', 'to']),
            'last24h'   => $repository->countBySourceSince(time() - 86400),
            'sources'   => ErrorLog::SOURCES,
            'purgeDays' => self::PURGE_DAYS,
        ]);
    }

    #[Route('/admin/logs/errors/{id}', name: 'app_admin_error_log_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function error(ErrorLog $error): Response
    {
        return $this->render('admin/logs/error_show.html.twig', ['e' => $error]);
    }

    /** Deletes email or error entries older than 30 / 90 / 180 days. Activity is kept: app:prune owns its retention. */
    #[Route('/admin/logs/{kind}/purge', name: 'app_admin_logs_purge', requirements: ['kind' => 'email|errors'], methods: ['POST'])]
    public function purge(string $kind, Request $request, EmailLogRepository $emails, ErrorLogRepository $errors, WorkAuditTrail $audit): Response
    {
        $this->assertCsrf($request, 'logs_purge_'.$kind);
        $days = $request->request->getInt('days');
        if (!in_array($days, self::PURGE_DAYS, true)) {
            throw $this->createNotFoundException();
        }
        $cutoff = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days))->getTimestamp();
        $deleted = $kind === 'email' ? $emails->deleteOlderThan($cutoff) : $errors->deleteOlderThan($cutoff);
        $audit->record($this->viewer(), 'logs.purge_'.$kind, sprintf('Deleted %d %s log entries older than %d days', $deleted, $kind, $days));
        $this->addFlash('success', sprintf('Deleted %d entr%s older than %d days.', $deleted, $deleted === 1 ? 'y' : 'ies', $days));

        return $this->redirectToRoute($kind === 'email' ? 'app_admin_email_log' : 'app_admin_error_log');
    }

    /** Spreadsheet apps run cells starting with = + - @ as formulas: make them plain text. */
    private static function csvSafe(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }

    /** @return array{from?: int, to?: int} */
    private function dateFilters(Request $request): array
    {
        $filters = [];
        foreach (['from' => 0, 'to' => 1] as $key => $days) {
            $text = $request->query->getString($key);
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
            if ($date !== false && $date->format('Y-m-d') === $text) {
                $filters[$key] = $date->modify(sprintf('+%d day', $days))->getTimestamp(); // "to" is inclusive: before the next day
            }
        }

        return $filters;
    }

    /** @return array{q?: string} */
    private function textFilter(Request $request): array
    {
        $q = trim($request->query->getString('q'));

        return $q !== '' ? ['q' => mb_substr($q, 0, 100)] : [];
    }

    /** @return array{page: int, pageSize: int, total: int, pages: int} the shape ui.pager() reads */
    private function page(int $page, int $total): array
    {
        return ['page' => $page, 'pageSize' => self::PAGE_SIZE, 'total' => $total, 'pages' => max(1, (int) ceil($total / self::PAGE_SIZE))];
    }

    /** @param list<string> $keys @return array<string, string> the non-empty filters, for pager links */
    private function query(Request $request, array $keys): array
    {
        return array_filter(array_map(fn (string $k) => trim($request->query->getString($k)), array_combine($keys, $keys)), static fn (string $v) => $v !== '');
    }
}
