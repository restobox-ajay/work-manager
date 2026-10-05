<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-070: clients, projects and tasks, with the tables and columns of work-platform's (Yii2) schema as its
 * entities map them, so data can be copied across later without a mapping step. Timestamps stay unix integers,
 * as there. Not carried over: project.is_template / parent_template_id / template_grid_sort_order /
 * duplicated_project_id (Project Templates, which work-platform dropped) — they have no use here.
 *
 * Seeds the reference rows other code names by id: task statuses 1-8 (TaskStatus::*_ID), currency 1 (the
 * column default) and one task type. Admins can edit them under Settings.
 */
final class Version20261006120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clients, projects and tasks from work-platform (ADR-070)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE currency (id INT AUTO_INCREMENT NOT NULL, short_name VARCHAR(10) NOT NULL, fx_rate NUMERIC(10, 2) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE task_status (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, is_active TINYINT NOT NULL, `order` INT DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE task_type (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, description VARCHAR(255) DEFAULT NULL, status TINYINT NOT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE client (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, email VARCHAR(100) DEFAULT NULL, client_code VARCHAR(10) DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, street_address_1 VARCHAR(255) DEFAULT NULL, street_address_2 VARCHAR(255) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, province VARCHAR(100) DEFAULT NULL, state VARCHAR(100) DEFAULT NULL, zip_code VARCHAR(10) DEFAULT NULL, country VARCHAR(50) DEFAULT NULL, is_active SMALLINT NOT NULL, is_deleted SMALLINT NOT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE client_admin (client_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_2B6391C119EB6921 (client_id), INDEX IDX_2B6391C1A76ED395 (user_id), PRIMARY KEY (client_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE project (id BIGINT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, status SMALLINT NOT NULL, is_deleted SMALLINT NOT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, client_id INT NOT NULL, INDEX IDX_2FB3D0EE19EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE project_staff (id INT AUTO_INCREMENT NOT NULL, permission VARCHAR(50) DEFAULT NULL, can_access_task_fee SMALLINT DEFAULT NULL, roles LONGTEXT DEFAULT NULL, created_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, project_id BIGINT NOT NULL, user_id INT NOT NULL, INDEX IDX_513BFA0C166D1F9C (project_id), INDEX IDX_513BFA0CA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE task (id BIGINT AUTO_INCREMENT NOT NULL, client_id INT DEFAULT NULL, task_type_id INT DEFAULT NULL, currency_id INT DEFAULT NULL, rbx_order_id INT DEFAULT NULL, name VARCHAR(5000) NOT NULL, reviewer_user_id INT DEFAULT NULL, description LONGTEXT DEFAULT NULL, is_read SMALLINT DEFAULT NULL, task_status_id INT DEFAULT NULL, status_detail LONGTEXT DEFAULT NULL, approved_date INT DEFAULT NULL, approved_by INT DEFAULT NULL, due_date INT DEFAULT NULL, paid_date INT DEFAULT NULL, paid_by INT DEFAULT NULL, payment_id VARCHAR(50) DEFAULT NULL, total_amount NUMERIC(10, 2) DEFAULT NULL, is_active SMALLINT DEFAULT NULL, is_deleted SMALLINT DEFAULT NULL, payer_id INT DEFAULT NULL, dependent_task_list LONGTEXT DEFAULT NULL, authorized SMALLINT DEFAULT NULL, authorized_status VARCHAR(50) DEFAULT NULL, authorized_description LONGTEXT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, priority INT DEFAULT NULL, duplicated_task_id INT DEFAULT NULL, is_active_on_template_grid SMALLINT DEFAULT NULL, template_sort_order INT DEFAULT NULL, tutorial LONGTEXT DEFAULT NULL, creation_date DATE DEFAULT NULL, time_budget INT DEFAULT NULL, billable_date DATE DEFAULT NULL, project_id BIGINT DEFAULT NULL, user_id INT DEFAULT NULL, INDEX IDX_527EDB25166D1F9C (project_id), INDEX IDX_527EDB25A76ED395 (user_id), INDEX idx_task_client (client_id), INDEX idx_task_status (task_status_id), INDEX idx_task_reviewer (reviewer_user_id), INDEX idx_task_created_by (created_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE task_manager (id INT AUTO_INCREMENT NOT NULL, task_id BIGINT NOT NULL, user_id INT NOT NULL, can_access_task_fee TINYINT NOT NULL, created_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, INDEX idx_task_manager_task (task_id), INDEX idx_task_manager_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE client_admin ADD CONSTRAINT FK_2B6391C119EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE client_admin ADD CONSTRAINT FK_2B6391C1A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE19EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_staff ADD CONSTRAINT FK_513BFA0C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE project_staff ADD CONSTRAINT FK_513BFA0CA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');

        foreach ([
            [1, 'Pending', 1],
            [2, 'Approved', 2],
            [3, 'Paid', 3],
            [4, 'Reviewing - Internal', 4],
            [5, 'Overdue', 5],
            [6, 'Inquiry', 6],
            [7, 'Waiting For Another Task', 7],
            [8, 'Reviewing - Client', 8],
        ] as [$id, $name, $order]) {
            $this->addSql('INSERT INTO task_status (id, name, is_active, `order`, created_at) VALUES (?, ?, 1, ?, UNIX_TIMESTAMP())', [$id, $name, $order]);
        }
        $this->addSql("INSERT INTO currency (id, short_name, fx_rate) VALUES (1, 'USD', 1.00), (2, 'CAD', 1.00)");
        $this->addSql("INSERT INTO task_type (id, name, description, status, created_at) VALUES (1, 'General', NULL, 1, UNIX_TIMESTAMP())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE task_manager');
        $this->addSql('DROP TABLE task');
        $this->addSql('DROP TABLE project_staff');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE client_admin');
        $this->addSql('DROP TABLE client');
        $this->addSql('DROP TABLE task_type');
        $this->addSql('DROP TABLE task_status');
        $this->addSql('DROP TABLE currency');
    }
}
