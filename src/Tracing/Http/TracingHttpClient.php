<?php

declare(strict_types=1);

namespace App\Tracing\Http;

use App\Tracing\TraceContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * TracingHttpClient
 *
 * Decorator für Symfony HttpClient, der pro request() genau EIN Span erzeugt.
 * stream() bleibt unverändert (lazy), damit wir keine Streams “verfälschen”.
 */
final readonly class TracingHttpClient implements HttpClientInterface
{
    public function __construct(private HttpClientInterface $inner) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $safeUrl = $this->safeUrl($url);

        return TraceContext::span(
            'http.client.request',
            function () use ($method, $url, $options): ResponseInterface {
                return $this->inner->request($method, $url, $options);
            },
            [
                'method' => strtoupper($method),
                'url' => $safeUrl,
            ]
        );
    }

    /**
     * Symfony Contracts (Symfony 6/7):
     * stream(ResponseInterface|Traversable|array $responses, ?float $timeout = null): ResponseStreamInterface
     */
    public function stream(ResponseInterface|\Traversable|array $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options));
    }

    private function safeUrl(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p)) {
            return $url;
        }

        $scheme = $p['scheme'] ?? 'https';
        $host   = $p['host'] ?? '';
        $port   = isset($p['port']) ? ':' . (string) $p['port'] : '';
        $path   = $p['path'] ?? '';

        // absichtlich ohne Query/Fragment (keine Tokens, keine PII)
        return $scheme . '://' . $host . $port . $path;
    }
}
