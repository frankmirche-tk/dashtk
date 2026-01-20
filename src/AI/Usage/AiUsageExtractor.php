<?php

declare(strict_types=1);

namespace App\AI\Usage;

/**
 * AiUsageExtractor
 *
 * Purpose:
 * - Extracts token usage information (input/output/total) from provider response objects.
 * - Implements a best-effort, provider-agnostic extraction strategy to support multiple SDKs
 *   (OpenAI, Gemini, Modelflow adapters, and future providers).
 *
 * Why this exists:
 * - Different providers/SDKs expose token usage in different shapes:
 *   - OpenAI-style: prompt_tokens / completion_tokens / total_tokens
 *   - Generic:      input_tokens / output_tokens / total_tokens
 *   - Gemini-like:  promptTokenCount / candidatesTokenCount / totalTokenCount
 * - Some libraries (e.g. Modelflow) wrap provider responses and hide usage inside:
 *   - response->getUsage()
 *   - response->getMetadata() / getMeta()
 *   - response->getMessage()
 *   - nested array/object payloads
 *
 * Design principles:
 * - Best-effort only: never throw in production and never block the chat flow.
 * - Conservative: returns AiUsage(null, null, null) if nothing can be found.
 * - Extensible: new provider/SDK shapes can be supported by adding key candidates
 *   or new "probe" layers without changing call sites.
 *
 * Usage:
 * - Called by AiChatGateway (after receiving provider response).
 * - Result is forwarded to AiCostTracker for aggregation and EUR calculation.
 *
 * Notes:
 * - This extractor does NOT validate pricing or cost; it only extracts tokens.
 * - AiUsage::withFallbackTotal() is applied to produce a stable output even when:
 *   - only total tokens are known
 *   - only input/output tokens are known
 */
final class AiUsageExtractor
{
    /**
     * Extract usage tokens from a provider response object (best-effort).
     *
     * Extraction strategy (progressive fallbacks):
     * 1) Direct extraction from the response object itself:
     *    - response->getUsage()
     *    - response->usage property
     *    - public props / array-like representations
     *
     * 2) Extraction from the response message payload:
     *    - response->getMessage()
     *
     * 3) Extraction from meta/metadata containers:
     *    - response->getMetadata()
     *    - response->getMeta()
     *
     * 4) Extraction from array/json representations:
     *    - response->toArray()
     *    - response->jsonSerialize()
     *
     * Supported field-name variants (examples):
     * - OpenAI:
     *   - prompt_tokens, completion_tokens, total_tokens
     * - Generic:
     *   - input_tokens, output_tokens, total_tokens
     * - Gemini-like / Google:
     *   - promptTokenCount, candidatesTokenCount, totalTokenCount
     *
     * Safety:
     * - Must never throw exceptions during runtime.
     * - In the worst case, returns AiUsage(null, null, null).
     *
     * @param mixed $response Provider response (object/array/mixed).
     *
     * @return AiUsage Extracted usage token information (best-effort).
     */
    public function extract(mixed $response): AiUsage
    {
        // 1) Direct response usage object/array
        $usage = $this->extractFromAny($response);
        if ($usage->hasAny()) {
            return $usage;
        }

        // 2) Try response->getMessage()
        if (is_object($response) && method_exists($response, 'getMessage')) {
            $msg = $response->getMessage();
            $usage = $this->extractFromAny($msg);
            if ($usage->hasAny()) {
                return $usage;
            }
        }

        // 3) Try response->getMetadata()/getMeta()
        if (is_object($response)) {
            foreach (['getMetadata', 'getMeta'] as $m) {
                if (method_exists($response, $m)) {
                    $meta = $response->{$m}();
                    $usage = $this->extractFromAny($meta);
                    if ($usage->hasAny()) {
                        return $usage;
                    }
                }
            }
        }

        // 4) If response can be cast to array via toArray/jsonSerialize, try that
        if (is_object($response)) {
            foreach (['toArray', 'jsonSerialize'] as $m) {
                if (method_exists($response, $m)) {
                    $arr = $response->{$m}();
                    $usage = $this->extractFromAny($arr);
                    if ($usage->hasAny()) {
                        return $usage;
                    }
                }
            }
        }

        // Nothing found
        return new AiUsage(null, null, null);
    }

