<?php

declare(strict_types=1);

namespace App\Service\Demo;

use App\Entity\Client;
use App\Entity\Project;
use App\Entity\ProjectStaff;
use App\Entity\Settings\BillingProfile;
use App\Entity\Settings\TaskStatus;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\InvoiceKind;
use App\Repository\Expense\ExpenseCategoryRepository;
use App\Repository\Rent\RentTenantRepository;
use App\Repository\Settings\BillingProfileRepository;
use App\Repository\Subscription\SubscriptionCategoryRepository;
use App\Repository\UserRepository;
use App\Service\Expense\ExpenseService;
use App\Service\Invoice\InvoiceInput;
use App\Service\Invoice\InvoiceLineInput;
use App\Service\Invoice\InvoiceService;
use App\Service\Note\NoteService;
use App\Service\Project\ProjectStaffService;
use App\Service\Rent\RentService;
use App\Service\Subscription\SubscriptionService;
use App\Service\Validation\WriteResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Demo data for every module (ADR-103, ADR-108): clients, projects, staff and tasks; invoices; notes; rent; expenses
 * and subscriptions. Everything after the tasks goes through the modules' own services, so it is validated and
 * audited (it fills the Activity Log too) exactly as if typed in. Each demo record carries a marker so purge()
 * removes only demo data: client codes DEMO…, property names "Demo – …", expense descriptions "Demo: …", note titles
 * "Demo: …", subscriptions tagged [demo-data] in their notes, and the "Demo Billing Co." profile when it had to be
 * created. The Password Manager is left alone: its entries are encrypted in the browser with your master password.
 */
final class DemoData
{
    public const CLIENT_CODE_PREFIX = 'DEMO';
    private const PROPERTY_PREFIX = 'Demo – ';
    private const TEXT_PREFIX = 'Demo: ';
    private const SUBSCRIPTION_TAG = '[demo-data]';
    private const BILLING_PROFILE = 'Demo Billing Co.';

    /** [code, name, company, email, city, province, country, currency, projects => [name => [[task, status, amount], …]]] */
    private const CLIENTS = [
        ['DEMO1', 'Acme Retail', 'Acme Retail Ltd.', 'accounts@acme-retail.example', 'Toronto', 'ON', 'Canada', 'CAD', [
            'Online store redesign' => [
                ['Homepage and category page design', 'approved', '850.00'],
                ['Product page templates', 'approved', '640.00'],
                ['Checkout flow UX review', 'pending', '320.00'],
                ['Brand style guide update', 'paid', '400.00'],
            ],
            'Inventory sync' => [
                ['Shopify ↔ ERP stock sync script', 'approved', '1200.00'],
                ['Nightly sync error report email', 'reviewing', '180.00'],
                ['Barcode import for warehouse', 'pending', '260.00'],
            ],
        ]],
        ['DEMO2', 'Bluewave Foods', 'Bluewave Foods Inc.', 'billing@bluewave-foods.example', 'Vancouver', 'BC', 'Canada', 'CAD', [
            'Wholesale ordering portal' => [
                ['Customer login and price lists', 'approved', '960.00'],
                ['Order PDF and email confirmation', 'approved', '420.00'],
                ['Delivery route export', 'pending', '300.00'],
            ],
            'Marketing site' => [
                ['Recipe blog setup', 'paid', '350.00'],
                ['SEO audit fixes', 'approved', '275.00'],
            ],
        ]],
        ['DEMO3', 'Nimbus Software', 'Nimbus Software LLC', 'ap@nimbus-software.example', 'Austin', 'TX', 'United States', 'USD', [
            'Customer dashboard' => [
                ['Usage charts (daily / monthly)', 'approved', '1500.00'],
                ['Team invitations and roles', 'approved', '780.00'],
                ['Dark mode', 'pending', '240.00'],
                ['Billing page with Stripe portal link', 'reviewing', '520.00'],
            ],
            'API maintenance' => [
                ['Upgrade to PHP 8.3', 'paid', '600.00'],
                ['Rate-limit headers', 'approved', '190.00'],
            ],
        ]],
        ['DEMO4', 'Harbour Dental', 'Harbour Dental Clinic', 'office@harbour-dental.example', 'Seattle', 'WA', 'United States', 'USD', [
            'Appointment booking' => [
                ['Online booking widget', 'approved', '880.00'],
                ['SMS reminders', 'pending', '340.00'],
                ['Patient intake form', 'on hold', '410.00'],
            ],
        ]],
    ];

