<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-124 (owner request): Client Managers, project staff and Task Managers are removed — only admins manage work.
 * down() recreates the empty tables exactly as they were; the rows themselves are not restored.
 */
final class Version20261014090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop client_admin, project_staff and task_manager (ADR-124)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS client_admin');
        $this->addSql('DROP TABLE IF EXISTS project_staff');
        $this->addSql('DROP TABLE IF EXISTS task_manager');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE client_admin (client_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_2B6391C119EB6921 (client_id), INDEX IDX_2B6391C1A76ED395 (user_id), PRIMARY KEY (client_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE client_admin ADD CONSTRAINT FK_2B6391C119EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE client_admin ADD CONSTRAINT FK_2B6391C1A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('CREATE TABLE project_staff (id INT AUTO_INCREMENT NOT NULL, permission VARCHAR(50) DEFAULT NULL, can_access_task_fee SMALLINT DEFAULT NULL, roles LONGTEXT DEFAULT NULL, created_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, project_id BIGINT NOT NULL, user_id INT NOT NULL, INDEX IDX_513BFA0C166D1F9C (project_id), INDEX IDX_513BFA0CA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE project_staff ADD CONSTRAINT FK_513BFA0C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE project_staff ADD CONSTRAINT FK_513BFA0CA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('CREATE TABLE task_manager (id INT AUTO_INCREMENT NOT NULL, task_id BIGINT NOT NULL, user_id INT NOT NULL, can_access_task_fee TINYINT NOT NULL, created_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, INDEX idx_task_manager_task (task_id), INDEX idx_task_manager_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }
}
