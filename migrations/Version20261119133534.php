<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261119133534 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark runs whose model answers were replayed from a recording';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run ADD replayed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE run ALTER replayed DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run DROP replayed');
    }
}
