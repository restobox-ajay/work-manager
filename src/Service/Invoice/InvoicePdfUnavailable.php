<?php

declare(strict_types=1);

namespace App\Service\Invoice;

/** The PDF library (dompdf/dompdf) is not installed, so no PDF can be made. */
final class InvoicePdfUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invoice PDFs need the dompdf library. Run: composer require dompdf/dompdf');
    }
}
