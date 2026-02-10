<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ExternalUrlInspector
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   reason?: string,
     *   status?: int,
     *   contentType?: string,
     *   finalUrl?: string
     * }
     */
    public function inspect(string $url): array
    {
        $url = trim($url);

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'reason' => 'invalid_url'];
        }

        // Nur http(s) zulassen
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
            return ['ok' => false, 'reason' => 'invalid_scheme'];
        }

        try {
            // HEAD ist oft geblockt; wir nutzen GET mit kleinem Download-Limit
            $response = $this->httpClient->request('GET', $url, [
                'max_redirects' => 5,
                'timeout' => 8,
                // wir wollen nicht riesige Downloads ziehen – nur Header + ersten Chunk
                'headers' => [
                    'User-Agent' => 'DashTK/1.0 (+external-url-inspector)',
                ],
            ]);

            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);

            $contentType = $headers['content-type'][0] ?? null;

            // final URL nach redirects (falls vorhanden)
            $finalUrl = $headers['x-symfony-final-url'][0] ?? null; // je nach Symfony-Version nicht immer vorhanden

            if ($status >= 400) {
                return [
                    'ok' => false,
                    'reason' => 'http_error',
                    'status' => $status,
                    'contentType' => $contentType,
                    'finalUrl' => $finalUrl,
                ];
            }

            // Wenn HTML zurückkommt, ist es sehr oft Login/Viewer (kein Direktdownload)
            if (is_string($contentType) && str_starts_with(strtolower($contentType), 'text/html')) {
                // nur einen kleinen Ausschnitt lesen
                $snippet = mb_strtolower(substr($response->getContent(false), 0, 2000));

                // Heuristiken (Google Login / Permission / Viewer)
                $looksLikeLogin = str_contains($snippet, 'accounts.google.com')
                    || str_contains($snippet, 'sign in')
                    || str_contains($snippet, 'anmelden')
                    || str_contains($snippet, 'cookie')
                    || str_contains($snippet, 'consent');

                if ($looksLikeLogin) {
                    return [
                        'ok' => false,
                        'reason' => 'html_login_or_consent',
                        'status' => $status,
                        'contentType' => $contentType,
                        'finalUrl' => $finalUrl,
                    ];
                }

                return [
                    'ok' => false,
                    'reason' => 'html_response',
                    'status' => $status,
                    'contentType' => $contentType,
                    'finalUrl' => $finalUrl,
                ];
            }

            // Wenn nicht HTML: erstmal OK (Datei oder zumindest nicht Login-Seite)
            return [
                'ok' => true,
                'status' => $status,
                'contentType' => $contentType,
                'finalUrl' => $finalUrl,
            ];
        } catch (TransportExceptionInterface $e) {
            return ['ok' => false, 'reason' => 'transport_error'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'unknown_error'];
        }
    }
}
