<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-088: a payment made with "Mark paid" on the Year View records the month's bill it pays.
 */
final class Version20261010130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'rent_payment.bill_id: the bill a "Mark paid" payment settles (ADR-088)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rent_payment ADD bill_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE rent_payment ADD CONSTRAINT FK_8C04FF7D1A8C12F5 FOREIGN KEY (bill_id) REFERENCES rent_bill (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8C04FF7D1A8C12F5 ON rent_payment (bill_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rent_payment DROP FOREIGN KEY FK_8C04FF7D1A8C12F5');
        $this->addSql('DROP INDEX IDX_8C04FF7D1A8C12F5 ON rent_payment');
        $this->addSql('ALTER TABLE rent_payment DROP bill_id');
    }
}
