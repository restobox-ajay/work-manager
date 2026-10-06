<?php

declare(strict_types=1);

namespace App\Service\Vault;

use App\Entity\User;
use App\Entity\Vault\VaultEntry;
use App\Entity\Vault\VaultKey;
use App\Repository\Vault\VaultEntryRepository;
use App\Repository\Vault\VaultKeyRepository;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The Password Manager's server side (ADR-092). It only ever stores and returns opaque, browser-encrypted blobs for
 * the signed-in admin: it checks their shape and size, keeps each admin to their own vault and audits what changed
 * (never the content). It cannot decrypt anything — there is no key here to do it with.
 */
final class VaultService
{
    /** PBKDF2-SHA256 iterations: OWASP's 2023 minimum is 600,000. The browser picks; we refuse weaker. */
    public const MIN_ITERATIONS = 600_000;
    private const MAX_ITERATIONS = 10_000_000;
    private const SALT_BYTES = 16;
    private const IV_BYTES = 12;
    /** 32-byte AES-256 vault key + 16-byte GCM tag. */
    private const WRAPPED_KEY_BYTES = 48;
    /** One entry's ciphertext; generous for notes, small enough to stop the table being used as file storage. */
    private const MAX_ENTRY_BYTES = 64 * 1024;
    private const MAX_ENTRIES = 5000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VaultKeyRepository $keys,
        private readonly VaultEntryRepository $entries,
        private readonly WorkAuditTrail $audit,
    ) {
    }

    /**
     * Everything the browser needs to unlock and show the vault: the wrapped key and every encrypted entry.
     *
     * @return array{setUp: bool, key: ?array{kdf: string, iterations: int, salt: string, wrappedKey: string, wrapIv: string}, entries: list<array<string, mixed>>}
     */
    public function state(User $user): array
    {
        $key = $this->keys->findForUser($user);

        return [
            'setUp'   => $key !== null,
            'key'     => $key === null ? null : ['kdf' => $key->getKdf(), 'iterations' => $key->getIterations(), 'salt' => $key->getSalt(),
                'wrappedKey' => $key->getWrappedKey(), 'wrapIv' => $key->getWrapIv()],
            'entries' => $key === null ? [] : array_map($this->entryJson(...), $this->entries->findForUser($user)),
        ];
    }

    /** First use: stores the wrapped vault key. Refused once a vault exists (that is what rewrap() is for). */
    public function setUp(User $user, mixed $data): void
    {
        if ($this->keys->findForUser($user) !== null) {
            throw VaultRequestRefused::conflict('Your vault is already set up. Reload the page.');
        }
        [$iterations, $salt, $wrappedKey, $wrapIv] = $this->wrapping($data);
        $this->em->persist((new VaultKey())->setUser($user)->wrap($iterations, $salt, $wrappedKey, $wrapIv));
        $this->em->flush();
        $this->audit->record($user, 'vault.setup', 'Password Manager vault created');
    }

    /** New master password: the same vault key, wrapped again. Entries are untouched. */
    public function rewrap(User $user, mixed $data): void
    {
        $key = $this->requireKey($user);
        [$iterations, $salt, $wrappedKey, $wrapIv] = $this->wrapping($data);
        $key->wrap($iterations, $salt, $wrappedKey, $wrapIv);
        $this->em->flush();
        $this->audit->record($user, 'vault.master_password', 'Password Manager master password changed');
    }

    /** @return array<string, mixed> the saved entry */
    public function create(User $user, mixed $data): array
    {
        $this->requireKey($user);
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
    public function update(User $user, int $id, mixed $data): array
    {
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

    public function delete(User $user, int $id): void
    {
        $entry = $this->entries->findOwned($id, $user) ?? throw VaultRequestRefused::notFound();
        $this->em->remove($entry);
        $this->em->flush();
        $this->audit->record($user, 'vault.entry_delete', sprintf('Vault entry #%d', $id));
    }

    /** Forgotten master password: the only way out is to delete the vault and start again. */
    public function reset(User $user): int
    {
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
