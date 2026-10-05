<?php

declare(strict_types=1);

namespace App\Service\Config;

use App\Entity\Settings\Country;
use App\Entity\Settings\Currency;
use App\Entity\Settings\EmailTemplate;
use App\Entity\Settings\Payer;
use App\Entity\Settings\PaymentMethod;
use App\Entity\Settings\Tag;
use App\Entity\Settings\TaskStatus;
use App\Entity\Settings\TaskType;
use App\Entity\Settings\WalletEntity;
use App\Enum\PayerType;
use App\Service\Config\ConfigField as F;

/**
 * Every Config list (ADR-070/073/074), in menu order. Fields, limits and messages are work-platform's
 * (its Settings\*Controller field arrays and entity constraints).
 *
 * Deletable: lists nothing points at by id. Task statuses, task types, currencies and payers are referenced from
 * tasks by id, so they are switched off (or edited) rather than deleted.
 */
final class ConfigListRegistry
{
    /** The URL slugs, for the route requirement. */
    public const KINDS = 'task-statuses|task-types|currencies|tags|payers|wallet-entities|payment-methods|email-templates|countries';

    /** @var array<string, ConfigListDefinition>|null */
    private ?array $definitions = null;

    /** @return array<string, ConfigListDefinition> kind => definition, in menu order */
    public function all(): array
    {
        return $this->definitions ??= $this->build();
    }

    public function get(string $kind): ConfigListDefinition
    {
        return $this->all()[$kind] ?? throw new \InvalidArgumentException(sprintf('Unknown config list "%s".', $kind));
    }

    /** @return array<string, ConfigListDefinition> */
    private function build(): array
    {
        $active = static fn (string $getter, string $setter) => new F('isActive', 'Active', $getter, $setter, F::CHECKBOX, help: 'Offered on forms');

        $lists = [
            new ConfigListDefinition('task-statuses', TaskStatus::class, 'Task Statuses', 'Task status', [
                new F('order', 'Order', 'getOrder', 'setOrder', F::INTEGER),
                new F('name', 'Name', 'getName', 'setName', required: true, maxLength: 50),
                $active('isActive', 'setIsActive'),
            ], ['order' => 'ASC', 'id' => 'ASC'], false,
                'Task forms offer the active ones, in this order. Paid is set by the payment flow, not by hand.',
                defaults: ['isActive' => true]),

            new ConfigListDefinition('task-types', TaskType::class, 'Task Types', 'Task type', [
                new F('name', 'Name', 'getName', 'setName', required: true, maxLength: 60),
                new F('description', 'Description', 'getDescription', 'setDescription', maxLength: 255),
                $active('isStatus', 'setStatus'),
            ], ['name' => 'ASC'], false,
                'Task forms offer the active ones. Switch one off rather than removing it: existing tasks keep it.',
                defaults: ['isActive' => true]),

            new ConfigListDefinition('currencies', Currency::class, 'Currencies', 'Currency', [
                new F('shortName', 'Short Name', 'getShortName', 'setShortName', required: true, maxLength: 10, unique: 'This currency already exists.'),
                new F('fxRate', 'FX Rate', 'getFxRate', 'setFxRate', F::DECIMAL, required: true, help: 'For CAD: how many CAD make 1 USD.'),
            ], ['shortName' => 'ASC'], false,
                'The currencies a task\'s payout can be in. CAD\'s FX rate is CAD per 1 USD.',
                defaults: ['fxRate' => '1.00']),

            new ConfigListDefinition('tags', Tag::class, 'Tags', 'Tag', [
                new F('name', 'Name', 'getName', 'setName', required: true, maxLength: 50),
                new F('type', 'Type', 'getType', 'setType', maxLength: 50),
            ], ['name' => 'ASC'], true,
                'Labels given to project staff (their roles on a project).'),

            new ConfigListDefinition('payers', Payer::class, 'Payer Entities', 'Payer entity', [
                new F('companyName', 'Company Name', 'getCompanyName', 'setCompanyName', required: true, maxLength: 255),
                new F('contactName', 'Contact Name', 'getContactName', 'setContactName', required: true, maxLength: 255),
                new F('email', 'Email', 'getEmail', 'setEmail', required: true, maxLength: 255),
                new F('type', 'Type', 'getType', 'setType', F::CHOICE, required: true,
                    choices: [PayerType::Company->value => 'Company', PayerType::User->value => 'User'],
                    toForm: static fn (?PayerType $type) => $type?->value,
                    fromForm: static fn (?string $value) => PayerType::tryFrom((string) $value) ?? PayerType::Company),
                new F('userId', 'User', 'getUserId', 'setUserId', F::USER, help: 'Only for type User: the person this payer is.'),
                new F('status', 'Active', 'getStatus', 'setStatus', F::CHECKBOX, help: 'Offered when paying'),
            ], ['companyName' => 'ASC'], false,
                'Who pays: chosen on the Make Payment form, and copied onto the payment.',
                check: static fn (array $values) => ($values['type'] ?? null) === PayerType::User->value && ($values['userId'] ?? null) === null
                    ? ['Choose the user for a payer of type User.']
                    : [],
                defaults: ['type' => PayerType::Company->value, 'status' => true],
                // A company payer is nobody's account: drop a user left selected from an earlier choice.
                prepare: static fn (array $values) => ($values['type'] ?? null) === PayerType::User->value ? $values : ['userId' => null] + $values),

            new ConfigListDefinition('wallet-entities', WalletEntity::class, 'Wallet Entities', 'Wallet entity', [
                new F('name', 'Name', 'getName', 'setName', required: true, maxLength: 255),
                new F('ownerName', 'Owner Name', 'getOwnerName', 'setOwnerName', maxLength: 255),
            ], ['name' => 'ASC'], true,
                'The wallets payments are made from: chosen on the Make Payment form.'),

            new ConfigListDefinition('payment-methods', PaymentMethod::class, 'Payment Methods', 'Payment method', [
                new F('name', 'Name', 'getName', 'setName', required: true, maxLength: 60, unique: 'This name is already in use.'),
                new F('description', 'Description', 'getDescription', 'setDescription', F::TEXTAREA),
                $active('isActive', 'setIsActive'),
            ], ['name' => 'ASC'], true,
                'How a payment is made: offered on the Make Payment form.',
                defaults: ['isActive' => true]),

            new ConfigListDefinition('email-templates', EmailTemplate::class, 'Email Templates', 'Email template', [
                new F('module', 'Module', 'getModule', 'setModule', required: true, maxLength: 100, unique: 'This module is already in use.'),
                new F('subject', 'Subject', 'getSubject', 'setSubject', required: true, maxLength: 255),
                new F('body', 'Body', 'getBody', 'setBody', F::TEXTAREA, required: true, listed: false),
            ], ['module' => 'ASC'], true,
                'The emails the app sends, one per module.'),

            new ConfigListDefinition('countries', Country::class, 'Countries', 'Country', [
                new F('code', 'Code', 'getCode', 'setCode', required: true, maxLength: 2, unique: 'This country code already exists.'),
                new F('name', 'Name', 'getName', 'setName', required: true, maxLength: 100),
                new F('sortOrder', 'Sort Order', 'getSortOrder', 'setSortOrder', F::INTEGER),
            ], ['sortOrder' => 'ASC', 'name' => 'ASC'], true,
                'Countries offered in address forms, in this order.'),
        ];

        $byKind = [];
        foreach ($lists as $list) {
            $byKind[$list->kind] = $list;
        }

        return $byKind;
    }
}
