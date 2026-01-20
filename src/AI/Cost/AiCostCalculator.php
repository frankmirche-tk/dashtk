<?php

declare(strict_types=1);

namespace App\AI\Cost;

use App\AI\Usage\AiUsage;

/**
 * AiCostCalculator
 *
 * Purpose:
 * - Calculates estimated AI costs in EUR based on:
 *   - provider (e.g. "openai", "gemini")
 *   - concrete model identifier (env-driven, e.g. "gpt-4o-mini")
 *   - token usage (input / output tokens)
 *
 * Design principles:
 * - Pricing is fully configuration-driven (services.yaml / parameters).
 * - No hardcoded provider or model names.
 * - Missing or unknown pricing gracefully returns NULL (no cost attribution).
 *
 * Important:
 * - This class does NOT decide *whether* a request is billable.
 * - It only performs a deterministic cost calculation *if* pricing exists.
 * - Aggregation, error handling and persistence are handled by AiCostTracker.
 */
final readonly class AiCostCalculator
{
    /**
     * Pricing configuration injected from parameters.
     *
     * Expected structure:
     *
     * [
     *   'openai' => [
     *     'gpt-4o-mini' => [
     *       'input_per_1k'  => 0.00015,
     *       'output_per_1k' => 0.00060,
     *     ],
     *     'default' => [
     *       'input_per_1k'  => ...,
     *       'output_per_1k' => ...,
     *     ],
     *   ],
     *   'gemini' => [
     *     'models/gemini-2.5-flash' => [ ... ],
     *   ],
     * ]
     *
     * Notes:
     * - Model keys must match the *exact* model string used at runtime
     *   (usually sourced from env.local).
     * - A provider-level "default" pricing entry is optional but recommended
     *   as a safety net for newly introduced models.
     *
     * @param array<string, array<string, array{input_per_1k: float, output_per_1k: float}>> $pricing
     */
    public function __construct(
        private array $pricing
    ) {}

    /**
     * Calculate estimated cost in EUR for a single AI request.
     *
     * Behavior:
     * - Normalizes provider and model identifiers.
     * - Resolves pricing in two steps:
     *   1) Exact model pricing (preferred)
     *   2) Provider-level "default" pricing (fallback)
     * - Uses token counts from AiUsage.
     * - Returns NULL if no pricing can be resolved.
     *
     * Why NULL instead of 0.0?
     * - NULL explicitly signals "cost unknown / not billable"
     * - This avoids silently masking configuration errors.
     *
     * @param string  $provider Normalized provider key (e.g. "openai", "gemini").
     * @param string  $model    Exact model identifier used in the request.
     * @param AiUsage $usage    Token usage extracted from the provider response.
     *
     * @return float|null Calculated cost in EUR or NULL if pricing is unavailable.
     */
    public function calculateEur(string $provider, string $model, AiUsage $usage): ?float
    {
        // Normalize identifiers for stable lookup
        $provider = strtolower(trim($provider));
        $model    = trim($model) !== '' ? trim($model) : 'unknown';

        // Provider pricing block
        $pricingByProvider = $this->pricing[$provider] ?? null;
        if (!is_array($pricingByProvider)) {
            return null;
        }

        // 1) Try exact model pricing
        $pricing = $pricingByProvider[$model] ?? null;

        // 2) Fallback to provider default pricing (optional)
        if ($pricing === null) {
            $pricing = $pricingByProvider['default'] ?? null;
        }

        // No usable pricing → no cost attribution
        if (!is_array($pricing)) {
            return null;
        }

        // Prices are defined per 1,000 tokens
        $inPer1k  = (float) ($pricing['input_per_1k']  ?? 0.0);
        $outPer1k = (float) ($pricing['output_per_1k'] ?? 0.0);

        // Ensure total tokens are available if only partial usage was reported
        $usage = $usage->withFallbackTotal();

        $in  = (int) ($usage->inputTokens  ?? 0);
        $out = (int) ($usage->outputTokens ?? 0);

        // Final EUR calculation
        return
            ($in  / 1000) * $inPer1k +
            ($out / 1000) * $outPer1k;
    }
}
