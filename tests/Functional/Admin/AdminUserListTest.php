<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserListTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $this->createTestAdmin('userlist-admin@example.com', 'User List Admin');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                "DELETE FROM \"user\" WHERE email LIKE 'userlist-%@example.com'"
            );
            $admin = $this->em->getRepository(User::class)->findOneBy(['email' => 'userlist-admin@example.com']);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {}
    }

    private function createUser(string $suffix): User
    {
        return $this->createTestUser("userlist-{$suffix}@example.com", "User {$suffix}", 'password', 'active');
    }

    // AC1: GET /admin/users returns a paginated list of users
    public function testUserListRendersSuccessfully(): void
    {
        $this->loginAsAdmin('userlist-admin@example.com');
        $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
    }

    // AC2: Each row shows name, email, status, and role
    public function testEachRowShowsNameEmailStatusAndRole(): void
    {
        $this->createUser('ac2');
        $this->loginAsAdmin('userlist-admin@example.com');
        $crawler = $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();

        $row = $crawler->filter('tbody tr')->reduce(
            static fn ($tr): bool => str_contains($tr->text(), 'userlist-ac2@example.com')
        );
        $this->assertCount(1, $row);
        $cells = $row->filter('td');
        $this->assertSame('User ac2', trim($cells->eq(0)->text()));
        $this->assertSame('userlist-ac2@example.com', trim($cells->eq(1)->text()));
        $this->assertStringContainsString('active', $cells->eq(2)->text());
        // ADR-068: the role column shows the primary role's label.
        $this->assertSame('User', trim($cells->eq(3)->text()));
    }

    // AC3: Unauthenticated or non-admin access is redirected to /login
    public function testUnauthenticatedAccessRedirectsToLogin(): void
    {
        $this->client->request('GET', '/admin/users');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString(
            '/login',
            (string) $this->client->getResponse()->headers->get('Location')
        );
    }

    // AC4: Pagination controls navigate between pages correctly
    public function testPaginationNavigatesBetweenPages(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->createUser(sprintf('page%02d', $i));
        }

        $this->loginAsAdmin('userlist-admin@example.com');

        $this->client->request('GET', '/admin/users?page=1');
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('page=2', $content);

        $this->client->request('GET', '/admin/users?page=2');
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('page=1', $content);
    }
}
