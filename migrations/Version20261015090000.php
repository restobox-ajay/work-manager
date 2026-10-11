<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-125 / ADR-126: work orders (with milestones), events (with categories) and the attachments both use.
 */
final class Version20261015090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Work orders, events, event categories and attachments (ADR-125, ADR-126)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE attachment (id INT AUTO_INCREMENT NOT NULL, owner_type VARCHAR(20) NOT NULL, owner_id INT NOT NULL, label VARCHAR(120) DEFAULT NULL, original_name VARCHAR(255) NOT NULL, stored_name VARCHAR(80) NOT NULL, mime_type VARCHAR(120) NOT NULL, size INT NOT NULL, uploaded_at INT NOT NULL, uploaded_by INT DEFAULT NULL, UNIQUE INDEX UNIQ_795FD9BB1185AF6A (stored_name), INDEX idx_attachment_owner (owner_type, owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE work_order (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(30) NOT NULL, title VARCHAR(200) NOT NULL, status VARCHAR(20) NOT NULL, client_name VARCHAR(150) DEFAULT NULL, contact_name VARCHAR(120) DEFAULT NULL, contact_phone VARCHAR(40) DEFAULT NULL, contact_email VARCHAR(180) DEFAULT NULL, reference VARCHAR(80) DEFAULT NULL, value NUMERIC(14, 2) DEFAULT NULL, currency VARCHAR(3) NOT NULL, payment_terms VARCHAR(255) DEFAULT NULL, advance_received NUMERIC(14, 2) DEFAULT NULL, signed_date DATE DEFAULT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, description LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at INT NOT NULL, updated_at INT NOT NULL, created_by INT DEFAULT NULL, client_id INT DEFAULT NULL, project_id BIGINT DEFAULT NULL, UNIQUE INDEX UNIQ_DDD2E8B796901F54 (number), INDEX IDX_DDD2E8B719EB6921 (client_id), INDEX IDX_DDD2E8B7166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE work_order_milestone (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, due_date DATE DEFAULT NULL, amount NUMERIC(14, 2) DEFAULT NULL, done TINYINT NOT NULL, done_on DATE DEFAULT NULL, work_order_id INT NOT NULL, INDEX IDX_61D882C8582AE764 (work_order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE event_category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, sort_order INT DEFAULT 0 NOT NULL, is_active TINYINT NOT NULL, UNIQUE INDEX UNIQ_40A0F0115E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE life_event (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, event_date DATE NOT NULL, event_time TIME DEFAULT NULL, end_date DATE DEFAULT NULL, people VARCHAR(255) DEFAULT NULL, place VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, repeats_yearly TINYINT NOT NULL, remind_days_before SMALLINT DEFAULT NULL, last_reminded_for DATE DEFAULT NULL, created_at INT NOT NULL, updated_at INT NOT NULL, created_by INT DEFAULT NULL, category_id INT DEFAULT NULL, INDEX IDX_17CF369112469DE2 (category_id), INDEX idx_life_event_date (event_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE work_order ADD CONSTRAINT FK_DDD2E8B719EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE work_order ADD CONSTRAINT FK_DDD2E8B7166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE work_order_milestone ADD CONSTRAINT FK_61D882C8582AE764 FOREIGN KEY (work_order_id) REFERENCES work_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE life_event ADD CONSTRAINT FK_17CF369112469DE2 FOREIGN KEY (category_id) REFERENCES event_category (id) ON DELETE SET NULL');
        $this->addSql("INSERT INTO event_category (name, sort_order, is_active) VALUES ('Birth', 10, 1), ('Birthday', 20, 1), ('Death', 30, 1), ('Anniversary', 40, 1), ('Marriage', 50, 1), ('Health', 60, 1), ('Legal / Document', 70, 1), ('Financial', 80, 1), ('Meeting', 90, 1), ('Other', 100, 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE work_order_milestone');
        $this->addSql('DROP TABLE work_order');
        $this->addSql('DROP TABLE life_event');
        $this->addSql('DROP TABLE event_category');
        $this->addSql('DROP TABLE attachment');
    }
}
