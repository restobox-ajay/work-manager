<?php

declare(strict_types=1);

namespace App\Service\Vault;

use App\Entity\User;
use App\Entity\Vault\VaultEntry;
use App\Entity\Vault\VaultKey;
use App\Repository\Vault\VaultEntryRepository;
use App\Repository\Vault\VaultKeyRepository;
use App\Security\EndpointRateLimiterInterface;
use App\Service\WorkAuditTrail;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The Password Manager's server side (ADR-092). It only ever stores and returns opaque, browser-encrypted blobs for
 * the signed-in admin: it checks their shape and size, keeps each admin to their own vault and audits what changed
 * (never the content). It cannot decrypt anything — there is no key here to do it with.
 *
 * Anything that changes the vault must also prove the auth key the browser derives from the master password
 * (ADR-094), so a hijacked session — or script injected into another page of the app — cannot overwrite, delete or
 * re-key entries. The auth key cannot decrypt anything either: it is a separate HKDF output of the master key.
 */
final class VaultService
{
    /** PBKDF2-SHA256 iterations: OWASP's 2023 minimum is 600,000. The browser picks; we refuse weaker. */
    public const MIN_ITERATIONS = 600_000;
    private const MAX_ITERATIONS = 10_000_000;
    private const SALT_BYTES = 16;
    private const IV_BYTES = 12;
    private const AUTH_KEY_BYTES = 32;
    /** 32-byte AES-256 vault key + 16-byte GCM tag. */
    private const WRAPPED_KEY_BYTES = 48;
    /** One entry's ciphertext; generous for notes, small enough to stop the table being used as file storage. */
    private const MAX_ENTRY_BYTES = 64 * 1024;
    private const MAX_ENTRIES = 5000;
    private const RESET_RATE_ACTION = 'vault_reset';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VaultKeyRepository $keys,
        private readonly VaultEntryRepository $entries,
        private readonly WorkAuditTrail $audit,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EndpointRateLimiterInterface $rateLimiter,
    ) {
    }

    /**
     * Everything the browser needs to unlock and show the vault: the wrapped key and every encrypted entry.
     *
     * @return array{setUp: bool, key: ?array{kdf: string, iterations: int, salt: string, wrappedKey: string, wrapIv: string, hasAuth: bool}, entries: list<array<string, mixed>>}
     */
    public function state(User $user): array
    {
        $key = $this->keys->findForUser($user);

        return [
            'setUp'   => $key !== null,
            'key'     => $key === null ? null : ['kdf' => $key->getKdf(), 'iterations' => $key->getIterations(), 'salt' => $key->getSalt(),
                'wrappedKey' => $key->getWrappedKey(), 'wrapIv' => $key->getWrapIv(), 'hasAuth' => $key->hasAuth()],
            'entries' => $key === null ? [] : array_map($this->entryJson(...), $this->entries->findForUser($user)),
        ];
    }

    /** First use: stores the wrapped vault key and the auth key's hash. Refused once a vault exists. */
    public function setUp(User $user, mixed $data): void
    {
        if ($this->keys->findForUser($user) !== null) {
            throw VaultRequestRefused::conflict('Your vault is already set up. Reload the page.');
        }
        [$iterations, $salt, $wrappedKey, $wrapIv] = $this->wrapping($data);
        $key = (new VaultKey())->setUser($user)->wrap($iterations, $salt, $wrappedKey, $wrapIv)->setAuth($this->authKey($data));
        $this->em->persist($key);
        $this->em->flush();
        $this->audit->record($user, 'vault.setup', 'Password Manager vault created');
    }

    /**
     * A vault created before ADR-094 has no auth key yet: the browser registers it right after its first unlock. Only
     * possible while none is stored, so it cannot be used to replace a registered one.
     */
    public function registerAuth(User $user, mixed $data): void
    {
        $key = $this->requireKey($user);
        if ($key->hasAuth()) {
            throw VaultRequestRefused::conflict('Your vault is already protected. Reload the page.');
        }
        $key->setAuth($this->authKey($data));
        $this->em->flush();
        $this->audit->record($user, 'vault.auth_registered', 'Password Manager auth key registered');
    }

    /**
     * New master password: a NEW vault key, wrapped under it, with every entry re-encrypted under that key — all in
     * one transaction. A fresh key (rather than the old one wrapped again) means an old backup of the wrapped key plus
     * a leaked old master password no longer opens today's entries.
     *
     * @param mixed $data {key: {kdf, iterations, salt, wrappedKey, wrapIv, auth}, entries: list<{id, version, ciphertext, iv}>}
     */
    public function rekey(User $user, ?string $auth, mixed $data): void
    {
        if (!is_array($data) || !is_array($data['entries'] ?? null) || !array_is_list($data['entries'])) {
            throw VaultRequestRefused::invalid('Nothing to save.');
        }
        [$iterations, $salt, $wrappedKey, $wrapIv] = $this->wrapping($data['key'] ?? null);
        $newAuthKey = $this->authKey($data['key']);

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            // Row lock: two tabs changing the master password at once are serialised, and the loser fails the auth check.
            $key = $this->requireAuth($user, $auth, LockMode::PESSIMISTIC_WRITE);
            $owned = [];
            foreach ($this->entries->findForUser($user) as $entry) {
                $owned[$entry->getId()] = $entry;
            }
            if (count($data['entries']) !== count($owned)) {
                throw VaultRequestRefused::conflict('Your vault changed in another tab. Reload the vault and try again.');
            }
            foreach ($data['entries'] as $row) {
                $entry = is_array($row) && is_int($row['id'] ?? null) ? ($owned[$row['id']] ?? null) : null;
                if ($entry === null || ($row['version'] ?? null) !== $entry->getVersion()) {
                    throw VaultRequestRefused::conflict('Your vault changed in another tab. Reload the vault and try again.');
                }
                unset($owned[$row['id']]); // an id listed twice is then "unknown" the second time
                [$ciphertext, $iv] = $this->sealed($row);
                $entry->seal($ciphertext, $iv);
            }
            $key->wrap($iterations, $salt, $wrappedKey, $wrapIv)->setAuth($newAuthKey);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();
            $this->em->clear();

            throw $failure;
        }
        $this->audit->record($user, 'vault.master_password', sprintf('Password Manager master password changed, vault re-keyed (%d entries)', count($data['entries'])));
    }

    /** @return array<string, mixed> the saved entry */
    public function create(User $user, ?string $auth, mixed $data): array
    {
        $this->requireAuth($user, $auth);
        if (count($this->entries->findForUser($user)) >= self::MAX_ENTRIES) {
            throw VaultRequestRefused::invalid(sprintf('A vault holds at most %d entries.', self::MAX_ENTRIES));
        }
        [$ciphertext, $iv] = $this->sealed($data);
        $entry = (new VaultEntry())->setUser($user)->seal($ciphertext, $iv);
        $this->em->persist($entry);
        $this->em->flush();
        $this->audit->record($user, 'vault.entry_create', sprintf('Vault entry #%d', $entry->getId()));

        return $this->entryJson($entry);
    }

    /** @return array<string, mixed> the saved entry */
    public function update(User $user, ?string $auth, int $id, mixed $data): array
    {
        $this->requireAuth($user, $auth);
        $entry = $this->entries->findOwned($id, $user) ?? throw VaultRequestRefused::notFound();
        if (!is_array($data) || ($data['version'] ?? null) !== $entry->getVersion()) {
            throw VaultRequestRefused::conflict('This entry was changed somewhere else. Reload the vault and try again.');
        }
        [$ciphertext, $iv] = $this->sealed($data);
        $entry->seal($ciphertext, $iv);
        $this->em->flush();
        $this->audit->record($user, 'vault.entry_update', sprintf('Vault entry #%d', $id));

        return $this->entryJson($entry);
    }

    public function delete(User $user, ?string $auth, int $id): void
    {
        $this->requireAuth($user, $auth);
        $entry = $this->entries->findOwned($id, $user) ?? throw VaultRequestRefused::notFound();
        $this->em->remove($entry);
        $this->em->flush();
        $this->audit->record($user, 'vault.entry_delete', sprintf('Vault entry #%d', $id));
    }

    /**
     * Forgotten master password: the only way out is to delete the vault and start again. Needs the account password,
     * and attempts are rate-limited so this cannot be used to guess that password from a hijacked session.
     *
     * @return int the number of entries deleted
     */
    public function reset(User $user, mixed $password): int
    {
        if ($this->rateLimiter->tooManyAttempts(self::RESET_RATE_ACTION, 'user:'.$user->getId())) {
            throw VaultRequestRefused::tooManyAttempts();
        }
        if (!is_string($password) || $password === '' || !$this->hasher->isPasswordValid($user, $password)) {
            $this->audit->record($user, 'vault.reset_refused', 'Password Manager vault reset refused: wrong account password');

            throw VaultRequestRefused::invalid('That is not your account password.');
        }
        $count = $this->entries->deleteForUser($user);
        if (($key = $this->keys->findForUser($user)) !== null) {
            $this->em->remove($key);
        }
        $this->em->flush();
        $this->audit->record($user, 'vault.reset', sprintf('Password Manager vault deleted (%d entries)', $count));

        return $count;
    }

    private function requireKey(User $user): VaultKey
    {
        return $this->keys->findForUser($user) ?? throw VaultRequestRefused::conflict('Set up your vault first. Reload the page.');
    }

    /** The vault key row, once the request proves the auth key (sent base64 in a header by the unlocked page). */
    private function requireAuth(User $user, ?string $auth, ?LockMode $lock = null): VaultKey
    {
        $key = $this->requireKey($user);
        if ($lock !== null) {
            $this->em->refresh($key, $lock);
        }
        $authKey = is_string($auth) ? base64_decode($auth, true) : false;
        if ($authKey === false || strlen($authKey) !== self::AUTH_KEY_BYTES || !$key->authMatches($authKey)) {
            throw VaultRequestRefused::locked();
        }

        return $key;
    }

    /** @return array{0: int, 1: string, 2: string, 3: string} */
    private function wrapping(mixed $data): array
    {
        if (!is_array($data) || ($data['kdf'] ?? null) !== 'PBKDF2-SHA256' || !is_int($data['iterations'] ?? null)
            || $data['iterations'] < self::MIN_ITERATIONS || $data['iterations'] > self::MAX_ITERATIONS) {
            throw VaultRequestRefused::invalid('The key settings are not acceptable.');
        }

        return [
            $data['iterations'],
            self::base64($data['salt'] ?? null, self::SALT_BYTES, self::SALT_BYTES),
            self::base64($data['wrappedKey'] ?? null, self::WRAPPED_KEY_BYTES, self::WRAPPED_KEY_BYTES),
            self::base64($data['wrapIv'] ?? null, self::IV_BYTES, self::IV_BYTES),
        ];
    }

    /** @return string the raw auth key from $data['auth'] */
    private function authKey(mixed $data): string
    {
        $encoded = self::base64(is_array($data) ? ($data['auth'] ?? null) : null, self::AUTH_KEY_BYTES, self::AUTH_KEY_BYTES);

        return (string) base64_decode($encoded, true);
    }

    /** @return array{0: string, 1: string} */
    private function sealed(mixed $data): array
    {
        if (!is_array($data)) {
            throw VaultRequestRefused::invalid('Nothing to save.');
        }

        // At least a GCM tag (16 bytes) plus one byte of content.
        return [self::base64($data['ciphertext'] ?? null, 17, self::MAX_ENTRY_BYTES), self::base64($data['iv'] ?? null, self::IV_BYTES, self::IV_BYTES)];
    }

    /** A canonical base64 string decoding to between $min and $max bytes; returned as given. */
    private static function base64(mixed $value, int $min, int $max): string
    {
        $bytes = is_string($value) && strlen($value) <= 4 * (int) ceil($max / 3) ? base64_decode($value, true) : false;
        if ($bytes === false || base64_encode($bytes) !== $value || strlen($bytes) < $min || strlen($bytes) > $max) {
            throw VaultRequestRefused::invalid('The encrypted data is malformed or too large.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function entryJson(VaultEntry $entry): array
    {
        return ['id' => $entry->getId(), 'ciphertext' => $entry->getCiphertext(), 'iv' => $entry->getIv(), 'version' => $entry->getVersion(),
            'createdAt' => $entry->getCreatedAt(), 'updatedAt' => $entry->getUpdatedAt()];
    }
}
