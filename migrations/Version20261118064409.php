<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118064409 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users, votes on decisions and questions from servers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, password TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(100) NOT NULL, admin BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('CREATE TABLE vote (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, approve BOOLEAN NOT NULL, note TEXT DEFAULT NULL, decision_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX vote_decision_user_uniq ON vote (decision_id, user_id)');
        $this->addSql('CREATE INDEX IDX_5A108564BDEE7539 ON vote (decision_id)');
        $this->addSql('CREATE INDEX IDX_5A108564A76ED395 ON vote (user_id)');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A108564BDEE7539 FOREIGN KEY (decision_id) REFERENCES decision (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A108564A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE decision ADD question JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE decision ADD answers JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE decision ALTER answers DROP DEFAULT');
        $this->addSql('ALTER TABLE decision ADD kind VARCHAR(20) DEFAULT \'proposal\' NOT NULL');
        $this->addSql('ALTER TABLE decision ALTER kind DROP DEFAULT');
        $this->addSql('ALTER TABLE decision ADD required_approvals INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE decision ALTER required_approvals DROP DEFAULT');
        $this->addSql('ALTER TABLE decision ADD respondent_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE decision ADD CONSTRAINT FK_84ACBE48CE80CD19 FOREIGN KEY (respondent_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_84ACBE48CE80CD19 ON decision (respondent_id)');
        $this->addSql('ALTER TABLE reflex ADD required_approvals JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE reflex ALTER required_approvals DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vote DROP CONSTRAINT FK_5A108564BDEE7539');
        $this->addSql('ALTER TABLE vote DROP CONSTRAINT FK_5A108564A76ED395');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE vote');
        $this->addSql('ALTER TABLE decision DROP CONSTRAINT FK_84ACBE48CE80CD19');
        $this->addSql('DROP INDEX IDX_84ACBE48CE80CD19');
        $this->addSql('ALTER TABLE decision DROP question');
        $this->addSql('ALTER TABLE decision DROP answers');
        $this->addSql('ALTER TABLE decision DROP kind');
        $this->addSql('ALTER TABLE decision DROP required_approvals');
        $this->addSql('ALTER TABLE decision DROP respondent_id');
        $this->addSql('ALTER TABLE reflex DROP required_approvals');
    }
}
