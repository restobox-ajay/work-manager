<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Entity\Invoice;
use App\Entity\InvoiceItem;

/**
 * Line and invoice totals, as on the invoice: Amount = Quantity × Rate unless typed by hand; GST is split equally into CGST and SGST;
 * Total = Amount + CGST + SGST. All in hundredths (InvoiceMoney), each line rounded to the cent before summing.
 */
final class InvoiceCalculator
{
    /**
     * Fills a line's amount, CGST, SGST and total from its quantity, rate and GST %. $amount (hundredths), when the
     * amount was typed by hand, is used as it is.
     */
    public function priceLine(InvoiceItem $item, ?int $amount = null): InvoiceItem
    {
        // Quantity and Rate are optional; with either missing the line's amount is the typed one.
        $amount ??= $item->getQuantity() !== null && $item->getRate() !== null
            ? InvoiceMoney::multiply((int) InvoiceMoney::toHundredths($item->getQuantity()), (int) InvoiceMoney::toHundredths($item->getRate()))
            : 0;
        $gst = (int) InvoiceMoney::toHundredths($item->getGstRate());
        $cgst = InvoiceMoney::percentOf($amount, $gst, 2);
        $sgst = InvoiceMoney::percentOf($amount, $gst, 2);

        return $item->setAmount(InvoiceMoney::toDecimal($amount))
            ->setCgst(InvoiceMoney::toDecimal($cgst))
            ->setSgst(InvoiceMoney::toDecimal($sgst))
            ->setTotal(InvoiceMoney::toDecimal($amount + $cgst + $sgst));
    }

    /** The invoice's subtotal, CGST, SGST and total, from its (priced) lines. */
    public function totalInvoice(Invoice $invoice): Invoice
    {
        $sum = ['amount' => 0, 'cgst' => 0, 'sgst' => 0];
        foreach ($invoice->getItems() as $item) {
            $sum['amount'] += (int) InvoiceMoney::toHundredths($item->getAmount());
            $sum['cgst'] += (int) InvoiceMoney::toHundredths($item->getCgst());
            $sum['sgst'] += (int) InvoiceMoney::toHundredths($item->getSgst());
        }

        return $invoice->setSubtotal(InvoiceMoney::toDecimal($sum['amount']))
            ->setCgstTotal(InvoiceMoney::toDecimal($sum['cgst']))
            ->setSgstTotal(InvoiceMoney::toDecimal($sum['sgst']))
            ->setTotal(InvoiceMoney::toDecimal($sum['amount'] + $sum['cgst'] + $sum['sgst']));
    }
}
