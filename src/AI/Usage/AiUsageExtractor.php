<?php

declare(strict_types=1);

namespace App\AI\Usage;

final class AiUsageExtractor
{
    /**
     * Extract usage tokens from a provider response object (best-effort).
     *
     * Strategy:
     * - Try common methods/properties on the response itself
     * - Try message object (response->getMessage())
     * - Try meta/metadata containers
     * - Try array/json representations
     *
     * Supported field name variants (common):
     * - OpenAI: prompt_tokens, completion_tokens, total_tokens
     * - Generic: input_tokens, output_tokens, total_tokens
     * - Gemini-like: promptTokenCount, candidatesTokenCount, totalTokenCount (and other variations)
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

        return new AiUsage(null, null, null);
    }

    private function extractFromAny(mixed $x): AiUsage
    {
        // If it's already an AiUsage instance
        if ($x instanceof AiUsage) {
            return $x->withFallbackTotal();
        }

        // Arrays: scan recursively
        if (is_array($x)) {
            return $this->extractFromArrayRecursive($x);
        }

        if (!is_object($x)) {
            return new AiUsage(null, null, null);
        }

        // Objects: try common usage getters / properties
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

        // Try extracting from public props (best-effort)
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
     */
    private function extractFromArrayRecursive(array $a): AiUsage
    {
        // First: direct known shapes
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
     * Extract tokens if array contains relevant keys at this level.
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

        // OpenAI common keys
        $in  = $this->pickInt($keys, ['prompt_tokens', 'input_tokens', 'prompttokencount', 'inputtokencount']);
        $out = $this->pickInt($keys, ['completion_tokens', 'output_tokens', 'candidatestokencount', 'outputtokencount']);
        $tot = $this->pickInt($keys, ['total_tokens', 'totaltokencount', 'totaltokens']);

        // Some Gemini / generic responses may use different names
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

        return $usage->withFallbackTotal();
    }

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
