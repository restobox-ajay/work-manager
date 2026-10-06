<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-084: no authorization queue. Tasks still waiting in it (authorized = 0 with no decision, Pending or Modify)
 * become regular work; denied requests stay out of the lists as before. `task_read_status` (the "Task Created By
 * Manager" read marks) is dropped with that page.
 */
final class Version20261009140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the authorization queue: release queued tasks, drop task_read_status (ADR-084)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE task SET authorized = 1 WHERE authorized = 0 AND (authorized_status IS NULL OR authorized_status IN ('', 'Pending', 'Modify'))");
        $this->addSql('DROP TABLE task_read_status');
    }

    public function down(Schema $schema): void
    {
        // The released tasks cannot be told apart from regular ones afterwards, so only the table comes back.
        $this->addSql('CREATE TABLE task_read_status (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, task_id BIGINT DEFAULT NULL, is_read SMALLINT NOT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, INDEX idx_task_read_status_user_task (user_id, task_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }
}
