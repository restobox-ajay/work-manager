<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-129: a project's links become a list (project_link: name, url, description, order). The fixed local, dev,
 * prod, doc and mockup URL columns are copied into it as links named Local, Dev, Prod, Doc / Specs and Mockup, then
 * dropped. down() puts back the five columns and fills each from the first link of that name.
 */
final class Version20261017090000 extends AbstractMigration
{
    private const COLUMNS = ['local_url' => 'Local', 'dev_url' => 'Dev', 'prod_url' => 'Prod', 'doc_url' => 'Doc / Specs', 'mockup_url' => 'Mockup'];

    public function getDescription(): string
    {
        return 'Project links as a list; local/dev/prod/doc/mockup URLs moved into it (ADR-129)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project_link (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, url VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, sort_order INT DEFAULT 0 NOT NULL, project_id BIGINT NOT NULL, INDEX IDX_F3D51E9166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE project_link ADD CONSTRAINT FK_F3D51E9166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $order = 0;
        foreach (self::COLUMNS as $column => $name) {
            $order += 10;
            $this->addSql(sprintf(
                "INSERT INTO project_link (project_id, name, url, description, sort_order) SELECT id, '%s', %s, NULL, %d FROM project WHERE %s IS NOT NULL AND TRIM(%s) <> ''",
                $name, $column, $order, $column, $column,
            ));
        }
        $this->addSql('ALTER TABLE project DROP local_url, DROP dev_url, DROP prod_url, DROP doc_url, DROP mockup_url');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD local_url VARCHAR(255) DEFAULT NULL, ADD dev_url VARCHAR(255) DEFAULT NULL, ADD prod_url VARCHAR(255) DEFAULT NULL, ADD doc_url VARCHAR(255) DEFAULT NULL, ADD mockup_url VARCHAR(255) DEFAULT NULL');
        foreach (self::COLUMNS as $column => $name) {
            $this->addSql(sprintf(
                "UPDATE project p SET %s = (SELECT l.url FROM project_link l WHERE l.project_id = p.id AND l.name = '%s' ORDER BY l.sort_order, l.id LIMIT 1)",
                $column, $name,
            ));
        }
        $this->addSql('DROP TABLE project_link');
    }
}
