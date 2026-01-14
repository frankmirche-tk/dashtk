<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260114144354 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE support_solution_step ADD media_path VARCHAR(255) DEFAULT NULL, ADD media_original_name VARCHAR(255) DEFAULT NULL, ADD media_mime_type VARCHAR(100) DEFAULT NULL, ADD media_size INT DEFAULT NULL, ADD media_updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE support_solution_step DROP media_path, DROP media_original_name, DROP media_mime_type, DROP media_size, DROP media_updated_at');
    }
}
