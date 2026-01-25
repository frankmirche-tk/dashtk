<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260125211600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

  
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE support_solution
            ADD type VARCHAR(16) NOT NULL,
            ADD media_type VARCHAR(16) DEFAULT NULL,
            ADD external_media_provider VARCHAR(64) DEFAULT NULL,
            ADD external_media_url VARCHAR(2048) DEFAULT NULL,
            ADD external_media_id VARCHAR(255) DEFAULT NULL,
            CHANGE symptoms symptoms LONGTEXT DEFAULT NULL
        ");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs

    }
}
