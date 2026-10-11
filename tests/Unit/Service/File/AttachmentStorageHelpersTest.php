<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\AttachmentStorage;
use PHPUnit\Framework\TestCase;

/** ADR-125: the size and name helpers the upload checks rely on. */
final class AttachmentStorageHelpersTest extends TestCase
{
    public function testPhpSizeSettingsAreReadAsBytes(): void
    {
        self::assertSame(2 * 1024 * 1024, AttachmentStorage::iniBytes('2M'));
        self::assertSame(512 * 1024, AttachmentStorage::iniBytes('512k'));
        self::assertSame(1024 ** 3, AttachmentStorage::iniBytes('1G'));
        self::assertSame(1000, AttachmentStorage::iniBytes('1000'));
        self::assertSame(0, AttachmentStorage::iniBytes('-1'), 'Unlimited does not lower our limit.');
        self::assertSame(0, AttachmentStorage::iniBytes(''));
    }

    public function testSizesReadNaturally(): void
    {
        self::assertSame('512 bytes', AttachmentStorage::humanSize(512));
        self::assertSame('2 KB', AttachmentStorage::humanSize(2048));
        self::assertSame('20 MB', AttachmentStorage::humanSize(20 * 1024 * 1024));
        self::assertSame('1.5 MB', AttachmentStorage::humanSize((int) (1.5 * 1024 * 1024)));
    }

    public function testFileNamesLoseTheirPathAndControlCharacters(): void
    {
        self::assertSame('contract.pdf', AttachmentStorage::safeName('../../etc/contract.pdf'));
        self::assertSame('contract.pdf', AttachmentStorage::safeName('C:\\Users\\me\\contract.pdf'));
        self::assertSame('a b.pdf', AttachmentStorage::safeName("a\"\x00 b.pdf"));
        self::assertSame('file', AttachmentStorage::safeName(''));
    }

    public function testOnlyDocumentAndImageTypesAreAccepted(): void
    {
        foreach (['pdf', 'docx', 'xlsx', 'jpg', 'png', 'zip'] as $ok) {
            self::assertContains($ok, AttachmentStorage::ALLOWED_EXTENSIONS);
        }
        foreach (['php', 'phtml', 'html', 'svg', 'js', 'exe', 'sh', 'bat'] as $bad) {
            self::assertNotContains($bad, AttachmentStorage::ALLOWED_EXTENSIONS);
        }
    }
}
