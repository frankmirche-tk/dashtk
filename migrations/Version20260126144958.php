<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260126144958 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // neuer Code
        $this->addSql("
            ALTER TABLE support_solution
              ADD COLUMN category VARCHAR(32) NOT NULL DEFAULT 'GENERAL' AFTER type,
              ADD COLUMN published_at DATETIME NULL AFTER updated_at,
              ADD COLUMN newsletter_year INT NULL AFTER published_at,
              ADD COLUMN newsletter_kw TINYINT UNSIGNED NULL AFTER newsletter_year,
              ADD COLUMN newsletter_edition VARCHAR(16) NULL AFTER newsletter_kw
        ");

        $this->addSql("
            CREATE INDEX idx_solution_category_published
              ON support_solution (category, published_at)
        ");

        $this->addSql("
            CREATE INDEX idx_solution_newsletter_kw
              ON support_solution (newsletter_year, newsletter_kw, newsletter_edition)
        ");
    }

    public function down(Schema $schema): void
    {
        // neuer Code
        $this->addSql("DROP INDEX idx_solution_category_published ON support_solution");
        $this->addSql("DROP INDEX idx_solution_newsletter_kw ON support_solution");

        $this->addSql("
            ALTER TABLE support_solution
              DROP COLUMN category,
              DROP COLUMN published_at,
              DROP COLUMN newsletter_year,
              DROP COLUMN newsletter_kw,
              DROP COLUMN newsletter_edition
        ");
    }
}
