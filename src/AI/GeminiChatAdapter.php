<?php

declare(strict_types=1);

namespace App\AI;

use App\Tracing\Trace;
use App\Tracing\TraceContext;
use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use ModelflowAi\Chat\Request\AIChatRequest;
use ModelflowAi\Chat\Response\AIChatResponse;

final readonly class GeminiChatAdapter implements AIChatAdapterInterface
{
    public function __construct(
        private AIChatAdapterInterface $inner,
    ) {}

    public function supports(object $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handleRequest(object $request): AIChatResponse
    {
        /** @var Trace|null $trace */
        $trace = TraceContext::get();

        if (!$trace) {
            // Wichtig: wir fangen hier auch den "single-Part" Fehler ab,
            // damit er nicht bis in den SupportChatService hoch knallt.
            try {
                return $this->inner->handleRequest($request);
            } catch (\RuntimeException $e) {
                if ($this->isGeminiNonSimpleTextError($e)) {
                    return $this->makeNonSimpleTextFallbackResponse();
                }
                throw $e;
            }
        }

        return $trace->span('adapter.gemini.handleRequest', function () use ($trace, $request) {
            $meta = [
                'request_class' => is_object($request) ? $request::class : gettype($request),
            ];

            if ($request instanceof AIChatRequest && method_exists($request, 'getMessages')) {
                $msgs = $request->getMessages();
                $meta['messages_type'] = is_object($msgs) ? $msgs::class : gettype($msgs);
                $meta['messages_count'] = is_array($msgs) ? count($msgs) : ($msgs instanceof \Countable ? count($msgs) : null);
            }

            return $trace->span('adapter.gemini.vendor_call', function () use ($request) {
                try {
                    return $this->inner->handleRequest($request);
                } catch (\RuntimeException $e) {
                    // Ziel in Schritt 1: diesen konkreten "single-Part" Fehler abfangen,
                    // damit es keinen Hard-Fail gibt.
                    if ($this->isGeminiNonSimpleTextError($e)) {
                        return $this->makeNonSimpleTextFallbackResponse();
                    }

                    throw $e;
                }
            }, $meta);

        }, [
            'inner_class' => $this->inner::class,
        ]);
    }

    private function isGeminiNonSimpleTextError(\RuntimeException $e): bool
    {
        $m = $e->getMessage();

        // exakt der Fehler aus deinem Log:
        // "GenerateContentResponse::text() quick accessor only works for simple (single-Part) text responses..."
        return str_contains($m, 'GenerateContentResponse::text()')
            && str_contains($m, 'only works for simple (single-')
            && str_contains($m, 'parts');
    }

    /**
     * Fallback nur für Schritt 1 (single-Part Problem):
     * Wir liefern eine saubere, kurze Antwort zurück, statt Exception.
     *
     * Hinweis: Wir bauen hier absichtlich KEIN parts()-Parsing ein,
     * weil das sauber im eigentlichen Response-Extractor / Inner-Adapter passieren sollte.
     */
    private function makeNonSimpleTextFallbackResponse(): AIChatResponse
    {
        $msg = 'Fehler beim Erzeugen der Antwort (Gemini): Die Antwort kam als Multi-Part zurück und konnte nicht als einfacher Text gelesen werden.';

        // Versuche, eine AIChatResponse sauber zu erzeugen – abhängig davon,
        // welche Factory/Signatur eure ModelflowAi-Version anbietet.
        if (method_exists(AIChatResponse::class, 'fromText')) {
            /** @phpstan-ignore-next-line */
            return AIChatResponse::fromText($msg);
        }
        if (method_exists(AIChatResponse::class, 'fromString')) {
            /** @phpstan-ignore-next-line */
            return AIChatResponse::fromString($msg);
        }

        // Letzter Versuch: Constructor mit string (falls vorhanden).
        try {
            /** @phpstan-ignore-next-line */
            return new AIChatResponse($msg);
        } catch (\Throwable) {
            // Wenn wir keine Response bauen können, lieber sauber RuntimeException werfen,
            // damit es im DEV sichtbar bleibt (statt fatal/undefined behaviour).
            throw new \RuntimeException($msg);
        }
    }
}
