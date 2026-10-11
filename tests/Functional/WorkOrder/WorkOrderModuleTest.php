<?php

declare(strict_types=1);

namespace App\Tests\Functional\WorkOrder;

use App\Entity\Attachment;
use App\Entity\WorkOrder\WorkOrder;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * ADR-125: work orders — create with a number, documents stored privately and served only to admins, refused file
 * types, milestones, convert to project, and delete taking milestones and files with it.
 */
final class WorkOrderModuleTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const ADMIN = 'wo-admin@example.com';
    private const USER = 'wo-user@example.com';
    private const TITLE = 'WOTest ';
    private const CLIENT = 'WOTest Client';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $tmp;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tmp = sys_get_temp_dir().'/wo-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
        $this->cleanup();
        $this->createTestUser(self::ADMIN, roles: ['ROLE_ADMIN']);
        $this->createTestUser(self::USER);
        $this->em->getConnection()->insert('client', ['name' => self::CLIENT, 'is_active' => 1, 'is_deleted' => 0, 'created_at' => time(), 'updated_at' => time()]);
        $this->loginUser(self::ADMIN);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        array_map('unlink', glob($this->tmp.'/*') ?: []);
        @rmdir($this->tmp);
        parent::tearDown();
    }

    public function testCreatingAWorkOrderNumbersItAndShowsItsDetails(): void
    {
        $id = $this->create('Website build', ['value' => '250,000.50', 'advanceReceived' => '100000', 'paymentTerms' => '40% advance']);

        $page = $this->client->request('GET', "/work-order/$id");
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/WO-\d{4}-\d{3}/', $page->filter('.eyebrow')->text());
        self::assertStringContainsString('₹250,000.50', $page->filter('.kpis')->text());
        self::assertStringContainsString('To receive ₹150,000.50', $page->filter('.kpis')->text());
    }

    public function testInvalidValuesAreRefused(): void
    {
        $this->client->request('GET', '/work-order/new');
        $this->client->submitForm('Create work order', ['title' => self::TITLE.'Bad', 'value' => 'lots', 'startDate' => '2026-10-20', 'endDate' => '2026-10-01']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert', 'Contract value must be a number');
        self::assertSelectorTextContains('.alert', 'The end date cannot be before the start date.');
    }

    public function testDocumentsAreStoredPrivatelyAndServedToAdminsOnly(): void
    {
        $id = $this->create('Docs');
        $this->upload($id, [$this->file('contract.pdf', "%PDF-1.4\n%%EOF\n"), $this->file('notes.txt', 'plain notes')], 'Signed contract');

        $files = $this->attachments($id);
        self::assertCount(2, $files);
        self::assertSame('Signed contract', $files[0]->getLabel());
        $dir = self::getContainer()->getParameter('app.upload_dir');
        foreach ($files as $file) {
            self::assertFileExists($dir.'/'.$file->getStoredName());
            self::assertStringNotContainsString('public', $dir, 'Files are kept outside the web root.');
        }

        $pdf = $this->find($files, 'contract.pdf');
        $this->client->request('GET', '/attachment/'.$pdf->getId());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('inline;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $txt = $this->find($files, 'notes.txt');
        $this->client->request('GET', '/attachment/'.$txt->getId());
        self::assertResponseHeaderSame('Content-Type', 'application/octet-stream');
        self::assertStringStartsWith('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');

        $this->client->request('GET', '/logout');
        $this->loginUser(self::USER);
        $this->client->request('GET', '/attachment/'.$pdf->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testScriptsAndDisguisedPagesAreRefused(): void
    {
        $id = $this->create('Refusals');
        $this->upload($id, [$this->file('shell.php', '<?php echo 1;'), $this->file('page.pdf', '<html><script>alert(1)</script></html>'), $this->file('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')]);

        self::assertCount(0, $this->attachments($id));
        $alerts = implode(' ', $this->client->followRedirect()->filter('.alert')->each(static fn ($node) => $node->text()));
        self::assertStringContainsString('"shell.php": this type of file is not allowed', $alerts);
        self::assertStringContainsString('"page.pdf" does not look like a document or picture', $alerts);
        self::assertStringContainsString('"logo.svg": this type of file is not allowed', $alerts);
    }

    public function testMilestonesCanBeAddedAndTicked(): void
    {
        $id = $this->create('Milestones');
        $page = $this->client->request('GET', "/work-order/$id");
        $this->client->submit($page->selectButton('Add milestone')->form(['title' => 'Design approved', 'dueDate' => '2026-01-10', 'amount' => '5000']));
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Overdue', $page->filter('#milestones')->text());

        $this->client->submit($page->filter('#milestones button[title="Mark done"]')->form());
        $page = $this->client->followRedirect();
        self::assertStringContainsString('1 / 1', $page->filter('.kpis')->text());
    }

    public function testConvertingCreatesAProjectForTheLinkedClient(): void
    {
        $clientId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM client WHERE name = ?', [self::CLIENT]);
        $id = $this->create('Convert me', ['clientId' => (string) $clientId]);

        $page = $this->client->request('GET', "/work-order/$id");
        $this->client->submit($page->selectButton('Convert to project')->form());

        self::assertResponseRedirects();
        $this->em->clear();
        $workOrder = $this->em->find(WorkOrder::class, $id);
        self::assertNotNull($workOrder?->getProject());
        self::assertSame(self::TITLE.'Convert me', $workOrder->getProject()->getName());
        self::assertSame($clientId, $workOrder->getProject()->getClient()?->getId());
    }

    public function testConvertingWithoutAClientIsRefused(): void
    {
        $id = $this->create('No client', ['clientName' => 'Someone new']);
        $page = $this->client->request('GET', "/work-order/$id");
        $token = $page->filter('form[action$="/convert"] input[name="_token"]')->attr('value');
        $this->client->request('POST', "/work-order/$id/convert", ['_token' => $token]);

        $page = $this->client->followRedirect();
        self::assertStringContainsString('Link a client', $page->filter('.alert')->text());
        $this->em->clear();
        self::assertNull($this->em->find(WorkOrder::class, $id)?->getProject());
    }

    public function testDeletingRemovesItsMilestonesAndFiles(): void
    {
        $id = $this->create('Delete me');
        $this->upload($id, [$this->file('a.pdf', "%PDF-1.4\n%%EOF\n")]);
        $stored = $this->attachments($id)[0]->getStoredName();
        $page = $this->client->request('GET', "/work-order/$id");
        $this->client->submit($page->selectButton('Add milestone')->form(['title' => 'One']));

        $page = $this->client->request('GET', "/work-order/$id");
        $this->client->submit($page->selectButton('Delete work order')->form());

        self::assertResponseRedirects('/work-order');
        $this->em->clear();
        self::assertNull($this->em->find(WorkOrder::class, $id));
        self::assertSame([], $this->attachments($id));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM work_order_milestone WHERE work_order_id = ?', [$id]));
        self::assertFileDoesNotExist(self::getContainer()->getParameter('app.upload_dir').'/'.$stored);
    }

    public function testOnlyAdminsReachWorkOrders(): void
    {
        $this->client->request('GET', '/logout');
        $this->loginUser(self::USER);
        $this->client->request('GET', '/work-order');

        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, string> $fields */
    private function create(string $title, array $fields = []): int
    {
        $this->client->request('GET', '/work-order/new');
        $this->client->submitForm('Create work order', ['title' => self::TITLE.$title, 'currency' => 'INR'] + $fields);
        self::assertResponseRedirects();

        return (int) $this->em->getConnection()->fetchOne('SELECT id FROM work_order WHERE title = ?', [self::TITLE.$title]);
    }

    /** @param list<UploadedFile> $files */
    private function upload(int $id, array $files, string $label = ''): void
    {
        $page = $this->client->request('GET', "/work-order/$id");
        $token = $page->filter('#files input[name="_token"]')->attr('value');
        $this->client->request('POST', "/work-order/$id/files", ['_token' => $token, 'label' => $label], ['files' => $files]);
        self::assertResponseRedirects();
    }

    private function file(string $name, string $content): UploadedFile
    {
        file_put_contents($this->tmp.'/'.$name, $content);

        return new UploadedFile($this->tmp.'/'.$name, $name, null, null, true);
    }

    /** @return Attachment[] */
    private function attachments(int $id): array
    {
        $this->em->clear();

        return $this->em->getRepository(Attachment::class)->findBy(['ownerType' => Attachment::OWNER_WORK_ORDER, 'ownerId' => $id], ['id' => 'ASC']);
    }

    /** @param Attachment[] $files */
    private function find(array $files, string $name): Attachment
    {
        foreach ($files as $file) {
            if ($file->getOriginalName() === $name) {
                return $file;
            }
        }
        self::fail("No attachment $name");
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $ids = $conn->fetchFirstColumn('SELECT id FROM work_order WHERE title LIKE ?', [self::TITLE.'%']);
        $dir = self::getContainer()->getParameter('app.upload_dir');
        foreach ($ids === [] ? [] : $conn->fetchFirstColumn('SELECT stored_name FROM attachment WHERE owner_type = ? AND owner_id IN ('.implode(',', array_map('intval', $ids)).')', [Attachment::OWNER_WORK_ORDER]) as $stored) {
            @unlink($dir.'/'.$stored);
        }
        if ($ids !== []) {
            $conn->executeStatement('DELETE FROM attachment WHERE owner_type = ? AND owner_id IN ('.implode(',', array_map('intval', $ids)).')', [Attachment::OWNER_WORK_ORDER]);
        }
        $conn->executeStatement('DELETE FROM work_order WHERE title LIKE ?', [self::TITLE.'%']);
        $conn->executeStatement('DELETE FROM project WHERE client_id IN (SELECT id FROM client WHERE name = ?)', [self::CLIENT]);
        $conn->executeStatement('DELETE FROM client WHERE name = ?', [self::CLIENT]);
        $conn->executeStatement('DELETE FROM user WHERE email IN (?, ?)', [self::ADMIN, self::USER]);
    }
}
