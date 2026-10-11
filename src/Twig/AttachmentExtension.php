<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\File\AttachmentStorage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** `{{ bytes|file_size }}`, `upload_limit()` and `upload_accept()` for the attachment panels (ADR-125). */
final class AttachmentExtension extends AbstractExtension
{
    public function __construct(private readonly AttachmentStorage $storage)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('file_size', AttachmentStorage::humanSize(...))];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('upload_limit', fn () => AttachmentStorage::humanSize($this->storage->maxBytes())),
            new TwigFunction('upload_accept', AttachmentStorage::acceptAttribute(...)),
        ];
    }
}
