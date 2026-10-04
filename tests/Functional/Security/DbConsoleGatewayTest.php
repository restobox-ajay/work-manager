<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\ConsoleCookie;
use App\Tests\Support\ScratchDatabase;
use PHPUnit\Framework\TestCase;

/**
 * ADR-053: drives the REAL public/db-admin.php over actual HTTP, via PHP's built-in web server.
 *
 * The gateway's whole authorisation decision lives inline in that one file on purpose — it must run
 * without the Symfony container — so this exercises the file itself rather than a stand-in for its
 * logic. Every one of the seven checks gets its own test, each isolated so a failure names the check
 * that broke: only the condition under test is made to fail, everything else is left valid.
 *
 * Authorisation is observed through the response: a denial is a 302 to /admin/login; an authorised load
 * reaches the hand-off, which — until a MySQL console tool is chosen (ADR-066) — is a 503 page saying so.
 */
final class DbConsoleGatewayTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 8931;

    /** Shown only once every check has passed — the observable proof a request was authorised. */
    private const AUTHORISED_MARKER = 'Access was authorised';

    private string $projectRoot;
    private ScratchDatabase $database;
    private \PDO $pdo;
    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);

        // A dedicated database holding only the tables the gateway touches, created fresh so this
        // never depends on what another suite left behind.
        $this->database = ScratchDatabase::create('dbconsole');
        $this->pdo = $this->database->pdo();
        $this->pdo->exec('CREATE TABLE config (id INT AUTO_INCREMENT PRIMARY KEY, config_key VARCHAR(255), config_value LONGTEXT)');
        $this->pdo->exec('CREATE TABLE `admin` (id INT PRIMARY KEY, email VARCHAR(180), roles LONGTEXT, status VARCHAR(20))');
        $this->pdo->exec('CREATE TABLE db_console_session (id INT AUTO_INCREMENT PRIMARY KEY, token_hash VARCHAR(64), admin_id INT, expires_at DATETIME, ip_address VARCHAR(45), created_at DATETIME)');
        $this->pdo->exec('CREATE TABLE db_console_throttle (ip_address VARCHAR(45) PRIMARY KEY, window_start INT, attempts INT)');

        // The happy-path world: console armed, an active tech-support admin.
        $this->arm(time() + 3600);
        $this->pdo->exec("INSERT INTO admin (id, email, roles, status) VALUES (1, 'ts@example.com', '[\"ROLE_TECH_SUPPORT\"]', 'active')");

        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        $this->database->drop();
    }

    // ---------------------------------------------------------------- helpers

    private function startServer(): void
    {
        $env = [
            'APP_ENV' => 'dev',
            'DATABASE_URL' => $this->database->url,
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];
        if ($this->allowedIps !== null) {
            $env['DB_CONSOLE_ALLOWED_IPS'] = $this->allowedIps;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $this->server = proc_open(
            sprintf('exec php -S %s:%d -t public public/db-admin.php', self::HOST, self::PORT),
            $descriptors,
            $pipes,
            $this->projectRoot,
            $env,
        );

        // Wait for the port to accept connections rather than sleeping a fixed amount.
        for ($i = 0; $i < 100; ++$i) {
            $conn = @fsockopen(self::HOST, self::PORT, $errno, $errstr, 0.1);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            usleep(50_000);
        }

        self::fail('the built-in server never came up');
    }

    private function stopServer(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
    }

    private ?string $allowedIps = null;

    /** Restart the server with DB_CONSOLE_ALLOWED_IPS set, since env is fixed at spawn time. */
    private function restartWithAllowedIps(string $ips): void
    {
        $this->stopServer();
        $this->allowedIps = $ips;
        $this->startServer();
    }

    private function arm(int $until): void
    {
        $this->pdo->exec('DELETE FROM config WHERE config_key = ' . $this->pdo->quote(ConsoleCookie::ENABLED_UNTIL_KEY));
        $stmt = $this->pdo->prepare('INSERT INTO config (config_key, config_value) VALUES (:k, :v)');
        $stmt->execute(['k' => ConsoleCookie::ENABLED_UNTIL_KEY, 'v' => (string) $until]);
    }

    /** Insert a console session. Defaults are all valid; pass overrides to break exactly one thing. */
    private function openSession(
        string $token,
        ?string $expiresAt = null,
        string $ip = self::HOST,
        int $adminId = 1,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO db_console_session (token_hash, admin_id, expires_at, ip_address, created_at)
             VALUES (:h, :a, :e, :ip, :c)'
        );
        $stmt->execute([
            'h'  => ConsoleCookie::hashToken($token),
            'a'  => $adminId,
            'e'  => $expiresAt ?? date('Y-m-d H:i:s', time() + 1800),
            'ip' => $ip,
            'c'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, string> $extraCookies
     *
     * @return array{status:int,location:?string,body:string,setCookies:list<string>}
     */
    private function get(?string $token, array $extraCookies = []): array
    {
        $headers = ['Host: ' . self::HOST];
        $cookies = $extraCookies;
        if ($token !== null) {
            $cookies[ConsoleCookie::COOKIE_NAME] = $token;
        }
        if ($cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(static fn (string $k, string $v): string => $k . '=' . $v, array_keys($cookies), $cookies));
        }

        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);

        $body = @file_get_contents(sprintf('http://%s:%d/db-admin.php', self::HOST, self::PORT), false, $ctx);
        $status = 0;
        $location = null;
        $setCookies = [];
        foreach ($http_response_header ?? [] as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                $setCookies[] = trim(substr($h, 11));
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int) $m[1];
            }
            if (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }

        return ['status' => $status, 'location' => $location, 'body' => (string) $body, 'setCookies' => $setCookies];
    }

    private function assertDenied(?string $token, string $because): void
    {
        $r = $this->get($token);
        self::assertSame('/admin/login', $r['location'], $because);
        self::assertStringNotContainsString(self::AUTHORISED_MARKER, $r['body'], 'the console hand-off must not be reached: ' . $because);
    }

    private function assertAuthorised(string $token): void
    {
        $r = $this->get($token);
        self::assertNull($r['location'], 'an authorised load must not redirect');
        self::assertSame(503, $r['status'], 'no MySQL console tool is installed yet, so the hand-off says so');
        self::assertStringContainsString(self::AUTHORISED_MARKER, $r['body'], 'the console hand-off should have been reached');
    }

    private function attempts(): int
    {
        return (int) $this->pdo->query(
            'SELECT attempts FROM db_console_throttle WHERE ip_address = ' . $this->pdo->quote(self::HOST)
        )->fetchColumn();
    }

    // ---------------------------------------------------------------- the happy path

    // Issue #47: the console must never touch the panel's session cookie — not adopt it, not overwrite it.
    public function testAnAuthorisedLoadNeverTouchesThePanelSessionCookie(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);

        $r = $this->get($token, ['PHPSESSID' => 'panelsessionid0123456789abcdef']);

        self::assertStringContainsString(self::AUTHORISED_MARKER, $r['body'], 'precondition: the request was authorised');
        foreach ($r['setCookies'] as $cookie) {
            self::assertStringStartsNotWith('PHPSESSID=', $cookie, 'the panel session cookie must never be touched');
        }
    }

    // Issue #43: the gateway runs outside the kernel, so nothing but its own code decides how it connects. It must
    // use the project's factory (the one connection baseline, ADR-056/061/063), never a bare PDO. A connection's
    // session settings are invisible over HTTP, so this pins the wiring in the file itself.
    public function testTheGatewayConnectsThroughTheProjectFactoryOnly(): void
    {
        $source = (string) file_get_contents($this->projectRoot . '/public/db-admin.php');

        self::assertStringContainsString('(new MysqlPdoFactory())->create(', $source);
        $barePdo = '/new\s+\\\\?PDO\s*\(/';
        self::assertMatchesRegularExpression($barePdo, '$pdo = new \PDO($dsn);', 'control: the pattern must catch a bare PDO');
        self::assertDoesNotMatchRegularExpression($barePdo, $source, 'a bare PDO would skip the connection baseline');
    }

    public function testValidTokenIsAuthorised(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);

        $this->assertAuthorised($token);
    }

    // ---------------------------------------------------------------- check 1: kill-switch

    public function testDisarmedConsoleDeniesEvenAValidToken(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);
        $this->arm(0);

        $this->assertDenied($token, 'the kill-switch must beat a valid token');
    }

    public function testLapsedArmingWindowDenies(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);
        $this->arm(time() - 1);

        $this->assertDenied($token, 'an elapsed arming deadline must deny');
    }

    public function testMissingSwitchRowReadsAsOff(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);
        $this->pdo->exec('DELETE FROM config');

        $this->assertDenied($token, 'an absent switch row must read as OFF, not ON');
    }

    /** The kill-switch is checked before the throttle, so disarming must not burn attempts. */
    public function testKillSwitchDenialIsNotCountedAsAFailedLogin(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);
        $this->arm(0);

        $this->get($token);

        self::assertSame(0, $this->attempts(), 'a kill-switch denial is not a failed login');
    }

    // ---------------------------------------------------------------- check 4: the token

    public function testAbsentCookieDenies(): void
    {
        $this->assertDenied(null, 'no cookie must deny');
    }

    public function testEmptyTokenDenies(): void
    {
        $this->assertDenied('', 'an empty token must deny');
    }

    public function testUnknownTokenDeniesEvenWhenALiveSessionExists(): void
    {
        $this->openSession(ConsoleCookie::generateToken());

        $this->assertDenied(ConsoleCookie::generateToken(), 'a token with no row must deny');
    }

    /** The stored form is a hash; presenting the hash itself must not authenticate. */
    public function testPresentingTheStoredHashDoesNotAuthenticate(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);

        $this->assertDenied(ConsoleCookie::hashToken($token), 'the stored hash is not a usable credential');
    }

    // ---------------------------------------------------------------- check 5: expiry

    public function testExpiredSessionDenies(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token, date('Y-m-d H:i:s', time() - 1));

        $this->assertDenied($token, 'an expired session must deny');
    }

    public function testExpiredSessionRowIsDeleted(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token, date('Y-m-d H:i:s', time() - 1));

        $this->get($token);

        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM db_console_session')->fetchColumn(),
            'an expired row must be cleaned up, not left to accumulate',
        );
    }

    // ---------------------------------------------------------------- check 6: IP binding

    public function testSessionOpenedFromAnotherIpDenies(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token, null, '203.0.113.9');

        $this->assertDenied($token, 'a token replayed from a different IP must deny');
    }

    // ---------------------------------------------------------------- check 7: live account state

    public function testDeletedAdminDenies(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);
        $this->pdo->exec('DELETE FROM admin WHERE id = 1');

        $this->assertDenied($token, 'a session whose admin is gone must deny');
    }

    public function testDeactivatedAdminDenies(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);
        $this->pdo->exec("UPDATE admin SET status = 'inactive' WHERE id = 1");

        $this->assertDenied($token, 'a deactivated admin must lose the console immediately');
    }

    public function testAdminWhoLostTechSupportDenies(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);
        $this->pdo->exec("UPDATE admin SET roles = '[\"ROLE_ADMIN\",\"ROLE_SUPER_ADMIN\"]' WHERE id = 1");

        $this->assertDenied($token, 'ROLE_SUPER_ADMIN is not enough — the console is tech-support only');
    }

    public function testMalformedRolesJsonDenies(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);
        $this->pdo->exec("UPDATE admin SET roles = 'not-json' WHERE id = 1");

        $this->assertDenied($token, 'unparseable roles must fail closed, not open');
    }

    // ---------------------------------------------------------------- check 2: throttling

    public function testFailedLoadsAccumulateAndThenLockTheIpOut(): void
    {
        $bad = ConsoleCookie::generateToken();

        for ($i = 1; $i <= 5; ++$i) {
            $this->get($bad);
            self::assertSame($i, $this->attempts(), "attempt {$i} should have been counted");
        }

        // Sixth request: the IP is now over the limit, so even a genuinely valid token is refused.
        $good = ConsoleCookie::generateToken();
        $this->openSession($good);

        $this->assertDenied($good, 'once the IP is locked out, even a valid token must be refused');
    }

    public function testASuccessfulLoadClearsTheIpCounter(): void
    {
        $bad = ConsoleCookie::generateToken();
        $this->get($bad);
        $this->get($bad);
        self::assertSame(2, $this->attempts());

        $good = ConsoleCookie::generateToken();
        $this->openSession($good);
        $this->assertAuthorised($good);

        self::assertSame(0, $this->attempts(), 'a successful load must clear the failed-login counter');
    }

    public function testAStaleThrottleWindowDoesNotLockOutForever(): void
    {
        // A window that started longer ago than the window length must not still be blocking.
        $stmt = $this->pdo->prepare(
            'INSERT INTO db_console_throttle (ip_address, window_start, attempts) VALUES (:ip, :ws, 99)'
        );
        $stmt->execute(['ip' => self::HOST, 'ws' => time() - 3600]);

        $token = ConsoleCookie::generateToken();
        $this->openSession($token);

        $this->assertAuthorised($token);
    }

    // ---------------------------------------------------------------- check 3: IP allowlist

    public function testIpOutsideTheAllowlistDeniesEvenWithAValidToken(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);
        $this->restartWithAllowedIps('203.0.113.0/24');

        $this->assertDenied($token, 'an IP off the allowlist must be refused outright');
    }

    public function testIpInsideTheAllowlistIsAuthorised(): void
    {
        $token = ConsoleCookie::generateToken();
        $this->openSession($token);
        $this->restartWithAllowedIps('127.0.0.1/32, 203.0.113.0/24');

        $this->assertAuthorised($token);
    }

    // ---------------------------------------------------------------- sliding expiry

    public function testActivitySlidesTheExpiryForward(): void
    {
        $token = ConsoleCookie::generateToken();
        $soon = date('Y-m-d H:i:s', time() + 60);
        $this->openSession($token, $soon);

        $this->assertAuthorised($token);

        $after = (string) $this->pdo->query('SELECT expires_at FROM db_console_session LIMIT 1')->fetchColumn();
        self::assertGreaterThan(
            strtotime($soon),
            strtotime($after),
            'an authorised load must push the expiry forward so the console closes on idle, not on a fixed clock',
        );
    }
}
