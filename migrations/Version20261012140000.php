<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-101: notes — on one client, project or task, or independent (private to the author). A note goes with the
 * record it is about (ON DELETE CASCADE), so a hard-deleted record never leaves a note behind as an "independent" one.
 */
final class Version20261012140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes on clients, projects, tasks and independent notes (ADR-101)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE note (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(150) NOT NULL, body LONGTEXT DEFAULT NULL, pinned TINYINT NOT NULL, created_at INT NOT NULL, updated_at INT NOT NULL, client_id INT DEFAULT NULL, project_id BIGINT DEFAULT NULL, task_id BIGINT DEFAULT NULL, author_id INT NOT NULL, INDEX IDX_CFBDFA1419EB6921 (client_id), INDEX IDX_CFBDFA14166D1F9C (project_id), INDEX IDX_CFBDFA148DB60186 (task_id), INDEX idx_note_author (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA1419EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA148DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14F675F31B FOREIGN KEY (author_id) REFERENCES "user" (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE note');
    }
}
