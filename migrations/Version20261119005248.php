<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261119005248 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add precedents, the memory of past decisions, and the precedents each run recalled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE precedent (id UUID NOT NULL, situation TEXT NOT NULL, embedding vector DEFAULT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, kind VARCHAR(20) NOT NULL, verdict VARCHAR(20) NOT NULL, tool_name VARCHAR(255) NOT NULL, arguments JSONB NOT NULL, reason TEXT NOT NULL, decided_by VARCHAR(255) NOT NULL, note TEXT DEFAULT NULL, answer JSONB DEFAULT NULL, reflex_id UUID NOT NULL, decision_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A8AD3D8E515E8BB0 ON precedent (reflex_id)');
        $this->addSql('CREATE INDEX IDX_A8AD3D8EBDEE7539 ON precedent (decision_id)');
        $this->addSql('ALTER TABLE precedent ADD CONSTRAINT FK_A8AD3D8E515E8BB0 FOREIGN KEY (reflex_id) REFERENCES reflex (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE precedent ADD CONSTRAINT FK_A8AD3D8EBDEE7539 FOREIGN KEY (decision_id) REFERENCES decision (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE run ADD recalled JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE run ALTER recalled DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE precedent DROP CONSTRAINT FK_A8AD3D8E515E8BB0');
        $this->addSql('ALTER TABLE precedent DROP CONSTRAINT FK_A8AD3D8EBDEE7539');
        $this->addSql('DROP TABLE precedent');
        $this->addSql('ALTER TABLE run DROP recalled');
    }
}
