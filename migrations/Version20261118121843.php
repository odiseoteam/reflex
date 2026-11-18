<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118121843 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cron schedules to reflexes and a table for shared locks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT NOT NULL, PRIMARY KEY (key_id))');
        $this->addSql('ALTER TABLE reflex ADD cron VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE reflex ADD last_fired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lock_keys');
        $this->addSql('ALTER TABLE reflex DROP cron');
        $this->addSql('ALTER TABLE reflex DROP last_fired_at');
    }
}
