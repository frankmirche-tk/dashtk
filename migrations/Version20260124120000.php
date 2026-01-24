<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260124120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recreate ai_traces and ai_trace_spans tables if they were dropped by schema diff migrations.';
    }

    public function up(Schema $schema): void
    {
        // ai_traces
        $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS `ai_traces` (
  `trace_id` varchar(36) NOT NULL,
  `view` varchar(120) DEFAULT NULL,
  `exported_at` datetime NOT NULL,
  PRIMARY KEY (`trace_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // ai_trace_spans
        $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS `ai_trace_spans` (
  `trace_id` varchar(36) NOT NULL,
  `span_id` varchar(36) NOT NULL,
  `parent_span_id` varchar(36) DEFAULT NULL,
  `sequence` int(11) NOT NULL,
  `name` varchar(190) NOT NULL,
  `started_at_ms` bigint(20) NOT NULL,
  `ended_at_ms` bigint(20) NOT NULL,
  `duration_ms` int(11) NOT NULL,
  `meta_json` longtext DEFAULT NULL,
  PRIMARY KEY (`trace_id`,`span_id`),
  KEY `idx_trace_seq` (`trace_id`,`sequence`),
  KEY `idx_parent` (`trace_id`,`parent_span_id`),
  CONSTRAINT `fk_ai_trace_spans_trace`
    FOREIGN KEY (`trace_id`) REFERENCES `ai_traces` (`trace_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        // We intentionally do not drop these tables automatically.
        // If you really want to remove them, do it explicitly.
        $this->addSql('DROP TABLE IF EXISTS `ai_trace_spans`');
        $this->addSql('DROP TABLE IF EXISTS `ai_traces`');
    }
}
