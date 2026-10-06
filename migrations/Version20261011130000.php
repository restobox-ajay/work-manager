<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-092: Password Manager — `vault_key` (each admin's vault key, wrapped by their master password in the browser)
 * and `vault_entry` (browser-encrypted entries). Neither holds anything the server can decrypt.
 */
final class Version20261011130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Password Manager vault tables (ADR-092)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vault_key (id INT AUTO_INCREMENT NOT NULL, kdf VARCHAR(30) NOT NULL, iterations INT NOT NULL, salt VARCHAR(64) NOT NULL, wrapped_key VARCHAR(128) NOT NULL, wrap_iv VARCHAR(32) NOT NULL, created_at INT NOT NULL, updated_at INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_527A1539A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE vault_entry (id INT AUTO_INCREMENT NOT NULL, ciphertext LONGTEXT NOT NULL, iv VARCHAR(32) NOT NULL, version INT DEFAULT 1 NOT NULL, created_at INT NOT NULL, updated_at INT NOT NULL, user_id INT NOT NULL, INDEX IDX_76B9510BA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE vault_key ADD CONSTRAINT FK_527A1539A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vault_entry ADD CONSTRAINT FK_76B9510BA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE vault_entry');
        $this->addSql('DROP TABLE vault_key');
    }
}
