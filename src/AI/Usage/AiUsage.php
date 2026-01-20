<?php

declare(strict_types=1);

namespace App\AI\Usage;

final readonly class AiUsage
{
    public function __construct(
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $totalTokens,
    ) {}

    public function withFallbackTotal(): self
    {
        $in = $this->inputTokens;
        $out = $this->outputTokens;
        $tot = $this->totalTokens;

        // If total is missing, compute it from parts (best-effort)
        if ($tot === null && ($in !== null || $out !== null)) {
            $tot = (int) (($in ?? 0) + ($out ?? 0));
        }

        // If total exists but parts are missing, attribute all to input.
        // This is safe for cost estimation and unblocks EUR reporting immediately.
        if ($tot !== null && $in === null && $out === null) {
            $in = $tot;
            $out = 0;
        }

        // If one part exists, keep the other as 0
        if ($tot !== null && $in !== null && $out === null) {
            $out = 0;
        }
        if ($tot !== null && $in === null && $out !== null) {
            $in = 0;
        }

        return new self($in, $out, $tot);
    }



    public function hasAny(): bool
    {
        return $this->inputTokens !== null
            || $this->outputTokens !== null
            || $this->totalTokens !== null;
    }

}
