<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117002121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add run limits, budgets, resumable steps and auto-disable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reflex ADD daily_token_budget INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reflex ADD max_tool_calls INT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE reflex ADD time_limit_seconds INT DEFAULT 120 NOT NULL');
        $this->addSql('ALTER TABLE reflex ADD consecutive_failures INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE reflex ADD disabled_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE run ADD attempts INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE run ADD tokens_used INT DEFAULT NULL');
        $this->addSql('ALTER TABLE run_step ADD completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Existing steps get a key of their own, and are complete since they ran before this column.
        $this->addSql('ALTER TABLE run_step ADD idempotency_key VARCHAR(64) DEFAULT NULL');
        $this->addSql("UPDATE run_step SET idempotency_key = encode(sha256(id::text::bytea), 'hex'), completed_at = created_at");
        $this->addSql('ALTER TABLE run_step ALTER idempotency_key SET NOT NULL');
        foreach (['reflex' => ['max_tool_calls', 'time_limit_seconds', 'consecutive_failures'], 'run' => ['attempts']] as $table => $columns) {
            foreach ($columns as $column) {
                $this->addSql(\sprintf('ALTER TABLE %s ALTER %s DROP DEFAULT', $table, $column));
            }
        }
        $this->addSql('CREATE UNIQUE INDEX run_step_idempotency_idx ON run_step (idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reflex DROP daily_token_budget');
        $this->addSql('ALTER TABLE reflex DROP max_tool_calls');
        $this->addSql('ALTER TABLE reflex DROP time_limit_seconds');
        $this->addSql('ALTER TABLE reflex DROP consecutive_failures');
        $this->addSql('ALTER TABLE reflex DROP disabled_reason');
        $this->addSql('ALTER TABLE run DROP attempts');
        $this->addSql('ALTER TABLE run DROP tokens_used');
        $this->addSql('DROP INDEX run_step_idempotency_idx');
        $this->addSql('ALTER TABLE run_step DROP completed_at');
        $this->addSql('ALTER TABLE run_step DROP idempotency_key');
    }
}
