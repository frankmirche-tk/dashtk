<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251219091702 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE support_solution (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, symptoms LONGTEXT NOT NULL, context_notes LONGTEXT DEFAULT NULL, priority INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_solution_keyword (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, keyword VARCHAR(80) NOT NULL, weight SMALLINT UNSIGNED DEFAULT 1 NOT NULL, solution_id BIGINT UNSIGNED NOT NULL, INDEX IDX_119E2E41C0BE183 (solution_id), UNIQUE INDEX uniq_solution_keyword (solution_id, keyword), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_solution_step (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, step_no INT NOT NULL, instruction LONGTEXT NOT NULL, expected_result LONGTEXT DEFAULT NULL, next_if_failed LONGTEXT DEFAULT NULL, solution_id BIGINT UNSIGNED NOT NULL, INDEX IDX_B5241AB41C0BE183 (solution_id), UNIQUE INDEX uniq_solution_step (solution_id, step_no), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE support_solution_keyword ADD CONSTRAINT FK_119E2E41C0BE183 FOREIGN KEY (solution_id) REFERENCES support_solution (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE support_solution_step ADD CONSTRAINT FK_B5241AB41C0BE183 FOREIGN KEY (solution_id) REFERENCES support_solution (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE support_solution_keyword DROP FOREIGN KEY FK_119E2E41C0BE183');
        $this->addSql('ALTER TABLE support_solution_step DROP FOREIGN KEY FK_B5241AB41C0BE183');
        $this->addSql('DROP TABLE support_solution');
        $this->addSql('DROP TABLE support_solution_keyword');
        $this->addSql('DROP TABLE support_solution_step');
    }
}
