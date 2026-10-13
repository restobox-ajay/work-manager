<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use DoctrineMigrations\Version20261018090000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/migrations/Version20261018090000.php';

/** ADR-135: the one-off project name tidy — dashes to spaces, each word's first letter capitalised. */
final class ProjectNameTidyMigrationTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'dashes become spaces'         => ['wheel-mart-canada', 'Wheel Mart Canada'];
        yield 'single word'                  => ['tapio', 'Tapio'];
        yield 'runs of dashes collapse'      => ['tapio--main - old', 'Tapio Main Old'];
        yield 'unicode dashes'               => ["maxeme\u{2013}auto\u{2014}old", 'Maxeme Auto Old'];
        yield 'outer dashes trimmed'         => ['-rct-', 'Rct'];
        yield 'casing inside a word is kept' => ['Rbx GHL reporting', 'Rbx GHL Reporting'];
        yield 'dots are left alone'          => ['backend.joyfulfishing', 'Backend.joyfulfishing'];
        yield 'digits'                       => ['yii2-basic', 'Yii2 Basic'];
        yield 'already tidy'                 => ['Wheel Mart Canada', 'Wheel Mart Canada'];
        yield 'only dashes'                  => ['---', ''];
    }

    #[DataProvider('names')]
    public function testTidy(string $name, string $expected): void
    {
        self::assertSame($expected, Version20261018090000::tidy($name));
    }
}
