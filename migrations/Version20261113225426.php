<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113225426 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create connections and their encrypted OAuth credentials';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE connection (id UUID NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(40) NOT NULL, url VARCHAR(2048) NOT NULL, auth VARCHAR(20) NOT NULL, oauth_client_id VARCHAR(255) DEFAULT NULL, oauth_issuer VARCHAR(2048) DEFAULT NULL, encrypted_secret TEXT DEFAULT NULL, status VARCHAR(30) NOT NULL, last_error TEXT DEFAULT NULL, tools JSONB NOT NULL, tools_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_29F77366989D9B62 ON connection (slug)');
        $this->addSql('CREATE TABLE connection_credentials (data TEXT NOT NULL, connection_id UUID NOT NULL, PRIMARY KEY (connection_id))');
        $this->addSql('ALTER TABLE connection_credentials ADD CONSTRAINT FK_D8D5C4F2DD03F01 FOREIGN KEY (connection_id) REFERENCES connection (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connection_credentials DROP CONSTRAINT FK_D8D5C4F2DD03F01');
        $this->addSql('DROP TABLE connection');
        $this->addSql('DROP TABLE connection_credentials');
    }
}
