<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115173625 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reflexes, incoming events, runs and run steps';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE incoming_event (id UUID NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, source VARCHAR(40) NOT NULL, external_id VARCHAR(255) NOT NULL, type VARCHAR(100) NOT NULL, payload JSONB NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX incoming_event_external_idx ON incoming_event (source, external_id)');
        $this->addSql('CREATE TABLE reflex (id UUID NOT NULL, enabled BOOLEAN NOT NULL, tool_policies JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(100) NOT NULL, trigger_source VARCHAR(40) NOT NULL, trigger_event VARCHAR(100) NOT NULL, instructions TEXT NOT NULL, model VARCHAR(100) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX reflex_trigger_idx ON reflex (trigger_source, trigger_event)');
        $this->addSql('CREATE TABLE reflex_connection (reflex_id UUID NOT NULL, connection_id UUID NOT NULL, PRIMARY KEY (reflex_id, connection_id))');
        $this->addSql('CREATE INDEX IDX_EC32DC38515E8BB0 ON reflex_connection (reflex_id)');
        $this->addSql('CREATE INDEX IDX_EC32DC38DD03F01 ON reflex_connection (connection_id)');
        $this->addSql('CREATE TABLE run (id UUID NOT NULL, status VARCHAR(20) NOT NULL, reflex_snapshot JSONB NOT NULL, output TEXT DEFAULT NULL, error TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reflex_id UUID NOT NULL, event_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX run_reflex_created_idx ON run (reflex_id, created_at)');
        $this->addSql('CREATE INDEX IDX_5076A4C0515E8BB0 ON run (reflex_id)');
        $this->addSql('CREATE INDEX IDX_5076A4C071F7E88B ON run (event_id)');
        $this->addSql('CREATE TABLE run_step (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, position INT NOT NULL, kind VARCHAR(20) NOT NULL, tool_name VARCHAR(255) NOT NULL, arguments JSONB NOT NULL, result TEXT DEFAULT NULL, run_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX run_step_position_idx ON run_step (run_id, position)');
        $this->addSql('CREATE INDEX IDX_DF47A11884E3FEC4 ON run_step (run_id)');
        $this->addSql('ALTER TABLE reflex_connection ADD CONSTRAINT FK_EC32DC38515E8BB0 FOREIGN KEY (reflex_id) REFERENCES reflex (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reflex_connection ADD CONSTRAINT FK_EC32DC38DD03F01 FOREIGN KEY (connection_id) REFERENCES connection (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE run ADD CONSTRAINT FK_5076A4C0515E8BB0 FOREIGN KEY (reflex_id) REFERENCES reflex (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE run ADD CONSTRAINT FK_5076A4C071F7E88B FOREIGN KEY (event_id) REFERENCES incoming_event (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE run_step ADD CONSTRAINT FK_DF47A11884E3FEC4 FOREIGN KEY (run_id) REFERENCES run (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reflex_connection DROP CONSTRAINT FK_EC32DC38515E8BB0');
        $this->addSql('ALTER TABLE reflex_connection DROP CONSTRAINT FK_EC32DC38DD03F01');
        $this->addSql('ALTER TABLE run DROP CONSTRAINT FK_5076A4C0515E8BB0');
        $this->addSql('ALTER TABLE run DROP CONSTRAINT FK_5076A4C071F7E88B');
        $this->addSql('ALTER TABLE run_step DROP CONSTRAINT FK_DF47A11884E3FEC4');
        $this->addSql('DROP TABLE incoming_event');
        $this->addSql('DROP TABLE reflex');
        $this->addSql('DROP TABLE reflex_connection');
        $this->addSql('DROP TABLE run');
        $this->addSql('DROP TABLE run_step');
    }
}