    /** [name, vendor, category, plan, amount, currency, cycle, status, signup method, card last 4, days to renewal] */
    private const SUBSCRIPTIONS = [
        ['Claude Pro', 'Anthropic', 'AI tools', 'Pro', '20.00', 'USD', 'monthly', 'active', 'google', '4242', 4],
        ['PhpStorm', 'JetBrains', 'Development tools', 'Individual', '99.00', 'EUR', 'yearly', 'active', 'email', '4242', 45],
        ['Microsoft 365 Business', 'Microsoft', 'Office & productivity', 'Business Standard', '6199.00', 'INR', 'yearly', 'active', 'microsoft', '1881', -3],
        ['Windows 11 Pro', 'Microsoft', 'Operating system', 'Retail licence', '14999.00', 'INR', 'one_time', 'active', 'microsoft', '1881', null],
        ['GitHub Team', 'GitHub', 'Development tools', 'Team (3 seats)', '12.00', 'USD', 'monthly', 'active', 'github', '4242', 18],
        ['Figma Professional', 'Figma', 'Design', 'Professional', '15.00', 'USD', 'monthly', 'paused', 'other', '4242', null],
    ];

    /** [category, description, amount, method, days ago] */
    private const EXPENSES = [
        ['Electricity', 'Office electricity bill', '2340.50', 'upi', 5],
        ['Internet & phone', 'Fibre broadband — monthly', '1179.00', 'bank', 8],
        ['Salaries', 'Office assistant salary', '18000.00', 'bank', 10],
        ['Office supplies', 'Printer paper and toner', '1650.00', 'cash', 14],
        ['Maintenance', 'AC servicing (2 units)', '2400.00', 'upi', 21],
        ['Travel', 'Client visit — cab fares', '860.00', 'upi', 26],
        ['Electricity', 'Office electricity bill', '2105.00', 'upi', 35],
        ['Internet & phone', 'Fibre broadband — monthly', '1179.00', 'bank', 38],
        ['Salaries', 'Office assistant salary', '18000.00', 'bank', 40],
        ['Repairs', 'Laptop screen replacement', '7200.00', 'cheque', 47],
        ['Water', 'Drinking water cans', '540.00', 'cash', 52],
        ['Property tax', 'Half-yearly property tax', '9800.00', 'bank', 60],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly ProjectStaffService $staff,
        private readonly InvoiceService $invoices,
        private readonly BillingProfileRepository $billingProfiles,
        private readonly NoteService $notes,
        private readonly RentService $rent,
        private readonly RentTenantRepository $tenants,
        private readonly ExpenseService $expenses,
        private readonly ExpenseCategoryRepository $expenseCategories,
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionCategoryRepository $subscriptionCategories,
    ) {
    }

    public function exists(): bool
    {
        return $this->demoClientIds() !== [];
    }

