<?php

declare(strict_types=1);

namespace App\AI\Usage;

/**
 * AiUsage
 *
 * Purpose:
 * - Small immutable value object representing token usage for a single AI call.
 * - Used as a normalized transport format between:
 *   - provider/SDK-specific responses (extracted by AiUsageExtractor)
 *   - cost tracking and aggregation (AiCostTracker / AiCostCalculator)
 *
 * Why this exists:
 * - Different providers expose usage in different shapes and naming conventions.
 * - We standardize that into three optional integers:
 *   - inputTokens   (prompt/input)
 *   - outputTokens  (completion/output)
 *   - totalTokens   (total = input + output)
 *
 * Notes:
 * - All fields are nullable because not every SDK provides all usage components.
 * - This object is intentionally "dumb": it does not know providers or pricing.
 * - Normalization/fallback behavior lives in withFallbackTotal().
 *
 * Immutability:
 * - The class is readonly; methods return a new instance instead of mutating state.
 */
final readonly class AiUsage
{
    /**
     * @param int|null $inputTokens  Input/prompt token count (best-effort).
     * @param int|null $outputTokens Output/completion token count (best-effort).
     * @param int|null $totalTokens  Total token count (best-effort).
     */
    public function __construct(
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $totalTokens,
    ) {}

    /**
     * Normalize and complete token information using best-effort fallbacks.
     *
     * Goals:
     * - Ensure downstream code (reports/cost calculation) receives stable token values.
     * - Avoid "all zero cost" outcomes when only partial usage is reported by a provider/SDK.
     *
     * Fallback rules:
     * 1) If totalTokens is missing but at least one part is known:
     *      totalTokens = (inputTokens ?? 0) + (outputTokens ?? 0)
     *
     * 2) If totalTokens is known but both inputTokens and outputTokens are missing:
     *      - Attribute the entire total to inputTokens
     *      - Set outputTokens to 0
     *
     *    Rationale:
     *    - Many providers reliably expose total tokens but not the split (depending on SDK wrapper).
     *    - For cost visibility and reporting we need *some* base for calculation.
     *    - Attributing total to inputTokens is conservative and unblocks EUR reporting immediately.
     *    - Once the extractor is improved (input/output split becomes available), this fallback
     *      becomes less relevant automatically.
     *
     * 3) If totalTokens is known and one part is known, the other is assumed 0:
     *      - (input known, output missing) => output = 0
     *      - (output known, input missing) => input = 0
     *
     * This method never throws and always returns a new instance.
     *
     * @return self Normalized AiUsage instance.
     */
    public function withFallbackTotal(): self
    {
        $in = $this->inputTokens;
        $out = $this->outputTokens;
        $tot = $this->totalTokens;

        // 1) If total is missing, compute it from parts (best-effort).
        if ($tot === null && ($in !== null || $out !== null)) {
            $tot = (int) (($in ?? 0) + ($out ?? 0));
        }

        // 2) If total exists but both parts are missing, attribute all to input.
        // This provides stable reporting/cost visibility even with limited SDK usage info.
        if ($tot !== null && $in === null && $out === null) {
            $in = $tot;
            $out = 0;
        }

        // 3) If one part exists, keep the other as 0 (stable, explicit values).
        if ($tot !== null && $in !== null && $out === null) {
            $out = 0;
        }
        if ($tot !== null && $in === null && $out !== null) {
            $in = 0;
        }

        return new self($in, $out, $tot);
    }

    /**
     * Indicate whether *any* token usage information is present.
     *
     * Used by AiUsageExtractor to decide if an extraction attempt was successful.
     *
     * @return bool True if at least one token field is non-null.
     */
    public function hasAny(): bool
    {
        return $this->inputTokens !== null
            || $this->outputTokens !== null
            || $this->totalTokens !== null;
    }
}
