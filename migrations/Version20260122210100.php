<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260122210100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ai_trace_spans table (spans for traces)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
CREATE TABLE ai_trace_spans (
  trace_id VARCHAR(36) NOT NULL,
  span_id VARCHAR(36) NOT NULL,
  parent_span_id VARCHAR(36) DEFAULT NULL,
  sequence INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  started_at_ms BIGINT NOT NULL,
  ended_at_ms BIGINT NOT NULL,
  duration_ms INT NOT NULL,
  meta_json LONGTEXT DEFAULT NULL,
  PRIMARY KEY(trace_id, span_id),
  INDEX idx_trace_seq (trace_id, sequence),
  INDEX idx_parent (trace_id, parent_span_id),
  CONSTRAINT fk_ai_trace_spans_trace
    FOREIGN KEY (trace_id) REFERENCES ai_traces(trace_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_trace_spans');
    }
}
