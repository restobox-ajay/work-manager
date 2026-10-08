<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-066 review finding: MySQL matches `victim@example.com\xFF` to the real `victim@example.com` (it cuts the
 * parameter at the first invalid byte, with only a warning), while the throttle and lockout key the attempt under
 * a different identifier — so each `\xFF` variant got a fresh per-account bucket and a correct password still
 * logged in after any number of failures. Invalid UTF-8 input is now refused before the firewall sees it.
 *
 * Requests are sent raw (client->request with parameters), not via submitForm: DomCrawler would re-encode the value.
 */
final class InvalidUtf8LoginInputTest extends WebTestCase
{
    private const EMAIL = 'utf8-victim@example.com';
    private const PASSWORD = 'correct-horse-battery';

    private function conn(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function cleanup(): void
    {
        $this->conn()->executeStatement('DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM "user" WHERE email = ?)', [self::EMAIL]);
        $this->conn()->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        $this->conn()->executeStatement("DELETE FROM login_attempts WHERE email LIKE 'utf8-victim@%'");
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testAnInvalidUtf8VariantOfARealEmailCannotLogInOrOpenAFreshThrottleBucket(): void
    {
        $client = static::createClient();
        $this->cleanup();

        $user = new User();
        $user->setEmail(self::EMAIL);
        $user->setName('UTF-8 Victim');
        $user->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));
        self::getContainer()->get(EntityManagerInterface::class)->persist($user);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach ([1, 2, 5] as $badBytes) {
            $client->request('POST', '/login', ['email' => self::EMAIL . str_repeat("\xFF", $badBytes), 'password' => self::PASSWORD]);

            self::assertResponseStatusCodeSame(400, "an email with {$badBytes} invalid byte(s) must be refused outright");
            self::assertResponseNotHasHeader('Location');
        }

        self::assertSame(0, (int) $this->conn()->fetchOne("SELECT COUNT(*) FROM login_attempts WHERE email LIKE 'utf8-victim@%'"), 'nothing reached the throttle');
        self::assertSame(0, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$user->getId()]), 'no session was opened for the victim');
    }

    public function testInvalidUtf8IsRefusedOnOtherPublicFormsAndInTheQueryStringToo(): void
    {
        $client = static::createClient();

        // The refusal is request-wide, not a login-form special case (the separate admin login is gone, ADR-068).
        $client->request('POST', '/forgot-password', ['email' => "admin@example.com\xFF"]);
        self::assertResponseStatusCodeSame(400);

        $client->request('GET', '/login?next=' . rawurlencode("\xFF"));
        self::assertResponseStatusCodeSame(400);
    }

    public function testValidNonAsciiInputIsNotRefused(): void
    {
        $client = static::createClient();

        // Valid multi-byte UTF-8: an ordinary failed login, not a 400.
        $client->request('POST', '/login', ['email' => 'jörg-ünïcode@example.com', 'password' => 'wrong']);

        self::assertNotSame(400, $client->getResponse()->getStatusCode());
    }
}
