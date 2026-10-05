<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-072: the two work-platform task tables the rest of the Tasks menu needs — `task_priority_order` (each
 * person's own task order, Task Priority page) and `task_read_status` (who has read which queued task, Task
 * Created By Manager page). Same columns as work-platform.
 */
final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Task priority order and read status tables from work-platform (ADR-072)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_priority_order (id BIGINT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, task_id INT NOT NULL, sort_order INT DEFAULT NULL, INDEX idx_task_priority_order_user_task (user_id, task_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE task_read_status (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, task_id BIGINT DEFAULT NULL, is_read SMALLINT NOT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, INDEX idx_task_read_status_user_task (user_id, task_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE task_read_status');
        $this->addSql('DROP TABLE task_priority_order');
    }
}
