<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117213114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add decisions, waitpoints and resumable run segments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE decision (id UUID NOT NULL, status VARCHAR(20) NOT NULL, note TEXT DEFAULT NULL, result TEXT DEFAULT NULL, segment INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, execution_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, executed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, tool_name VARCHAR(255) NOT NULL, remote_tool_name VARCHAR(255) NOT NULL, arguments JSONB NOT NULL, reason TEXT NOT NULL, run_id UUID NOT NULL, waitpoint_id UUID NOT NULL, connection_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX decision_status_idx ON decision (status, created_at)');
        $this->addSql('CREATE INDEX IDX_84ACBE4884E3FEC4 ON decision (run_id)');
        $this->addSql('CREATE INDEX IDX_84ACBE489FEA4B44 ON decision (waitpoint_id)');
        $this->addSql('CREATE INDEX IDX_84ACBE48DD03F01 ON decision (connection_id)');
        $this->addSql('CREATE TABLE waitpoint (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, kind VARCHAR(20) NOT NULL, run_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9190584F84E3FEC4 ON waitpoint (run_id)');
        $this->addSql('ALTER TABLE decision ADD CONSTRAINT FK_84ACBE4884E3FEC4 FOREIGN KEY (run_id) REFERENCES run (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE decision ADD CONSTRAINT FK_84ACBE489FEA4B44 FOREIGN KEY (waitpoint_id) REFERENCES waitpoint (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE decision ADD CONSTRAINT FK_84ACBE48DD03F01 FOREIGN KEY (connection_id) REFERENCES connection (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE waitpoint ADD CONSTRAINT FK_9190584F84E3FEC4 FOREIGN KEY (run_id) REFERENCES run (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE run ADD segment INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE run ALTER segment DROP DEFAULT');
        $this->addSql('ALTER TABLE run ADD conversation JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE decision DROP CONSTRAINT FK_84ACBE4884E3FEC4');
        $this->addSql('ALTER TABLE decision DROP CONSTRAINT FK_84ACBE489FEA4B44');
        $this->addSql('ALTER TABLE decision DROP CONSTRAINT FK_84ACBE48DD03F01');
        $this->addSql('ALTER TABLE waitpoint DROP CONSTRAINT FK_9190584F84E3FEC4');
        $this->addSql('DROP TABLE decision');
        $this->addSql('DROP TABLE waitpoint');
        $this->addSql('ALTER TABLE run DROP segment');
        $this->addSql('ALTER TABLE run DROP conversation');
    }
}
