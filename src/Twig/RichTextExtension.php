<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Text\RichText;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** `{{ value|rich_text }}`: editor HTML through the allowlist, plain text with its line breaks (ADR-118). */
final class RichTextExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('rich_text', RichText::render(...), ['is_safe' => ['html']])];
    }
}
