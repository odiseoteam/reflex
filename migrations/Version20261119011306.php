<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261119011306 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add OAuth clients and grants for the MCP server';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_client (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, refreshed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, client_id VARCHAR(2048) NOT NULL, name VARCHAR(255) NOT NULL, redirect_uris JSONB NOT NULL, metadata_document BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AD73274D19EB6921 ON oauth_client (client_id)');
        $this->addSql('CREATE TABLE oauth_grant (id UUID NOT NULL, code_hash VARCHAR(64) NOT NULL, code_expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, code_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, access_hash VARCHAR(64) DEFAULT NULL, access_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, refresh_hash VARCHAR(64) DEFAULT NULL, previous_refresh_hash VARCHAR(64) DEFAULT NULL, refresh_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, scopes JSONB NOT NULL, resource VARCHAR(2048) NOT NULL, redirect_uri VARCHAR(2048) NOT NULL, code_challenge VARCHAR(128) NOT NULL, client_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4E068C5DE7530879 ON oauth_grant (code_hash)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4E068C5D276104BE ON oauth_grant (access_hash)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4E068C5D7B1870FA ON oauth_grant (refresh_hash)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4E068C5DD6CD32B7 ON oauth_grant (previous_refresh_hash)');
        $this->addSql('CREATE INDEX IDX_4E068C5D19EB6921 ON oauth_grant (client_id)');
        $this->addSql('CREATE INDEX IDX_4E068C5DA76ED395 ON oauth_grant (user_id)');
        $this->addSql('ALTER TABLE oauth_grant ADD CONSTRAINT FK_4E068C5D19EB6921 FOREIGN KEY (client_id) REFERENCES oauth_client (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_grant ADD CONSTRAINT FK_4E068C5DA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_grant DROP CONSTRAINT FK_4E068C5D19EB6921');
        $this->addSql('ALTER TABLE oauth_grant DROP CONSTRAINT FK_4E068C5DA76ED395');
        $this->addSql('DROP TABLE oauth_client');
        $this->addSql('DROP TABLE oauth_grant');
    }
}
