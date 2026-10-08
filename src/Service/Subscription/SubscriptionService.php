<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription\Subscription;
use App\Entity\Subscription\SubscriptionPayment;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Repository\Subscription\SubscriptionCategoryRepository;
use App\Repository\Subscription\SubscriptionRepository;
use App\Service\Invoice\InvoiceMoney;
use App\Service\Task\TaskLookups;
use App\Service\Validation\InputValue;
use App\Service\Validation\WriteResult;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Subscriptions (ADR-091): recording apps and services, their renewal payments, what is renewing soon, and what they
 * cost per month and per year in each currency. Amounts are worked in hundredths (InvoiceMoney); secrets such as
 * licence keys belong in the password vault, not here.
 */
final class SubscriptionService
{
    /** "Renewing soon" means within this many days (renewals page default and dashboard alert). */
    public const DUE_SOON_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionCategoryRepository $categories,
        private readonly TaskLookups $lookups,
        private readonly WorkAuditTrail $audit,
    ) {
    }

    /** @return list<string> currencies offered: the ones with a symbol plus any added under Config › Currencies */
    public function currencies(): array
    {
        return array_values(array_unique([...InvoiceMoney::knownCurrencies(), ...array_map('strtoupper', array_values($this->lookups->currencies()))]));
    }

    /** @return array<string, mixed> the form's fields for a subscription */
    public function valuesFrom(Subscription $s): array
    {
        return [
            'name' => $s->getName(), 'vendor' => $s->getVendor(), 'categoryId' => $s->getCategory()?->getId(), 'plan' => $s->getPlan(),
            'amount' => $s->getAmount(), 'currency' => $s->getCurrency(), 'billingCycle' => $s->getBillingCycle(),
            'startDate' => $s->getStartDate()?->format('Y-m-d'), 'nextRenewal' => $s->getNextRenewal()?->format('Y-m-d'),
            'autoRenew' => $s->isAutoRenew(), 'status' => $s->getStatus(), 'accountEmail' => $s->getAccountEmail(),
            'seats' => $s->getSeats(), 'assignedTo' => $s->getAssignedTo(), 'paymentMethod' => $s->getPaymentMethod(),
            'websiteUrl' => $s->getWebsiteUrl(), 'billingUrl' => $s->getBillingUrl(), 'notes' => $s->getNotes(),
            'signupName' => $s->getSignupName(), 'signupPhone' => $s->getSignupPhone(), 'signupMethod' => $s->getSignupMethod(), 'signupMethodNote' => $s->getSignupMethodNote(),
            'accountUsername' => $s->getAccountUsername(), 'recoveryEmail' => $s->getRecoveryEmail(),
            'billingCompany' => $s->getBillingCompany(), 'taxId' => $s->getTaxId(), 'billingAddress' => $s->getBillingAddress(),
            'cardLast4' => $s->getCardLast4(),
        ];
    }

    /** @return array<string, mixed> a new subscription's defaults */
    public function defaults(): array
    {
        return ['currency' => 'INR', 'billingCycle' => BillingCycle::Monthly->value, 'autoRenew' => true, 'status' => Subscription::STATUS_ACTIVE,
            'startDate' => (new \DateTimeImmutable('today'))->format('Y-m-d')];
    }

    /** @param array<string, mixed> $posted @return WriteResult<Subscription> */
    public function save(?Subscription $subscription, array $posted, User $actor): WriteResult
    {
        $errors = [];
        $name = InputValue::text($posted['name'] ?? null) ?? '';
        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'Enter the app or service name (at most 120 characters).';
        }
        $category = null;
        if (($categoryId = InputValue::int($posted['categoryId'] ?? null)) !== null && ($category = $this->categories->find($categoryId)) === null) {
            $errors[] = 'Choose a valid category, or none.';
        }
        $amount = InvoiceMoney::toHundredths(InputValue::text($posted['amount'] ?? null) ?? '0');
        if ($amount === null || $amount < 0 || $amount >= 1_000_000_000_00) {
            $errors[] = 'Cost must be a number of 0 or more, with at most 2 decimals.';
        }
        $currency = strtoupper(InputValue::text($posted['currency'] ?? null) ?? '');
        if (!in_array($currency, $this->currencies(), true)) {
            $errors[] = 'Choose a currency.';
        }
        $cycle = BillingCycle::tryFrom(InputValue::text($posted['billingCycle'] ?? null) ?? '');
        if ($cycle === null) {
            $errors[] = 'Choose how often it is billed.';
        }
        $status = InputValue::text($posted['status'] ?? null) ?? '';
        if (!isset(Subscription::STATUSES[$status])) {
            $errors[] = 'Choose a status.';
        }
        [$startDate, $startOk] = self::optionalDate($posted['startDate'] ?? null);
        [$nextRenewal, $nextOk] = self::optionalDate($posted['nextRenewal'] ?? null);
        if (!$startOk || !$nextOk) {
            $errors[] = 'Dates must be valid dates.';
        }
        if ($cycle?->isRecurring() && $nextOk && $nextRenewal === null && $startDate !== null) {
            // A recurring subscription with only a start date renews one cycle after it (rolled forward past today).
            $nextRenewal = self::rollForward($startDate, $cycle, new \DateTimeImmutable('today'));
        }
        if ($cycle !== null && !$cycle->isRecurring()) {
            $nextRenewal = null;
        }
        $seats = InputValue::text($posted['seats'] ?? null);
        if ($seats !== null && (!ctype_digit($seats) || (int) $seats < 1 || (int) $seats > 100000)) {
            $errors[] = 'Seats must be a whole number from 1, or blank.';
        }
        $email = InputValue::text($posted['accountEmail'] ?? null);
        if ($email !== null && (filter_var($email, \FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 180)) {
            $errors[] = 'Account email is not a valid email address.';
        }
        $urls = [];
        foreach (['websiteUrl' => 'Website', 'billingUrl' => 'Billing portal'] as $field => $label) {
            $urls[$field] = InputValue::url($posted[$field] ?? null);
            if ($urls[$field] !== null && (mb_strlen($urls[$field]) > 255 || !preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $urls[$field]))) {
                $errors[] = $label.' must be a web address (http or https).';
            }
        }
        $short = [];
        foreach (['vendor' => 'Vendor', 'plan' => 'Plan', 'assignedTo' => 'Assigned to', 'paymentMethod' => 'Paid with'] as $field => $label) {
            $short[$field] = InputValue::text($posted[$field] ?? null);
            if ($short[$field] !== null && mb_strlen($short[$field]) > 120) {
                $errors[] = $label.' must be at most 120 characters.';
            }
        }
        $signup = $this->signupDetails($posted, $errors);
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $isNew = $subscription === null;
        $subscription ??= (new Subscription())->setCreatedAt(time())->setCreatedBy($actor->getId());
        $notes = is_scalar($posted['notes'] ?? null) ? trim(str_replace("\r\n", "\n", (string) $posted['notes'])) : '';
        $subscription->setName($name)->setVendor($short['vendor'])->setCategory($category)->setPlan($short['plan'])
            ->setAmount(InvoiceMoney::toDecimal((int) $amount))->setCurrency($currency)->setBillingCycle($cycle->value)
            ->setStartDate($startDate)->setNextRenewal($nextRenewal)->setAutoRenew(InputValue::flag($posted['autoRenew'] ?? false))
            ->setStatus($status)->setAccountEmail($email)->setSeats($seats !== null ? (int) $seats : null)
            ->setAssignedTo($short['assignedTo'])->setPaymentMethod($short['paymentMethod'])
            ->setWebsiteUrl($urls['websiteUrl'])->setBillingUrl($urls['billingUrl'])
            ->setNotes($notes !== '' ? $notes : null)->setUpdatedAt(time())
            ->setSignupName($signup['signupName'])->setSignupPhone($signup['signupPhone'])->setSignupMethod($signup['signupMethod'])->setSignupMethodNote($signup['signupMethodNote'])
            ->setAccountUsername($signup['accountUsername'])->setRecoveryEmail($signup['recoveryEmail'])
            ->setBillingCompany($signup['billingCompany'])->setTaxId($signup['taxId'])->setBillingAddress($signup['billingAddress'])
            ->setCardLast4($signup['cardLast4']);
        if ($isNew) {
            $this->em->persist($subscription);
        }
        $this->em->flush();
        $this->audit->record($actor, 'subscription.'.($isNew ? 'create' : 'update'), $this->describe($subscription));

        return WriteResult::saved($subscription);
    }

    public function delete(Subscription $subscription, User $actor): void
    {
        $label = $this->describe($subscription);
        $this->em->remove($subscription);
        $this->em->flush();
        $this->audit->record($actor, 'subscription.delete', $label);
    }

    /**
     * Records a renewal payment in the subscription's currency; with $advance, a recurring subscription's next
     * renewal moves forward one cycle (from the current renewal date, or from the payment date when there is none).
     *
     * @param array<string, mixed> $posted
     *
     * @return list<string> errors; empty when saved
     */
    public function recordPayment(Subscription $subscription, array $posted, User $actor): array
    {
        [$paidOn, $ok] = self::optionalDate($posted['paidOn'] ?? null);
        $errors = [];
        if (!$ok || $paidOn === null) {
            $errors[] = 'Enter the payment date.';
        }
        $amount = InvoiceMoney::toHundredths(InputValue::text($posted['amount'] ?? null) ?? '');
        if ($amount === null || $amount <= 0 || $amount >= 1_000_000_000_00) {
            $errors[] = 'Amount must be a number above 0, with at most 2 decimals.';
        }
        $note = InputValue::text($posted['note'] ?? null);
        if ($note !== null && mb_strlen($note) > 255) {
            $errors[] = 'Note must be at most 255 characters.';
        }
        if ($errors !== []) {
            return $errors;
        }

        $payment = (new SubscriptionPayment())->setSubscription($subscription)->setPaidOn($paidOn)
            ->setAmount(InvoiceMoney::toDecimal((int) $amount))->setCurrency($subscription->getCurrency())->setNote($note)
            ->setCreatedBy($actor->getId())->setCreatedAt(time());
        $this->em->persist($payment);
        $months = $subscription->cycle()->months();
        if ($months !== null && InputValue::flag($posted['advance'] ?? false)) {
            $subscription->setNextRenewal(($subscription->getNextRenewal() ?? $paidOn)->modify(sprintf('+%d months', $months)))->setUpdatedAt(time());
        }
        $this->em->flush();
        $this->audit->record($actor, 'subscription.payment', sprintf('%s paid %s on %s', $this->describe($subscription),
            InvoiceMoney::display($payment->getAmount(), $payment->getCurrency()), $paidOn->format('Y-m-d')));

        return [];
    }

    public function deletePayment(SubscriptionPayment $payment, User $actor): void
    {
        $label = sprintf('%s payment %s on %s', $this->describe($payment->getSubscription()),
            InvoiceMoney::display($payment->getAmount(), $payment->getCurrency()), $payment->getPaidOn()?->format('Y-m-d'));
        $this->em->remove($payment);
        $this->em->flush();
        $this->audit->record($actor, 'subscription.payment_delete', $label);
    }

    /** overdue | soon | ok | none — how close an active subscription's renewal is */
    public function renewalState(Subscription $s, ?\DateTimeImmutable $today = null): string
    {
        $next = $s->getNextRenewal();
        if (!$s->isActive() || $next === null) {
            return 'none';
        }
        $today ??= new \DateTimeImmutable('today');

        return match (true) {
            $next < $today                                                        => 'overdue',
            $next <= $today->modify(sprintf('+%d days', self::DUE_SOON_DAYS)) => 'soon',
            default                                                               => 'ok',
        };
    }

    /**
     * @param Subscription[] $subscriptions
     *
     * @return array<int, string> subscription id => renewalState()
     */
    public function renewalStates(array $subscriptions): array
    {
        $today = new \DateTimeImmutable('today');
        $states = [];
        foreach ($subscriptions as $s) {
            $states[(int) $s->getId()] = $this->renewalState($s, $today);
        }

        return $states;
    }

    /** @return Subscription[] active subscriptions due within $days days, overdue ones included */
    public function renewing(int $days = self::DUE_SOON_DAYS): array
    {
        return $this->subscriptions->findRenewingBy((new \DateTimeImmutable('today'))->modify(sprintf('+%d days', $days)));
    }

    /** What a recurring subscription costs per month, in hundredths; 0 for one-time / lifetime. */
    public function monthlyCost(Subscription $s): int
    {
        $months = $s->cycle()->months();

        return $months === null ? 0 : self::divideRounded(InvoiceMoney::toHundredths($s->getAmount()) ?? 0, $months);
    }

    /** What a recurring subscription costs per year, in hundredths; 0 for one-time / lifetime. */
    public function yearlyCost(Subscription $s): int
    {
        $months = $s->cycle()->months();

        return $months === null ? 0 : self::divideRounded((InvoiceMoney::toHundredths($s->getAmount()) ?? 0) * 12, $months);
    }

    /**
     * Per currency: what the active recurring subscriptions cost per month and per year, and the same split by
     * category. Currencies are never added together (no conversion).
     *
     * @param Subscription[] $subscriptions
     *
     * @return array<string, array{monthly: int, yearly: int, count: int, byCategory: array<string, array{monthly: int, yearly: int, count: int}>}>
     */
    public function costs(array $subscriptions): array
    {
        $costs = [];
        foreach ($subscriptions as $s) {
            if (!$s->isActive() || !$s->cycle()->isRecurring()) {
                continue;
            }
            $currency = $s->getCurrency();
            $category = $s->getCategory()?->getName() ?? 'Uncategorised';
            $costs[$currency] ??= ['monthly' => 0, 'yearly' => 0, 'count' => 0, 'byCategory' => []];
            $costs[$currency]['byCategory'][$category] ??= ['monthly' => 0, 'yearly' => 0, 'count' => 0];
            foreach ([&$costs[$currency], &$costs[$currency]['byCategory'][$category]] as &$bucket) {
                $bucket['monthly'] += $this->monthlyCost($s);
                $bucket['yearly'] += $this->yearlyCost($s);
                ++$bucket['count'];
            }
            unset($bucket);
        }
        foreach ($costs as &$c) {
            uasort($c['byCategory'], static fn (array $a, array $b) => $b['yearly'] <=> $a['yearly']);
        }
        unset($c);
        uasort($costs, static fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $costs;
    }

    /**
     * The sign-up details (ADR-104), each null when blank. Only the card's last 4 digits are accepted: full card
     * numbers, passwords and recovery codes belong in the Password Manager.
     *
     * @param array<string, mixed> $posted
     * @param list<string>         $errors appended to
     *
     * @return array<string, ?string>
     */
    private function signupDetails(array $posted, array &$errors): array
    {
        $limits = ['signupName' => ['Name used', 120], 'signupPhone' => ['Phone used', 40], 'accountUsername' => ['Username / account ID', 120],
            'billingCompany' => ['Billing company', 160], 'taxId' => ['GST / VAT number', 60]];
        $values = [];
        foreach ($limits as $field => [$label, $max]) {
            $values[$field] = InputValue::text($posted[$field] ?? null);
            if ($values[$field] !== null && mb_strlen($values[$field]) > $max) {
                $errors[] = sprintf('%s must be at most %d characters.', $label, $max);
            }
        }
        $values['recoveryEmail'] = InputValue::text($posted['recoveryEmail'] ?? null);
        if ($values['recoveryEmail'] !== null && (filter_var($values['recoveryEmail'], \FILTER_VALIDATE_EMAIL) === false || mb_strlen($values['recoveryEmail']) > 180)) {
            $errors[] = 'Recovery email is not a valid email address.';
        }
        $values['signupMethod'] = InputValue::text($posted['signupMethod'] ?? null);
        if ($values['signupMethod'] !== null && !isset(Subscription::SIGNUP_METHODS[$values['signupMethod']])) {
            $errors[] = 'Choose how the account was opened, or leave it blank.';
        }
        // The description only belongs to "Other"; switching to another method drops it.
        $values['signupMethodNote'] = $values['signupMethod'] === Subscription::SIGNUP_OTHER ? InputValue::text($posted['signupMethodNote'] ?? null) : null;
        if ($values['signupMethodNote'] !== null && mb_strlen($values['signupMethodNote']) > 120) {
            $errors[] = 'The "Other" description must be at most 120 characters.';
        }
        $values['cardLast4'] = InputValue::text($posted['cardLast4'] ?? null);
        if ($values['cardLast4'] !== null && preg_match('/^\d{4}$/', $values['cardLast4']) !== 1) {
            $errors[] = 'Card: enter only the last 4 digits (keep full card numbers out of here).';
        }
        $address = is_scalar($posted['billingAddress'] ?? null) ? trim(str_replace("\r\n", "\n", (string) $posted['billingAddress'])) : '';
        if (mb_strlen($address) > 1000) {
            $errors[] = 'Billing address must be at most 1000 characters.';
        }
        $values['billingAddress'] = $address !== '' ? $address : null;

        return $values;
    }

    /** @return array{0: ?\DateTimeImmutable, 1: bool} the date (null when blank) and whether the input was valid */
    private static function optionalDate(mixed $value): array
    {
        $text = InputValue::text($value);
        if ($text === null) {
            return [null, true];
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return $date !== false && $date->format('Y-m-d') === $text ? [$date, true] : [null, false];
    }

    private static function rollForward(\DateTimeImmutable $from, BillingCycle $cycle, \DateTimeImmutable $today): \DateTimeImmutable
    {
        $next = $from;
        do {
            $next = $next->modify(sprintf('+%d months', (int) $cycle->months()));
        } while ($next < $today);

        return $next;
    }

    private static function divideRounded(int $numerator, int $denominator): int
    {
        return (int) round($numerator / $denominator, 0, \PHP_ROUND_HALF_UP);
    }

    private function describe(?Subscription $s): string
    {
        return sprintf('#%d %s (%s %s)', (int) $s?->getId(), $s?->getName(), InvoiceMoney::display($s?->getAmount(), (string) $s?->getCurrency()), $s?->cycle()->label());
    }
}