    /** @return array{0: ?User, 1: list<User>} who "creates" the demo data (an admin), and the contractors to assign */
    public function people(): array
    {
        $creator = null;
        $assignees = [];
        foreach ($this->users->findBy([], ['id' => 'ASC']) as $user) {
            $roles = $user->getRoles();
            $isAdmin = array_intersect($roles, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_TECH_SUPPORT']) !== [];
            if ($isAdmin && $creator === null && !in_array('ROLE_TECH_SUPPORT', $roles, true)) {
                $creator = $user;
            } elseif (!$isAdmin && $user->isActive()) {
                $assignees[] = $user;
            }
        }

        // A one-person setup (ADR-114): the demo tasks are the admin's own.
        return [$creator, $assignees === [] && $creator !== null ? [$creator] : $assignees];
    }

    /**
     * @return array{counts: array<string, int>, problems: list<string>} what was added, and anything a module refused
     */
    public function seed(User $actor, array $assignees): array
    {
        $problems = [];
        [$clients, $projects, $counts] = $this->seedWork($actor, $assignees);
        $counts['staff'] = $this->seedStaff($projects, $assignees, $actor, $problems);
        $counts['invoices'] = $this->seedInvoices($clients, $actor, $problems);
        $counts['notes'] = $this->seedNotes($clients, $projects, $actor, $problems);
        [$counts['properties'], $counts['tenants'], $counts['rent bills'], $counts['rent payments']] = $this->seedRent($actor, $problems);
        $counts['expenses'] = $this->seedExpenses($actor, $problems);
        [$counts['subscriptions'], $counts['subscription payments']] = $this->seedSubscriptions($actor, $problems);

        return ['counts' => $counts, 'problems' => $problems];
    }

    /** @return array<string, int> what was removed */
    public function purge(): array
    {
        $clientIds = $this->demoClientIds();

        return $this->connection->transactional(function (Connection $db) use ($clientIds): array {
            $ints = ArrayParameterType::INTEGER;
            $counts = [];
            $invoiceIds = $clientIds === [] ? [] : $db->fetchFirstColumn('SELECT id FROM invoice WHERE client_id IN (?)', [$clientIds], [$ints]);
            if ($invoiceIds !== []) {
                $db->executeStatement('DELETE FROM invoice_log WHERE invoice_id IN (?)', [$invoiceIds], [$ints]);
                $db->executeStatement('DELETE FROM invoice WHERE id IN (?)', [$invoiceIds], [$ints]); // items cascade
            }
            $counts['invoices'] = count($invoiceIds);
            $projectIds = $clientIds === [] ? [] : $db->fetchFirstColumn('SELECT id FROM project WHERE client_id IN (?)', [$clientIds], [$ints]);
            $counts['tasks'] = $projectIds === [] ? 0 : $db->executeStatement('DELETE FROM task WHERE project_id IN (?)', [$projectIds], [$ints]); // their notes cascade
            if ($projectIds !== []) {
                $db->executeStatement('DELETE FROM project_staff WHERE project_id IN (?)', [$projectIds], [$ints]);
            }
            $counts['projects'] = $clientIds === [] ? 0 : $db->executeStatement('DELETE FROM project WHERE client_id IN (?)', [$clientIds], [$ints]);
            if ($clientIds !== []) {
                $db->executeStatement('DELETE FROM client_admin WHERE client_id IN (?)', [$clientIds], [$ints]);
            }
            $counts['clients'] = $clientIds === [] ? 0 : $db->executeStatement('DELETE FROM client WHERE id IN (?)', [$clientIds], [$ints]);
            $counts['notes'] = $db->executeStatement('DELETE FROM note WHERE title LIKE ?', [self::TEXT_PREFIX.'%']);

            $propertyIds = $db->fetchFirstColumn('SELECT id FROM rent_property WHERE name LIKE ?', [self::PROPERTY_PREFIX.'%']);
            $tenantIds = $propertyIds === [] ? [] : $db->fetchFirstColumn('SELECT id FROM rent_tenant WHERE property_id IN (?)', [$propertyIds], [$ints]);
            if ($tenantIds !== []) {
                $db->executeStatement('DELETE FROM rent_payment WHERE tenant_id IN (?)', [$tenantIds], [$ints]);
                $db->executeStatement('DELETE FROM rent_bill WHERE tenant_id IN (?)', [$tenantIds], [$ints]);
                $db->executeStatement('DELETE FROM rent_tenant WHERE id IN (?)', [$tenantIds], [$ints]);
            }
            $counts['tenants'] = count($tenantIds);
            $counts['expenses'] = $db->executeStatement('DELETE FROM expense WHERE description LIKE ?', [self::TEXT_PREFIX.'%']);
            $counts['properties'] = $propertyIds === [] ? 0 : $db->executeStatement('DELETE FROM rent_property WHERE id IN (?)', [$propertyIds], [$ints]);
            $counts['subscriptions'] = $db->executeStatement('DELETE FROM subscription WHERE notes LIKE ?', ['%'.self::SUBSCRIPTION_TAG.'%']); // payments cascade
            // The demo billing profile only when nothing else uses it.
            $db->executeStatement('DELETE FROM billing_profile WHERE name = ? AND id NOT IN (SELECT billing_profile_id FROM invoice WHERE billing_profile_id IS NOT NULL)', [self::BILLING_PROFILE]);

            return $counts;
        });
    }

    /** @return array{0: list<Client>, 1: list<Project>, 2: array<string, int>} */
    private function seedWork(User $actor, array $assignees): array
    {
        $statusIds = $this->statusIds();
        $currencyIds = $this->connection->fetchAllKeyValue('SELECT UPPER(short_name), id FROM currency');
        $taskTypeId = $this->connection->fetchOne('SELECT MIN(id) FROM task_type');
        $now = time();
        $clients = [];
        $projects = [];
        $tasks = 0;
        $n = 0;

        foreach (self::CLIENTS as $i => [$code, $name, $company, $email, $city, $province, $country, $currency, $projectList]) {
            $client = (new Client())->setName($name)->setCompanyName($company)->setClientCode($code)->setEmail($email)
                ->setStreetAddress1(sprintf('%d Market Street', 100 + 25 * $i))->setCity($city)->setProvince($province)
                ->setCountry($country)->setPhone(sprintf('555-01%02d', 10 + $i))
                ->setCreatedAt($now)->setUpdatedAt($now)->setCreatedBy($actor->getId());
            $this->em->persist($client);
            $clients[] = $client;

            foreach ($projectList as $projectName => $taskList) {
                $project = (new Project())->setClient($client)->setName($projectName)->setDescription('Demo project for '.$name.'.')
                    ->setCreatedAt($now)->setUpdatedAt($now)->setCreatedBy($actor->getId());
                $this->em->persist($project);
                $projects[] = $project;

                foreach ($taskList as [$taskName, $status, $amount]) {
                    $daysAgo = 3 + ($n * 5) % 40;
                    $created = $now - ($daysAgo + 10) * 86400;
                    $task = (new Task())->setProject($project)->setAssignee($assignees === [] ? null : $assignees[$n % count($assignees)])
                        ->setName($taskName)->setDescription('Demo task.')->setTaskTypeId($taskTypeId !== false ? (int) $taskTypeId : null)
                        ->setCurrencyId(isset($currencyIds[$currency]) ? (int) $currencyIds[$currency] : null)->setTotalAmount($amount)
                        ->setTaskStatusId($statusIds[$status] ?? TaskStatus::PENDING_ID)->setIsActive(1)->setIsDeleted(0)
                        ->setCreatedAt($created)->setUpdatedAt($now)->setCreatedBy($actor->getId())
                        ->setCreationDate((new \DateTimeImmutable())->setTimestamp($created))->setDueDate($created + 14 * 86400);
                    if (in_array($status, ['approved', 'paid'], true)) {
                        $task->setApprovedDate($now - $daysAgo * 86400)->setApprovedBy($actor->getId());
                    }
                    if ($status === 'paid') {
                        $task->setPaidDate($now - max(1, $daysAgo - 2) * 86400)->setPaidBy($actor->getId());
                    }
                    $this->em->persist($task);
                    ++$tasks;
                    ++$n;
                }
            }
        }
        $this->em->flush();

        return [$clients, $projects, ['clients' => count($clients), 'projects' => count($projects), 'tasks' => $tasks]];
    }

    /** @param list<Project> $projects @param list<string> $problems */
    private function seedStaff(array $projects, array $assignees, User $actor, array &$problems): int
    {
        $added = 0;
        foreach ($projects as $i => $project) {
            if ($assignees === []) {
                break;
            }
            $error = $this->staff->add($project, $assignees[$i % count($assignees)], ProjectStaff::PERMISSION_CONTRACTOR, $i % 2 === 0, $actor);
            $error === null ? ++$added : $problems[] = 'Project staff: '.$error;
        }

        return $added;
    }

    /**
     * Two approved-tasks invoices (one client's first project each) and one independent invoice; the other approved
     * tasks are left for Request Payment.
     *
     * @param list<Client> $clients @param list<string> $problems
     */
    private function seedInvoices(array $clients, User $actor, array &$problems): int
    {
        $profile = $this->billingProfile();
        $made = 0;
        foreach ([[$clients[0], 'CAD', 2], [$clients[2], 'USD', 1]] as [$client, $currency, $take]) {
            $tasks = array_slice($this->invoices->invoiceableTasks((int) $client->getId(), $currency), 0, $take);
            if ($tasks === []) {
                continue;
            }
            $input = $this->invoices->inputForTasks($client, $currency, array_map(static fn (Task $t) => (int) $t->getId(), $tasks));
            $this->withProfile($input, $profile);
            $made += $this->saved($this->invoices->create(InvoiceKind::Tasks, $input, $actor), 'Invoice', $problems);
        }

        $input = $this->invoices->blankInput();
        $this->withProfile($input, $profile);
        $client = $clients[3];
        $input->clientId = (int) $client->getId();
        $input->currency = 'USD';
        $input->toName = (string) $client->getCompanyName();
        $input->toAddress = '100 Pine Street'."\n".'Seattle, WA';
        $input->toEmail = (string) $client->getEmail();
        $input->dueDate = (new \DateTimeImmutable('+14 days'))->format('Y-m-d');
        $input->description = 'Thank you for your business.';
        $input->lines = [
            new InvoiceLineInput(name: 'Website hosting (12 months)', quantity: '1', rate: '240.00', amount: '240.00'),
            new InvoiceLineInput(name: 'Domain renewal', quantity: '2', rate: '18.00', amount: '36.00'),
        ];
        $made += $this->saved($this->invoices->create(InvoiceKind::Independent, $input, $actor), 'Independent invoice', $problems);

        return $made;
    }

    /** @param list<Client> $clients @param list<Project> $projects @param list<string> $problems */
    private function seedNotes(array $clients, array $projects, User $actor, array &$problems): int
    {
        $firstTask = $this->em->getRepository(Task::class)->findOneBy(['project' => $projects[0]], ['id' => 'ASC']);
        $made = 0;
        foreach ([
            [$clients[0], 'Billing contact', "Send invoices to accounts@ — they pay within 15 days.\nCC the store manager on design work.", true],
            [$projects[0], 'Design feedback round 1', 'Client wants a bolder hero image and fewer homepage sections.', false],
            [$firstTask, 'Assets needed', 'Waiting for product photos from the client.', false],
            [null, 'Monthly checklist', "Send invoices on the 1st\nRecord rent bills\nReview renewing subscriptions", true],
        ] as [$subject, $title, $body, $pinned]) {
            $made += $this->saved($this->notes->create(['title' => self::TEXT_PREFIX.$title, 'body' => $body, 'pinned' => $pinned], $subject, $actor), 'Note', $problems);
        }

        return $made;
    }

    /** @param list<string> $problems @return array{0: int, 1: int, 2: int, 3: int} properties, tenants, bills, payments */
    private function seedRent(User $actor, array &$problems): array
    {
        $counts = [0, 0, 0, 0];
        $months = 3;
        $start = (new \DateTimeImmutable('first day of this month'))->modify(sprintf('-%d months', $months))->format('Y-m-d');
        foreach ([
            ['Flat 2B Green Park', "2B Green Park\nNew Delhi 110016", '15000', '8', 'Ravi Kumar', 'ravi.kumar@demo.example', '1200', [140, 155, 132]],
            ['House 12 Sector 14', "House 12, Sector 14\nGurugram 122001", '22000', '7.5', 'Anita Sharma', 'anita.sharma@demo.example', '500', [210, 236, 198]],
        ] as [$name, $address, $rentAmount, $rate, $tenantName, $email, $meter, $units]) {
            $property = $this->rent->saveProperty(null, ['name' => self::PROPERTY_PREFIX.$name, 'address' => $address, 'defaultRent' => $rentAmount,
                'electricityRate' => $rate, 'isActive' => '1'], $actor);
            if (!$this->saved($property, 'Rent property', $problems)) {
                continue;
            }
            ++$counts[0];
            $tenant = $this->rent->saveTenant(null, ['name' => $tenantName, 'propertyId' => (string) $property->record->getId(), 'email' => $email,
                'phone' => '98100 0000'.$counts[0], 'monthlyRent' => $rentAmount, 'deposit' => (string) (2 * (int) $rentAmount), 'openingMeter' => $meter,
                'startDate' => $start, 'isActive' => '1'], $actor);
            if (!$this->saved($tenant, 'Tenant', $problems)) {
                continue;
            }
            ++$counts[1];
            $tenantEntity = $this->tenants->find($tenant->record->getId());
            foreach ($units as $m => $used) {
                $month = (new \DateTimeImmutable('first day of this month'))->modify(sprintf('-%d months', $months - 1 - $m));
                $defaults = $this->rent->nextBillValues($tenantEntity, $month);
                $previous = (float) ($defaults['meterPrevious'] ?? 0);
                $bill = $this->rent->saveBill($tenantEntity, null, array_merge($defaults, ['period' => $month->format('Y-m'), 'meterCurrent' => (string) ($previous + $used),
                    'otherAmount' => $m === 1 ? '300' : '', 'otherNote' => $m === 1 ? 'Water' : '']), $actor);
                if (!$this->saved($bill, 'Rent bill', $problems)) {
                    continue;
                }
                ++$counts[2];
                // Older months paid in full; the current month left unpaid, so Dues has something to show.
                if ($m < $months - 1) {
                    foreach (['rent' => $rentAmount, 'electricity' => number_format($used * (float) $rate, 2, '.', '')] as $kind => $amount) {
                        $paid = $this->rent->savePayment($tenantEntity, ['kind' => $kind, 'amount' => $amount, 'method' => $kind === 'rent' ? 'bank' : 'upi',
                            'paidOn' => $month->modify('+5 days')->format('Y-m-d'), 'note' => 'Demo payment'], $actor);
                        $counts[3] += $this->saved($paid, 'Rent payment', $problems);
                    }
                }
            }
        }

        return $counts;
    }

    /** @param list<string> $problems */
    private function seedExpenses(User $actor, array &$problems): int
    {
        $categories = [];
        foreach ($this->expenseCategories->findAllOrdered() as $category) {
            $categories[strtolower($category->getName())] = $category->getId();
        }
        $made = 0;
        foreach (self::EXPENSES as [$category, $description, $amount, $method, $daysAgo]) {
            $made += $this->saved($this->expenses->save(null, [
                'spentOn' => (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $daysAgo))->format('Y-m-d'),
                'categoryId' => (string) ($categories[strtolower($category)] ?? ''), 'amount' => $amount, 'method' => $method,
                'description' => self::TEXT_PREFIX.$description,
            ], $actor), 'Expense', $problems);
        }

        return $made;
    }