    /**
     * Normalize "anything" into AiUsage by probing common shapes.
     *
     * Supported inputs:
     * - AiUsage instance
     * - array (recursively scanned)
     * - object (usage getters/properties + public vars scanned)
     *
     * @param mixed $x Any value (object/array/scalar).
     *
     * @return AiUsage Best-effort usage information.
     */
    private function extractFromAny(mixed $x): AiUsage
    {
        // Already normalized
        if ($x instanceof AiUsage) {
            return $x->withFallbackTotal();
        }

        // Arrays: scan recursively
        if (is_array($x)) {
            return $this->extractFromArrayRecursive($x);
        }

        // Scalars: no usage possible
        if (!is_object($x)) {
            return new AiUsage(null, null, null);
        }

        // Objects: try common usage getters / properties first
        // (Many SDKs provide getUsage(), while others expose a "usage" property)
        foreach (['getUsage', 'usage'] as $u) {
            $usageVal = null;

            if (method_exists($x, $u)) {
                $usageVal = $x->{$u}();
            } elseif (property_exists($x, $u)) {
                $usageVal = $x->{$u};
            }

            if ($usageVal !== null) {
                $usage = $this->extractFromAny($usageVal);
                if ($usage->hasAny()) {
                    return $usage;
                }
            }
        }

        // Public properties: best-effort scan
        // This often works for DTO-like objects and json-decoded stdClass payloads.
        $vars = get_object_vars($x);
        if ($vars !== []) {
            $usage = $this->extractFromArrayRecursive($vars);
            if ($usage->hasAny()) {
                return $usage;
            }
        }

        return new AiUsage(null, null, null);
    }

    /**
     * Extract tokens from an array recursively, using common key variants.
     *
     * Strategy:
     * - First try to match "flat" token keys at the current array level.
     * - If not found, scan nested arrays/objects depth-first.
     *
     * @param array $a Any nested array payload (decoded JSON, toArray output, etc.)
     *
     * @return AiUsage Best-effort usage info extracted from this structure.
     */
    private function extractFromArrayRecursive(array $a): AiUsage
    {
        // First: direct known shapes at this level
        $direct = $this->extractFromArrayFlat($a);
        if ($direct->hasAny()) {
            return $direct;
        }

        // Then: scan nested arrays/objects
        foreach ($a as $v) {
            if (is_array($v)) {
                $u = $this->extractFromArrayRecursive($v);
                if ($u->hasAny()) {
                    return $u;
                }
            } elseif (is_object($v)) {
                $u = $this->extractFromAny($v);
                if ($u->hasAny()) {
                    return $u;
                }
            }
        }

        return new AiUsage(null, null, null);
    }

    /**
     * Extract tokens if array contains relevant keys at the current level.
     *
     * Implementation detail:
     * - Keys are normalized to lowercase to support mixed provider casing
     *   (e.g. totalTokenCount vs total_tokens).
     * - Values are parsed as int where possible.
     * - Result is normalized via AiUsage::withFallbackTotal() to avoid incomplete outputs.
     *
     * @param array $a Flat array fragment (no recursion here).
     *
     * @return AiUsage Usage extracted at this level (or null-values if not found).
     */
    private function extractFromArrayFlat(array $a): AiUsage
    {
        // Normalize keys (case-insensitive)
        $keys = [];
        foreach ($a as $k => $v) {
            if (is_string($k)) {
                $keys[strtolower($k)] = $v;
            }
        }

        // Common / OpenAI-style keys
        $in  = $this->pickInt($keys, ['prompt_tokens', 'input_tokens', 'prompttokencount', 'inputtokencount']);
        $out = $this->pickInt($keys, ['completion_tokens', 'output_tokens', 'candidatestokencount', 'outputtokencount']);
        $tot = $this->pickInt($keys, ['total_tokens', 'totaltokencount', 'totaltokens']);

        // Additional variants sometimes observed in SDK wrappers
        if ($in === null) {
            $in = $this->pickInt($keys, ['prompttoken', 'prompt_tokens_count', 'input_token_count']);
        }
        if ($out === null) {
            $out = $this->pickInt($keys, ['candidatestokens', 'candidates_tokens', 'output_token_count']);
        }
        if ($tot === null) {
            $tot = $this->pickInt($keys, ['total_tokens_count', 'total_token_count']);
        }

        $usage = new AiUsage($in, $out, $tot);

        // Normalize output for stable downstream behavior
        return $usage->withFallbackTotal();
    }

    /**
     * Pick the first integer-like value from a set of candidate keys.
     *
     * Supported value formats:
     * - int
     * - float (rounded)
     * - numeric string consisting of digits
     *
     * @param array<string, mixed> $keys       Lowercased key => value map.
     * @param array<int, string>   $candidates Candidate key names (case-insensitive).
     *
     * @return int|null Parsed integer value or null if none found.
     */
    private function pickInt(array $keys, array $candidates): ?int
    {
        foreach ($candidates as $c) {
            $c = strtolower($c);
            if (!array_key_exists($c, $keys)) {
                continue;
            }

            $v = $keys[$c];

            if (is_int($v)) {
                return $v;
            }
            if (is_float($v)) {
                return (int) round($v);
            }
            if (is_string($v) && $v !== '' && preg_match('/^\d+$/', $v)) {
                return (int) $v;
            }
        }

        return null;
    }
}
