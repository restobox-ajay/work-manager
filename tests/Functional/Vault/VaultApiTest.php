<?php

declare(strict_types=1);

namespace App\Tests\Functional\Vault;

use App\Entity\User;
use App\Entity\Vault\VaultEntry;
use App\Entity\Vault\VaultKey;
use App\Service\Vault\VaultService;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Password Manager's JSON API (ADR-092, ADR-094). The server never sees plaintext, so these requests carry random
 * bytes of the right shape; what is tested is who may store, change, re-key or delete them: CSRF, ownership, the
 * master password's auth key, the iteration floor, version conflicts and the rate-limited reset.
 */
final class VaultApiTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const EMAIL = 'vault-api-admin@example.com';
    private const OTHER_EMAIL = 'vault-api-other@example.com';
    private const PASSWORD = 'testpassword';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
        $this->createTestUser(self::EMAIL, roles: ['ROLE_ADMIN']);
        $this->loginUser(self::EMAIL, self::PASSWORD);
        $crawler = $this->client->request('GET', '/vault');
        self::assertResponseIsSuccessful();
        $this->csrf = (string) $crawler->filter('#vault')->attr('data-csrf');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testThePageIsIsolatedFromItsOpenerAndNeverCached(): void
    {
        $this->client->request('GET', '/vault');

        self::assertResponseHeaderSame('Cross-Origin-Opener-Policy', 'same-origin');
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString("script-src 'self'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testSetUpStoresTheWrappedKeyAndOnlyTheHashOfTheAuthKey(): void
    {
        $auth = random_bytes(32);

        $this->json('POST', '/vault/api/setup', $this->wrapping($auth));

        self::assertResponseIsSuccessful();
        $state = $this->json('GET', '/vault/api/state');
        self::assertTrue($state['setUp']);
        self::assertTrue($state['key']['hasAuth']);
        $stored = $this->em->getConnection()->fetchOne('SELECT auth_hash FROM vault_key');
        self::assertSame(hash('sha256', $auth), $stored, 'Only a hash of the auth key is kept.');
    }

    public function testSetUpRefusesWeakKeySettingsAndASecondVault(): void
    {
        $weak = ['iterations' => VaultService::MIN_ITERATIONS - 1] + $this->wrapping(random_bytes(32));
        $this->json('POST', '/vault/api/setup', $weak);
        self::assertResponseStatusCodeSame(422);

        $this->json('POST', '/vault/api/setup', $this->wrapping(random_bytes(32)));
        self::assertResponseIsSuccessful();
        $this->json('POST', '/vault/api/setup', $this->wrapping(random_bytes(32)));
        self::assertResponseStatusCodeSame(409);
    }

    public function testEveryChangeNeedsTheCsrfToken(): void
    {
        $this->csrf = 'not-the-token';

        $this->json('POST', '/vault/api/setup', $this->wrapping(random_bytes(32)));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_key'));
    }

    public function testCreatingUpdatingAndDeletingAnEntryNeedTheAuthKey(): void
    {
        $auth = $this->setUpVault();

        $this->json('POST', '/vault/api/entries', $this->sealed());
        self::assertResponseStatusCodeSame(403, 'A session without the auth key cannot add entries.');

        $created = $this->json('POST', '/vault/api/entries', $this->sealed(), $auth);
        self::assertResponseStatusCodeSame(201);

        $this->json('PUT', '/vault/api/entries/'.$created['id'], $this->sealed() + ['version' => $created['version']], random_bytes(32));
        self::assertResponseStatusCodeSame(403, 'A wrong auth key cannot overwrite an entry.');
        $this->json('DELETE', '/vault/api/entries/'.$created['id']);
        self::assertResponseStatusCodeSame(403, 'A session without the auth key cannot delete an entry.');
        self::assertSame(1, $this->entryCount());

        $updated = $this->json('PUT', '/vault/api/entries/'.$created['id'], $this->sealed() + ['version' => $created['version']], $auth);
        self::assertResponseIsSuccessful();
        self::assertSame($created['version'] + 1, $updated['version']);

        $this->json('DELETE', '/vault/api/entries/'.$created['id'], null, $auth);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->entryCount());
    }

    public function testAStaleVersionIsAConflict(): void
    {
        $auth = $this->setUpVault();
        $created = $this->json('POST', '/vault/api/entries', $this->sealed(), $auth);
        $this->json('PUT', '/vault/api/entries/'.$created['id'], $this->sealed() + ['version' => $created['version']], $auth);

        $this->json('PUT', '/vault/api/entries/'.$created['id'], $this->sealed() + ['version' => $created['version']], $auth);

        self::assertResponseStatusCodeSame(409);
    }

    public function testAnotherAdminsEntryIsNotFound(): void
    {
        $auth = $this->setUpVault();
        $otherEntryId = $this->otherAdminsEntry();

        $this->json('PUT', '/vault/api/entries/'.$otherEntryId, $this->sealed() + ['version' => 1], $auth);
        self::assertResponseStatusCodeSame(404);
        $this->json('DELETE', '/vault/api/entries/'.$otherEntryId, null, $auth);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_entry WHERE id = ?', [$otherEntryId]));
    }

    public function testChangingTheMasterPasswordReKeysEveryEntryAtOnce(): void
    {
        $auth = $this->setUpVault();
        $first = $this->json('POST', '/vault/api/entries', $this->sealed(), $auth);
        $second = $this->json('POST', '/vault/api/entries', $this->sealed(), $auth);
        $newAuth = random_bytes(32);
        $newKey = $this->wrapping($newAuth);
        $reEncrypted = [
            ['id' => $first['id'], 'version' => $first['version']] + $this->sealed(),
            ['id' => $second['id'], 'version' => $second['version']] + $this->sealed(),
        ];

        $this->json('POST', '/vault/api/master-password', ['key' => $newKey, 'entries' => $reEncrypted], random_bytes(32));
        self::assertResponseStatusCodeSame(403, 'Re-keying needs the CURRENT master password\'s auth key.');

        $this->json('POST', '/vault/api/master-password', ['key' => $newKey, 'entries' => [$reEncrypted[0]]], $auth);
        self::assertResponseStatusCodeSame(409, 'Leaving an entry out would strand it under the old key.');

        $this->json('POST', '/vault/api/master-password', ['key' => $newKey, 'entries' => $reEncrypted], $auth);
        self::assertResponseIsSuccessful();

        $state = $this->json('GET', '/vault/api/state');
        self::assertSame($newKey['wrappedKey'], $state['key']['wrappedKey']);
        $byId = array_column($state['entries'], null, 'id');
        self::assertSame($reEncrypted[0]['ciphertext'], $byId[$first['id']]['ciphertext']);
        self::assertSame($reEncrypted[1]['ciphertext'], $byId[$second['id']]['ciphertext']);

        $this->json('POST', '/vault/api/entries', $this->sealed(), $auth);
        self::assertResponseStatusCodeSame(403, 'The old auth key stops working.');
        $this->json('POST', '/vault/api/entries', $this->sealed(), $newAuth);
        self::assertResponseStatusCodeSame(201);
    }

    public function testAVaultFromBeforeTheAuthKeyRegistersItOnce(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        $this->em->persist((new VaultKey())->setUser($user)->wrap(VaultService::MIN_ITERATIONS, base64_encode(random_bytes(16)), base64_encode(random_bytes(48)), base64_encode(random_bytes(12))));
        $this->em->flush();
        self::assertFalse($this->json('GET', '/vault/api/state')['key']['hasAuth']);
        $auth = random_bytes(32);

        $this->json('POST', '/vault/api/auth', ['auth' => base64_encode($auth)]);
        self::assertResponseIsSuccessful();
        $this->json('POST', '/vault/api/auth', ['auth' => base64_encode(random_bytes(32))]);
        self::assertResponseStatusCodeSame(409, 'A registered auth key cannot be replaced.');

        $this->json('POST', '/vault/api/entries', $this->sealed(), $auth);
        self::assertResponseStatusCodeSame(201);
    }

    public function testResetNeedsTheAccountPasswordAndIsRateLimited(): void
    {
        $auth = $this->setUpVault();
        $this->json('POST', '/vault/api/entries', $this->sealed(), $auth);

        $this->json('POST', '/vault/api/reset', ['password' => 'wrong-password']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->entryCount());

        $this->json('POST', '/vault/api/reset', ['password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->entryCount());
        self::assertFalse($this->json('GET', '/vault/api/state')['setUp']);

        for ($attempt = 0; $attempt < 20 && $this->client->getResponse()->getStatusCode() !== 429; ++$attempt) {
            $this->json('POST', '/vault/api/reset', ['password' => 'wrong-password']);
        }
        self::assertResponseStatusCodeSame(429, 'Guessing the account password through the reset is throttled.');
    }

    /** @return string the raw auth key */
    private function setUpVault(): string
    {
        $auth = random_bytes(32);
        $this->json('POST', '/vault/api/setup', $this->wrapping($auth));
        self::assertResponseIsSuccessful();

        return $auth;
    }

    /** @return array<string, mixed> */
    private function wrapping(string $auth): array
    {
        return ['kdf' => 'PBKDF2-SHA256', 'iterations' => VaultService::MIN_ITERATIONS, 'salt' => base64_encode(random_bytes(16)),
            'wrappedKey' => base64_encode(random_bytes(48)), 'wrapIv' => base64_encode(random_bytes(12)), 'auth' => base64_encode($auth)];
    }

    /** @return array{ciphertext: string, iv: string} */
    private function sealed(): array
    {
        return ['ciphertext' => base64_encode(random_bytes(64)), 'iv' => base64_encode(random_bytes(12))];
    }

    private function otherAdminsEntry(): int
    {
        $other = $this->createTestUser(self::OTHER_EMAIL, roles: ['ROLE_ADMIN']);
        $entry = (new VaultEntry())->setUser($other)->seal(base64_encode(random_bytes(64)), base64_encode(random_bytes(12)));
        $this->em->persist($entry);
        $this->em->flush();

        return (int) $entry->getId();
    }

    private function entryCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM vault_entry e JOIN "user" u ON u.id = e.user_id WHERE u.email = ?', [self::EMAIL]);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $uri, ?array $body = null, ?string $auth = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf];
        if ($auth !== null) {
            $server['HTTP_X_VAULT_AUTH'] = base64_encode($auth);
        }
        $this->client->request($method, $uri, [], [], $server, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $users = 'SELECT id FROM "user" WHERE email IN (?, ?)';
        $conn->executeStatement("DELETE FROM vault_entry WHERE user_id IN ($users)", [self::EMAIL, self::OTHER_EMAIL]);
        $conn->executeStatement("DELETE FROM vault_key WHERE user_id IN ($users)", [self::EMAIL, self::OTHER_EMAIL]);
        $conn->executeStatement('DELETE FROM "user" WHERE email IN (?, ?)', [self::EMAIL, self::OTHER_EMAIL]);
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
        $conn->executeStatement("DELETE FROM audit_log WHERE action LIKE 'vault.%'");
        $this->em->clear();
    }
}
