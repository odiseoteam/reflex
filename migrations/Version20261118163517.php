<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118163517 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add poll and absence triggers, with absence waitpoints; drop the lock table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reflex ADD trigger_config JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE reflex ALTER trigger_config DROP DEFAULT');
        $this->addSql('ALTER TABLE reflex ADD poll_state JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE reflex ADD last_polled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE waitpoint ADD cancelled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE waitpoint ALTER cancelled DROP DEFAULT');
        $this->addSql('ALTER TABLE waitpoint ADD correlation VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE waitpoint ADD due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE waitpoint ADD reflex_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE waitpoint ADD event_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE waitpoint ALTER run_id DROP NOT NULL');
        $this->addSql('ALTER TABLE waitpoint ADD CONSTRAINT FK_9190584F515E8BB0 FOREIGN KEY (reflex_id) REFERENCES reflex (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE waitpoint ADD CONSTRAINT FK_9190584F71F7E88B FOREIGN KEY (event_id) REFERENCES incoming_event (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX waitpoint_due_idx ON waitpoint (kind, resolved_at, due_at)');
        $this->addSql('CREATE INDEX waitpoint_correlation_idx ON waitpoint (reflex_id, correlation)');
        $this->addSql('CREATE INDEX IDX_9190584F515E8BB0 ON waitpoint (reflex_id)');
        $this->addSql('CREATE INDEX IDX_9190584F71F7E88B ON waitpoint (event_id)');
        // Ticks claim their work with conditional updates now, so the shared lock table goes.
        $this->addSql('DROP TABLE lock_keys');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT NOT NULL, PRIMARY KEY (key_id))');
        $this->addSql('ALTER TABLE reflex DROP trigger_config');
        $this->addSql('ALTER TABLE reflex DROP poll_state');
        $this->addSql('ALTER TABLE reflex DROP last_polled_at');
        $this->addSql('ALTER TABLE waitpoint DROP CONSTRAINT FK_9190584F515E8BB0');
        $this->addSql('ALTER TABLE waitpoint DROP CONSTRAINT FK_9190584F71F7E88B');
        $this->addSql('DROP INDEX waitpoint_due_idx');
        $this->addSql('DROP INDEX waitpoint_correlation_idx');
        $this->addSql('DROP INDEX IDX_9190584F515E8BB0');
        $this->addSql('DROP INDEX IDX_9190584F71F7E88B');
        $this->addSql('ALTER TABLE waitpoint DROP cancelled');
        $this->addSql('ALTER TABLE waitpoint DROP correlation');
        $this->addSql('ALTER TABLE waitpoint DROP due_at');
        $this->addSql('ALTER TABLE waitpoint DROP reflex_id');
        $this->addSql('ALTER TABLE waitpoint DROP event_id');
        $this->addSql('ALTER TABLE waitpoint ALTER run_id SET NOT NULL');
    }
}
