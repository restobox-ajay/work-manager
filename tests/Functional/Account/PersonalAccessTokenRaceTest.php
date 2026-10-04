<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\User;
use App\Bundle\AuthPat\Repository\PersonalAccessTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-119 / review C27, AC2: the personal-access-token per-user cap must not be
 * exceedable by a concurrent create.
 *
 * The `countActiveByUserId >= max` pre-check is racy: two concurrent creates both read a
 * count below the cap and both insert. The fix re-counts INSIDE the create transaction
 * and rolls the insert back when the fresh count exceeds the cap. We reproduce that
 * window deterministically by stubbing the count: the pre-check sees a stale 0 (below the
 * cap), the in-transaction re-count sees the concurrent over-cap value (2), so the guard
 * must roll the just-inserted token back — leaving zero tokens.
 */
final class PersonalAccessTokenRaceTest extends WebTestCase
{
    private const EMAIL = 'patrace@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $user = new User();
        $user->setEmail(self::EMAIL);
        $user->setName('PAT Race User');
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        // Cap = 1 active token per user.
        $this->em->getConnection()->executeStatement(
            "REPLACE INTO config (config_key, config_value) VALUES ('pat.max_tokens_per_user', '1')"
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM personal_access_tokens');
            $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
            $conn->executeStatement("DELETE FROM config WHERE config_key LIKE 'pat.%'");
            $conn->executeStatement('DELETE FROM user_sessions');
        } catch (\Throwable) {
        }
    }

    public function testConcurrentCreateCannotExceedTheCap(): void
    {
        // Partial mock: only the count is faked (stale pre-check, then the over-cap value a
        // concurrent commit would make visible to the in-transaction guard). findActiveByUserId
        // stays REAL so the error re-render queries the live EntityManager — this is what
        // proves the rollback did not close the EM out from under the re-render.
        $mockRepo = $this->getMockBuilder(PersonalAccessTokenRepository::class)
            ->setConstructorArgs([self::getContainer()->get('doctrine')])
            ->onlyMethods(['countActiveByUserId'])
            ->getMock();
        $mockRepo->expects($this->atLeastOnce())
            ->method('countActiveByUserId')
            ->willReturnOnConsecutiveCalls(0, 2);
        self::getContainer()->set(PersonalAccessTokenRepository::class, $mockRepo);

        // Log in.
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            'email'    => self::EMAIL,
            'password' => 'testpassword',
        ]);
        $this->client->followRedirect();

        // Load the tokens page (renders the create form + CSRF) and submit.
        $this->client->request('GET', '/account/tokens');
        $this->client->submitForm('Create Token', ['name' => 'Racing Token']);

        // The guard rolled the insert back: handled error, no token persisted.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM personal_access_tokens'
        );
        $this->assertSame(0, $count, 'The over-cap token must be rolled back, not persisted.');
    }

    /**
     * ADR-066: the same race with a REAL second connection, no stubs. Another process creates a token for this
     * user inside its own transaction (taking the same owner row lock the controller takes) and holds it
     * uncommitted for a moment. InnoDB has no whole-database write lock, so without the controller's
     * `SELECT … FOR UPDATE` on the owner our request would neither wait nor see that uncommitted token: both
     * creates would pass the cap. With it, our request waits, then its re-count sees the committed token.
     */
    public function testACreateRacingARealConcurrentTransactionCannotExceedTheCap(): void
    {
        $userId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM "user" WHERE email = ?', [self::EMAIL]);

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => self::EMAIL, 'password' => 'testpassword']);
        $this->client->followRedirect();
        $this->client->request('GET', '/account/tokens');

        $other = <<<'PHP'
            [$autoload, $databaseUrl, $userId] = array_slice($argv, 1);
            require $autoload;
            $db = (new App\Doctrine\MysqlPdoFactory())->create($databaseUrl);
            $db->beginTransaction();
            $db->prepare('SELECT id FROM "user" WHERE id = ? FOR UPDATE')->execute([$userId]);
            $db->prepare("INSERT INTO personal_access_tokens (user_id, name, token_hash, created_at) VALUES (?, 'Other Request', ?, UTC_TIMESTAMP())")
                ->execute([$userId, hash('sha256', 'other-request')]);
            fwrite(STDOUT, "locked\n");
            fflush(STDOUT);
            usleep(1000000);
            $db->commit();
            PHP;
        $process = proc_open(
            [
                'php', '-r', $other,
                self::getContainer()->getParameter('kernel.project_dir') . '/vendor/autoload.php',
                (string) self::getContainer()->getParameter('app.session.dsn'),
                (string) $userId,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        self::assertSame("locked\n", fgets($pipes[1]), 'the other request must hold its transaction before ours starts');

        $this->client->submitForm('Create Token', ['name' => 'Racing Token']);

        $stderr = stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), 'the other request failed: ' . $stderr);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        self::assertSame(
            ['Other Request'],
            $this->em->getConnection()->fetchFirstColumn('SELECT name FROM personal_access_tokens WHERE user_id = ?', [$userId]),
            'only the first committed token may exist; ours must have been rolled back',
        );
    }
}
