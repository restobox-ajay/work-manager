<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Attachment;
use App\Service\File\AttachmentStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serving and removing work order / event files (ADR-125), admins only. Uploading happens on each owner's page.
 * Only PDFs and safe image types are shown in the browser; everything else is sent as a download, never sniffed.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/attachment')]
final class AttachmentController extends AbstractWorkController
{
    public function __construct(private readonly AttachmentStorage $storage)
    {
    }

    #[Route('/{id}', name: 'app_attachment_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(Attachment $attachment, Request $request): Response
    {
        $path = $this->storage->pathOf($attachment);
        if ($path === null) {
            throw $this->createNotFoundException('This file is missing from the server.');
        }

        $inline = $attachment->isViewableInline() && !$request->query->getBoolean('download');
        $response = new BinaryFileResponse($path, 200, [], false);
        $response->headers->set('Content-Type', $inline ? $attachment->getMimeType() : 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $name = $attachment->getOriginalName();
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $name) ?: 'file';
        $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, str_replace(['/', '\\', '%'], '_', $fallback));
        if (in_array($attachment->getMimeType(), Attachment::INLINE_IMAGES, true)) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        }

        return $response;
    }

    #[Route('/{id}/delete', name: 'app_attachment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Attachment $attachment, Request $request): Response
    {
        $this->assertCsrf($request, 'attachment_delete_'.$attachment->getId());
        $back = self::ownerRoute($attachment);
        $this->storage->delete($attachment, $this->viewer());
        $this->addFlash('success', 'File removed.');

        return $this->redirect($back === null ? $this->generateUrl('app_dashboard') : $this->generateUrl($back[0], $back[1]).'#files');
    }

    /** @return array{0: string, 1: array<string, int>}|null the page the file belongs to */
    private static function ownerRoute(Attachment $attachment): ?array
    {
        return match ($attachment->getOwnerType()) {
            Attachment::OWNER_WORK_ORDER => ['app_work_order_show', ['id' => $attachment->getOwnerId()]],
            Attachment::OWNER_EVENT      => ['app_event_show', ['id' => $attachment->getOwnerId()]],
            default                      => null,
        };
    }
}