    /** @param list<string> $problems @return array{0: int, 1: int} */
    private function seedSubscriptions(User $actor, array &$problems): array
    {
        $categories = [];
        foreach ($this->subscriptionCategories->findAllOrdered() as $category) {
            $categories[strtolower($category->getName())] = $category->getId();
        }
        $currencies = $this->subscriptions->currencies();
        $made = 0;
        $payments = 0;
        foreach (self::SUBSCRIPTIONS as [$name, $vendor, $category, $plan, $amount, $currency, $cycle, $status, $method, $card, $renewIn]) {
            if (!in_array($currency, $currencies, true)) {
                $currency = 'USD';
            }
            $result = $this->subscriptions->save(null, [
                'name' => $name, 'vendor' => $vendor, 'categoryId' => (string) ($categories[strtolower($category)] ?? ''), 'plan' => $plan,
                'amount' => $amount, 'currency' => $currency, 'billingCycle' => $cycle, 'status' => $status, 'autoRenew' => '1',
                'startDate' => (new \DateTimeImmutable('-200 days'))->format('Y-m-d'),
                'nextRenewal' => $renewIn !== null ? (new \DateTimeImmutable('today'))->modify(sprintf('%+d days', $renewIn))->format('Y-m-d') : '',
                'accountEmail' => 'ajay@demo.example', 'seats' => '1', 'paymentMethod' => 'HDFC credit card',
                'websiteUrl' => 'https://'.strtolower(str_replace(' ', '', $vendor)).'.example', 'notes' => self::SUBSCRIPTION_TAG.' Demo subscription.',
                'signupName' => 'Ajay Pathak', 'signupMethod' => $method, 'signupMethodNote' => $method === 'other' ? 'Team invite link' : '',
                'accountUsername' => 'ajay.demo', 'cardLast4' => $card, 'billingCompany' => 'Restobox', 'taxId' => '07ABCDE1234F1Z5',
                'billingAddress' => "12 MG Road\nNew Delhi 110001",
            ], $actor);
            if (!$this->saved($result, 'Subscription', $problems)) {
                continue;
            }
            ++$made;
            if ($cycle !== 'one_time' && $status === 'active' && $renewIn !== null && $renewIn > 0) {
                $errors = $this->subscriptions->recordPayment($result->record, ['paidOn' => (new \DateTimeImmutable('-20 days'))->format('Y-m-d'),
                    'amount' => $amount, 'note' => 'Demo renewal'], $actor);
                $errors === [] ? ++$payments : array_push($problems, ...array_map(static fn ($e) => 'Subscription payment: '.$e, $errors));
            }
        }

        return [$made, $payments];
    }

