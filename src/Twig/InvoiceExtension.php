<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Invoice\InvoiceMoney;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** `{{ amount|invoice_money(currency) }}` → "$1,250.50" (ADR-077). */
final class InvoiceExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('invoice_money', InvoiceMoney::display(...))];
    }
}
