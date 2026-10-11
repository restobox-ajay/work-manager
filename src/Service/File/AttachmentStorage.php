<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\Attachment;
use App\Entity\User;
use App\Repository\AttachmentRepository;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Private file storage for work orders and events (ADR-125).
 *
 *  - Files live in %app.upload_dir% (var/uploads: outside public/, so no URL reaches them) under a random name, and
 *    are only served by AttachmentController to admins.
 *  - Only the document and image types in ALLOWED_EXTENSIONS are taken, and the content is sniffed as well, so a
 *    renamed script or HTML page is refused whatever its extension says.
 *  - The size limit is the smaller of MAX_BYTES and PHP's own upload_max_filesize / post_max_size.
 */
final class AttachmentStorage
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MAX_FILES_PER_UPLOAD = 10;

    public const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'csv', 'xls', 'xlsx', 'ods', 'ppt', 'pptx',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'heic', 'zip', 'eml', 'msg',
    ];

    /** Content that must never be stored, whatever the extension claims. */
    private const REFUSED_MIME_TYPES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/x-php', 'application/x-php', 'application/x-httpd-php',
        'application/x-msdownload', 'application/x-dosexec', 'application/x-executable', 'application/x-sharedlib',
        'application/x-sh', 'text/x-shellscript', 'application/javascript', 'text/javascript',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentRepository $attachments,
        private readonly WorkAuditTrail $audit,
        #[Autowire('%app.upload_dir%')] private readonly string $uploadDir,
    ) {
    }

    /** @return Attachment[] */
    public function forOwner(string $type, int $id): array
    {
        return $this->attachments->findForOwner($type, $id);
    }

    /**
     * Stores each file and records it against its owner.
     *
     * @param array<int, UploadedFile|null> $files
     *
     * @return array{saved: int, errors: list<string>}
     */
    public function upload(array $files, string $ownerType, int $ownerId, ?string $label, User $actor): array
    {
        $files = array_values(array_filter($files, static fn ($f) => $f instanceof UploadedFile));
        if ($files === []) {
            return ['saved' => 0, 'errors' => [sprintf('Choose a file to upload (at most %s each).', self::humanSize($this->maxBytes()))]];
        }
        if (count($files) > self::MAX_FILES_PER_UPLOAD) {
            return ['saved' => 0, 'errors' => [sprintf('Upload at most %d files at a time.', self::MAX_FILES_PER_UPLOAD)]];
        }

        $label = $label !== null ? mb_substr(trim($label), 0, 120) : null;
        $saved = 0;
        $errors = [];
        foreach ($files as $file) {
            $error = $this->check($file);
            if ($error !== null) {
                $errors[] = $error;
                continue;
            }
            $extension = strtolower($file->getClientOriginalExtension());
            $storedName = bin2hex(random_bytes(16)).'.'.$extension;
            $size = (int) $file->getSize();
            $mime = $this->detectMime($file->getPathname());
            $this->ensureDirectory();
            $file->move($this->uploadDir, $storedName);

            $attachment = (new Attachment())
                ->setOwner($ownerType, $ownerId)
                ->setLabel($label === '' ? null : $label)
                ->setOriginalName(self::safeName($file->getClientOriginalName()))
                ->setStoredName($storedName)
                ->setMimeType($mime)
                ->setSize($size)
                ->setUploadedAt(time())
                ->setUploadedBy($actor->getId());
            $this->em->persist($attachment);
            $this->audit->record($actor, 'attachment.upload', sprintf('%s #%d: %s (%s)', $ownerType, $ownerId, $attachment->getOriginalName(), self::humanSize($size)));
            ++$saved;
        }
        $this->em->flush();

        return ['saved' => $saved, 'errors' => $errors];
    }

    /** The stored file's absolute path, or null when it has gone missing from disk. */
    public function pathOf(Attachment $attachment): ?string
    {
        $path = $this->uploadDir.'/'.basename($attachment->getStoredName());

        return is_file($path) ? $path : null;
    }

    public function delete(Attachment $attachment, User $actor): void
    {
        $this->removeFile($attachment);
        $this->audit->record($actor, 'attachment.delete', sprintf('%s #%d: %s', $attachment->getOwnerType(), $attachment->getOwnerId(), $attachment->getOriginalName()));
        $this->em->remove($attachment);
        $this->em->flush();
    }

    /** Removes every file of an owner that is being deleted (the caller flushes). */
    public function deleteAllFor(string $type, int $id): int
    {
        $all = $this->attachments->findForOwner($type, $id);
        foreach ($all as $attachment) {
            $this->removeFile($attachment);
            $this->em->remove($attachment);
        }

        return count($all);
    }

    /** The largest file this server accepts: our own limit, or PHP's if that is lower. */
    public function maxBytes(): int
    {
        $limits = [self::MAX_BYTES];
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = self::iniBytes((string) ini_get($setting));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    /** The accept="" list for file inputs. */
    public static function acceptAttribute(): string
    {
        return implode(',', array_map(static fn (string $e) => '.'.$e, self::ALLOWED_EXTENSIONS));
    }

    public static function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 * 1024 => rtrim(rtrim(number_format($bytes / 1024 / 1024, 1), '0'), '.').' MB',
            $bytes >= 1024        => (int) round($bytes / 1024).' KB',
            default               => $bytes.' bytes',
        };
    }

    /** "8M" / "2G" / "512K" / "1048576" → bytes; 0 when unlimited or unreadable. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d+)\s*([KMG]?)$/i', $value, $m)) {
            return 0;
        }

        return (int) $m[1] * match (strtoupper($m[2])) {
            'G'     => 1024 ** 3,
            'M'     => 1024 ** 2,
            'K'     => 1024,
            default => 1,
        };
    }

    /** A file name safe to show and to send back in Content-Disposition (no paths, no control characters). */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"]/u', '', $name) ?? '';

        return mb_substr($name !== '' ? $name : 'file', 0, 255);
    }

    private function check(UploadedFile $file): ?string
    {
        $name = self::safeName($file->getClientOriginalName());
        if (!$file->isValid()) {
            return in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? sprintf('"%s" is too large (at most %s).', $name, self::humanSize($this->maxBytes()))
                : sprintf('"%s" could not be uploaded. Please try again.', $name);
        }
        if ((int) $file->getSize() > $this->maxBytes()) {
            return sprintf('"%s" is too large (at most %s).', $name, self::humanSize($this->maxBytes()));
        }
        if ((int) $file->getSize() === 0) {
            return sprintf('"%s" is empty.', $name);
        }
        if (!in_array(strtolower($file->getClientOriginalExtension()), self::ALLOWED_EXTENSIONS, true)) {
            return sprintf('"%s": this type of file is not allowed. Upload a PDF, Word, Excel, PowerPoint, text or CSV file, a picture (PNG, JPG, GIF, WebP, HEIC), a ZIP or a saved email.', $name);
        }
        if (in_array($this->detectMime($file->getPathname()), self::REFUSED_MIME_TYPES, true)) {
            return sprintf('"%s" does not look like a document or picture, so it was not stored.', $name);
        }

        return null;
    }

    private function detectMime(string $path): string
    {
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) && $mime !== '' ? mb_substr($mime, 0, 120) : 'application/octet-stream';
    }

    private function removeFile(Attachment $attachment): void
    {
        $path = $this->pathOf($attachment);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0770, true) && !is_dir($this->uploadDir)) {
            throw new \RuntimeException('The upload folder could not be created.');
        }
        // Defence in depth: var/ is outside the web root, but should a server ever serve it, refuse.
        $guard = $this->uploadDir.'/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\n");
        }
    }
}
