<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260122210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ai_traces table (trace header/export metadata)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
CREATE TABLE ai_traces (
  trace_id VARCHAR(36) NOT NULL,
  view VARCHAR(120) DEFAULT NULL,
  exported_at DATETIME NOT NULL,
  PRIMARY KEY(trace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_traces');
    }
}