    /** The only active billing profile, or a demo one when there is none (an invoice needs a "billed by"). */
    private function billingProfile(): BillingProfile
    {
        $existing = $this->billingProfiles->findSelectable();
        if ($existing !== []) {
            return $existing[0];
        }
        $profile = (new BillingProfile())->setName(self::BILLING_PROFILE)->setStreetAddress1('12 MG Road')->setCity('New Delhi')
            ->setCountry('India')->setEmail('billing@demo.example')->setPhone('+91 11 4000 0000')->setInvoicePrefix('DEMO')
            ->setNextNumber(1001)->setIsActive(true)->setCreatedAt(time())->setUpdatedAt(time());
        $this->em->persist($profile);
        $this->em->flush();

        return $profile;
    }

    private function withProfile(InvoiceInput $input, BillingProfile $profile): void
    {
        $input->billingProfileId = (int) $profile->getId();
        $input->fromName = $profile->getName();
        $input->fromAddress = $profile->addressText();
        $input->fromEmail = (string) $profile->getEmail();
        $input->fromPhone = (string) $profile->getPhone();
        $input->fromTaxNumber = (string) $profile->getTaxNumber();
    }

    /** @param list<string> $problems */
    private function saved(WriteResult $result, string $what, array &$problems): int
    {
        if ($result->isSaved()) {
            return 1;
        }
        foreach ($result->errors as $error) {
            $problems[] = $what.': '.$error;
        }

        return 0;
    }

    /** @return list<int> */
    private function demoClientIds(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM client WHERE client_code LIKE ?', [self::CLIENT_CODE_PREFIX.'%']));
    }

    /** @return array<string, int> lower-case status name => id, whatever ids the database uses */
    private function statusIds(): array
    {
        $ids = [];
        foreach ($this->connection->fetchAllKeyValue('SELECT id, name FROM task_status') as $id => $name) {
            $key = strtolower(trim((string) $name));
            $ids[$key] = (int) $id;
            if (str_starts_with($key, 'reviewing') && !isset($ids['reviewing'])) {
                $ids['reviewing'] = (int) $id;
            }
        }

        return $ids + ['approved' => TaskStatus::APPROVED_ID, 'paid' => TaskStatus::PAID_ID, 'pending' => TaskStatus::PENDING_ID];
    }
}
