<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-135: a one-off tidy of the existing project names — dashes become spaces and each word starts with a capital
 * ("wheel-mart-canada" → "Wheel Mart Canada"). New and edited projects keep the name exactly as typed.
 */
final class Version20261018090000 extends AbstractMigration
{
    /** Hyphen-minus plus the Unicode hyphens and dashes (U+2010–U+2015). */
    private const DASHES = '/[-\x{2010}-\x{2015}]+/u';

    public function getDescription(): string
    {
        return 'Existing project names: dashes to spaces, words capitalised (ADR-135)';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->connection->fetchAllKeyValue('SELECT id, name FROM project') as $id => $name) {
            $tidy = self::tidy((string) $name);
            if ($tidy !== '' && $tidy !== $name) {
                $this->addSql('UPDATE project SET name = ? WHERE id = ?', [$tidy, (int) $id]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The original project names are not kept.');
    }

    /** Like ucwords(): only each word's first letter changes, so "GHL" or "iOS"-style casing inside a word stays. */
    public static function tidy(string $name): string
    {
        $spaced = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(self::DASHES, ' ', $name)));

        return (string) preg_replace_callback('/(^| )(\p{Ll})/u', static fn (array $m) => $m[1].mb_strtoupper($m[2]), $spaced);
    }
}
