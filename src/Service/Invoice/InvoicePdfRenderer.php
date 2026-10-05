<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * An invoice as a PDF (ADR-077): the same document template the print view shows, rendered by Dompdf (the library
 * work-platform used for its invoices). Remote resources are off, so a PDF never fetches anything over the network.
 */
final class InvoicePdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function isAvailable(): bool
    {
        return class_exists(Dompdf::class);
    }

    /** @throws InvoicePdfUnavailable */
    public function render(Invoice $invoice): string
    {
        if (!$this->isAvailable()) {
            throw new InvoicePdfUnavailable();
        }

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        // DejaVu Sans ships with Dompdf and has the ₹, € and £ signs the built-in PDF fonts lack.
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot($this->projectDir);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render('invoice/document.html.twig', ['invoice' => $invoice, 'forPdf' => true]));
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function fileName(Invoice $invoice): string
    {
        return sprintf('invoice-%s.pdf', preg_replace('/[^A-Za-z0-9_-]+/', '-', $invoice->getNumber()));
    }
}
