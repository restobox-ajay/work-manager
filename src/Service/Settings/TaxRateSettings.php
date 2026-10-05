<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Entity\Settings\TaxRate;
use App\Entity\User;
use App\Repository\Settings\TaxRateRepository;
use App\Service\Invoice\InvoiceMoney;
use App\Service\Validation\InputValue;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Settings › Tax Rates (ADR-078): the whole table is edited and saved at once, as on the Maxeme Auto settings page.
 * Rows are kept in the order shown; a rate is switched off rather than deleted, since saved invoice lines carry
 * their own copy of the rate.
 */
final class TaxRateSettings
{
    private const CODE_PATTERN = '/^[A-Za-z0-9_-]{1,10}$/';
    private const MAX_ROWS = 50;

    public function __construct(
        private readonly TaxRateRepository $rates,
        private readonly EntityManagerInterface $em,
        private readonly WorkAuditTrail $audit,
    ) {
    }

    /**
     * The rates an invoice line can pick, as "18.00" => "GST 18 (18%)". $keep (a line's saved rate) stays offered
     * even if no active row has it any more.
     *
     * @return array<string, string>
     */
    public function invoiceChoices(array $keep = []): array
    {
        $choices = [];
        foreach ($this->rates->findActiveOrdered() as $rate) {
            $choices[$rate->getRate()] ??= sprintf('%s (%s%%)', $rate->getName(), self::short($rate->getRate()));
        }
        foreach ($keep as $value) {
            $value = InvoiceMoney::toDecimal((int) InvoiceMoney::toHundredths((string) $value));
            $choices[$value] ??= sprintf('%s%% (no longer in Settings)', self::short($value));
        }

        return $choices;
    }

    /**
     * @param array<mixed> $postedRows rows[i][id|code|name|rate|isActive], in display order
     *
     * @return list<string> errors; empty when saved
     */
    public function save(array $postedRows, User $actor): array
    {
        $existing = [];
        foreach ($this->rates->findAllOrdered() as $rate) {
            $existing[(int) $rate->getId()] = $rate;
        }

        $rows = [];
        $errors = [];
        $codes = [];
        foreach (array_values(array_filter($postedRows, 'is_array')) as $index => $posted) {
            $id = InputValue::int($posted['id'] ?? null);
            $code = strtoupper(InputValue::text($posted['code'] ?? null) ?? '');
            $name = InputValue::text($posted['name'] ?? null) ?? '';
            $rateText = InputValue::text($posted['rate'] ?? null) ?? '';
            if ($id === null && $code === '' && $name === '' && $rateText === '') {
                continue; // an added row left empty
            }
            $row = $index + 1;
            if ($id !== null && !isset($existing[$id])) {
                $errors[] = sprintf('Row %d no longer exists: reload the page.', $row);
                continue;
            }
            if ($id !== null) {
                $code = $existing[$id]->getCode(); // the code identifies a rate and is not changed
            } elseif (preg_match(self::CODE_PATTERN, $code) !== 1) {
                $errors[] = sprintf('Row %d: the code is 1 to 10 letters, digits, "-" or "_".', $row);
            }
            if (isset($codes[$code])) {
                $errors[] = sprintf('Row %d: code %s is used twice.', $row, $code);
            }
            $codes[$code] = true;
            if ($name === '' || mb_strlen($name) > 60) {
                $errors[] = sprintf('Row %d: enter a name of at most 60 characters.', $row);
            }
            $rate = InvoiceMoney::toHundredths($rateText);
            if ($rate === null || $rate < 0 || $rate > 100_00) {
                $errors[] = sprintf('Row %d: the rate is a percentage from 0 to 100, with at most 2 decimals.', $row);
            }
            $rows[] = ['entity' => $id !== null ? $existing[$id] : null, 'code' => $code, 'name' => $name,
                'rate' => InvoiceMoney::toDecimal((int) $rate), 'isActive' => InputValue::flag($posted['isActive'] ?? null)];
        }
        if (count($rows) > self::MAX_ROWS) {
            $errors[] = sprintf('At most %d tax rates.', self::MAX_ROWS);
        }
        if ($errors !== []) {
            return $errors;
        }

        $changes = [];
        foreach ($rows as $position => $row) {
            $rate = $row['entity'] ?? (new TaxRate())->setCode($row['code']);
            $before = $rate->getId() !== null ? sprintf('%s %s%%%s', $rate->getName(), self::short($rate->getRate()), $rate->isActive() ? '' : ' off') : null;
            $after = sprintf('%s %s%%%s', $row['name'], self::short($row['rate']), $row['isActive'] ? '' : ' off');
            if ($before !== $after || $rate->getSortOrder() !== $position) {
                $rate->setName($row['name'])->setRate($row['rate'])->setIsActive($row['isActive'])->setSortOrder($position)
                    ->setUpdatedAt(time())->setUpdatedBy($actor->getId());
                if ($before !== $after) {
                    $changes[] = $row['code'].': '.($before === null ? 'added '.$after : $before.' → '.$after);
                }
            }
            if ($rate->getId() === null) {
                $this->em->persist($rate);
            }
        }
        $this->em->flush();

        if ($changes !== []) {
            $this->audit->record($actor, 'settings.tax_rates_update', implode('; ', $changes));
        }

        return [];
    }

    /** "18.00" → "18", "2.50" → "2.5" */
    public static function short(string $rate): string
    {
        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }
}
