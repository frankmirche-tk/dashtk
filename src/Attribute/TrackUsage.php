<?php

declare(strict_types=1);

namespace App\Attribute;

/**
 * Marks a method as a usage-tracked entry point.
 *
 * Purpose:
 * Declares that a method represents a business-relevant entry point
 * whose runtime usage should be measured explicitly and evaluated
 * later for relevance, impact, and maintenance decisions.
 *
 * Design principles:
 * - Declarative only (no runtime logic).
 * - Does NOT increment counters and does NOT access cache, DB, or services.
 * - Used only to mark *entry points* (public services, controllers, commands).
 * - Internal/helper methods must NOT use this attribute.
 *
 * Business relevance:
 * The optional weight expresses the relative business importance of
 * the entry point and is used to calculate an impact score:
 *
 *   impact = usage_count * weight
 *
 * Suggested weight scale:
 * - 1–2  : low relevance / helper-like entry points
 * - 3–5  : normal business relevance
 * - 6–8  : high relevance (core workflows)
 * - 9–10 : critical (revenue, operations, compliance)
 *
 * Usage:
 * #[TrackUsage('support_chat.ask', weight: 5)]
 * public function ask(...) { ... }
 *
 * Runtime counting is handled explicitly via UsageTracker
 * inside the method body.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class TrackUsage
{
    public function __construct(
        public readonly string $key,
        public readonly int $weight = 1

    ) {}
}
