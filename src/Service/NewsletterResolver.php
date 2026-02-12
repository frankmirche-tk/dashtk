<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SupportSolution;
use App\Repository\SupportSolutionRepository;
use Psr\Log\LoggerInterface;


final class NewsletterResolver
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly SupportSolutionRepository $solutions,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $kbMatches
     * @return array<string,mixed>|null
     */
    public function resolve(string $message, array $kbMatches = []): ?array
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        if (!$this->detectNewsletterIntent($message)) {
            return null;
        }

        // Zeitraum optional: wenn keiner angegeben ist -> "alle"
        $range = $this->parseNewsletterDateRange($message);
        $explicitRange = $range !== null;

        $from = $range['from'] ?? new \DateTimeImmutable('2000-01-01 00:00:00');
        $to   = $range['to']   ?? (new \DateTimeImmutable('now'))->modify('+10 years')->setTime(23, 59, 59);

        // Keywords (ohne Steuerwörter wie "newsletter")
        $tokens = $this->extractKeywordTokens($message, ['newsletter', 'news', 'letter', 'kw', 'kalenderwoche']);
        $tokens = $this->normalizeNewsletterTokens($tokens);

        if ($tokens === []) {
            return [
                'answer' => "Für Newsletter brauche ich mindestens **1 Keyword** (z.B. „Reduzierungen“, „Filialperformance“, „Sale“, „Rabatt“).\n"
                    . "Optional kannst du zusätzlich einen Zeitraum angeben (z.B. „seit 01.01.2026“ oder „01.01.2025 - 31.03.2025“).",
                'matches' => [],
                'choices' => [],
                'modeHint' => 'newsletter_need_keyword',
            ];
        }

        $this->logger->info('newsletter.resolve', [
            'tokens' => $tokens,
            'explicitRange' => $explicitRange,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);

        $rows = $this->solutions->findNewsletterMatches($tokens, $from, $to, 0, self::PAGE_SIZE);

        $mapped = [];
        foreach ($rows as $r) {
            $s = $r['solution'] ?? null;
            if (!$s instanceof SupportSolution) {
                continue;
            }

            // Newsletter/Formulare sind FORM-Dokumente
            if ($s->getType() !== 'FORM') {
                continue;
            }

            $publishedAt = $s->getPublishedAt();

            // ✅ Wenn ein Zeitraum explizit angegeben wurde: STRICT nach publishedAt filtern (publishedAt ist Pflicht)
            if ($explicitRange) {
                if (!$publishedAt instanceof \DateTimeImmutable) {
                    continue;
                }
                if ($publishedAt < $from || $publishedAt > $to) {
                    continue;
                }
            } else {
                // Fallback: createdAt/publishedAt in Range (Repo kann je nach Query auf createdAt matchen)
                $dt = $publishedAt ?? $s->getCreatedAt();
                if ($dt < $from || $dt > $to) {
                    continue;
                }
            }

            $mapped[] = [
                'id' => (int)$s->getId(),
                'title' => (string)$s->getTitle(),
                'score' => (int)($r['score'] ?? 0),
                'url' => '/api/support_solutions/' . (int)$s->getId(),
                'type' => 'FORM',
                'category' => 'NEWSLETTER',
                'publishedAt' => $publishedAt?->format('Y-m-d') ?? '',
                'updatedAt' => $s->getUpdatedAt()->format('Y-m-d H:i'),
                'symptoms' => (string)($s->getSymptoms() ?? ''),

                'mediaType' => $s->getMediaType(),
                'externalMediaProvider' => $s->getExternalMediaProvider(),
                'externalMediaUrl' => $s->getExternalMediaUrl(),
                'externalMediaId' => $s->getExternalMediaId(),
            ];
        }

        // Neueste zuerst: publishedAt, fallback createdAt über URL nicht verfügbar -> stabil über score + title
        usort($mapped, static function (array $a, array $b) {
            return strcmp((string)($b['publishedAt'] ?? ''), (string)($a['publishedAt'] ?? ''));
        });

        if ($mapped === []) {
            $rangeHint = $explicitRange
                ? (" (Zeitraum: " . $from->format('Y-m-d') . " bis " . $to->format('Y-m-d') . ")")
                : '';

            return [
                'answer' => "Ich habe **keinen Newsletter** gefunden, der zu **" . implode(', ', $tokens) . "** passt{$rangeHint}.",
                'matches' => [],
                'choices' => [],
                'modeHint' => 'newsletter_empty',
            ];
        }

        $choices = [];
        $lines = ["Gefundene **Newsletter**:"];
        $i = 1;

        foreach (array_slice($mapped, 0, self::PAGE_SIZE) as $hit) {
            $title = (string)($hit['title'] ?? '');
            $pub = (string)($hit['publishedAt'] ?? '');
            $label = $title . ($pub !== '' ? " ({$pub})" : '');
            $lines[] = "{$i}) {$label}";
            $choices[] = [
                'kind' => 'form',
                'label' => $label,
                'payload' => $hit,
            ];
            $i++;
        }

        $lines[] = "";
        $lines[] = "Antworte mit **1–" . count($choices) . "**, um einen Newsletter zu öffnen.";

        return [
            'answer' => implode("\n", $lines),
            'matches' => $mapped,
            'choices' => $choices,
            'modeHint' => 'newsletter_only',
        ];
    }

    private function detectNewsletterIntent(string $message): bool
    {
        $m = mb_strtolower($message);

        $keywords = [
            'newsletter', 'kw', 'kalenderwoche', 'specialnewsletter',
            'kampagne', 'kampagnen', 'rabatt', 'rabattaktion', 'sale', 'aktion',
        ];

        foreach ($keywords as $k) {
            if (str_contains($m, $k)) {
                return true;
            }
        }

        return false;
    }

    private function extractKeywordTokens(string $message, array $removeWords = []): array
    {
        $m = mb_strtolower($message);

        // rauswerfen: entfernte Steuerwörter (newsletter/form etc.)
        foreach ($removeWords as $w) {
            $w = mb_strtolower((string)$w);
            if ($w === '') {
                continue;
            }
            $m = preg_replace('/(?<![\p{L}\p{N}_])' . preg_quote($w, '/') . '(?![\p{L}\p{N}_])/iu', ' ', $m) ?? $m;
        }

        // Datum-/Zahlenmuster raus (01.01.2026, 2026-01-01, qtl, monate)
        $m = preg_replace('/\b\d{1,2}\.\d{1,2}\.\d{2,4}\b/u', ' ', $m) ?? $m;
        $m = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/u', ' ', $m) ?? $m;
        $m = preg_replace('/\b[1-4]\s*qtl\b/u', ' ', $m) ?? $m;
        $m = preg_replace('/\b[1-4]\s*quartal\b/u', ' ', $m) ?? $m;

        // Tokenisierung
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $m) ?: [];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

        $stop = [
            'in','im','am','an','auf','zu','zum','zur','von','vom','ab','seit','bis','und','oder',
            'der','die','das','des','den','dem','ein','eine','einen','einem','einer',
            'bitte','danke',
        ];

        $out = [];
        foreach ($parts as $p) {
            if (in_array($p, $stop, true)) {
                continue;
            }
            if (mb_strlen($p) < 3) {
                continue;
            }
            // muss Buchstaben enthalten (keine reinen Zahlen)
            if (!preg_match('/\p{L}/u', $p)) {
                continue;
            }
            $out[] = $p;
        }

        $out = array_values(array_unique($out));
        return $out;
    }

    private function parseNewsletterDateRange(string $message): ?array
    {
        $m = mb_strtolower(trim($message));

        // Helpers
        $nowPlus = static fn(string $spec) => (new \DateTimeImmutable('now'))->modify($spec);
        $startOfDay = static fn(\DateTimeImmutable $d) => $d->setTime(0, 0, 0);
        $endOfDay = static fn(\DateTimeImmutable $d) => $d->setTime(23, 59, 59);

        // dd.mm.yy OR dd.mm.yyyy -> DateTimeImmutable
        $parseDotDate = static function (string $dd, string $mm, string $yyOrYyyy): ?\DateTimeImmutable {
            $day = (int)$dd;
            $mon = (int)$mm;
            $yRaw = trim($yyOrYyyy);

            if (strlen($yRaw) === 2) {
                // 00-99 => 2000-2099 (für euren Usecase Newsletter vollkommen ok)
                $year = 2000 + (int)$yRaw;
            } elseif (strlen($yRaw) === 4) {
                $year = (int)$yRaw;
            } else {
                return null;
            }

            $dt = \DateTimeImmutable::createFromFormat('d.m.Y', sprintf('%02d.%02d.%04d', $day, $mon, $year));
            return ($dt instanceof \DateTimeImmutable) ? $dt : null;
        };

        /**
         * 4) Von-Bis / Range: "01.01.2026 - 28.02.2026" / "01.01.26 - 28.02.26" / "... bis ..."
         * dd.mm.(yy|yyyy) - dd.mm.(yy|yyyy)
         */
        if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})\s*(?:\-|–|—|bis)\s*(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})\b/u', $message, $x)) {
            $from = $parseDotDate($x[1], $x[2], $x[3]);
            $to   = $parseDotDate($x[4], $x[5], $x[6]);

            if ($from instanceof \DateTimeImmutable && $to instanceof \DateTimeImmutable) {
                $from = $startOfDay($from);
                $to   = $endOfDay($to);

                if ($to < $from) {
                    [$from, $to] = [$to, $from];
                }

                return ['from' => $from, 'to' => $to];
            }
        }

        // yyyy-mm-dd - yyyy-mm-dd
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\s*(?:\-|–|—|bis)\s*(\d{4})-(\d{2})-(\d{2})\b/u', $message, $x)) {
            $from = \DateTimeImmutable::createFromFormat('Y-m-d', "{$x[1]}-{$x[2]}-{$x[3]}");
            $to   = \DateTimeImmutable::createFromFormat('Y-m-d', "{$x[4]}-{$x[5]}-{$x[6]}");

            if ($from instanceof \DateTimeImmutable && $to instanceof \DateTimeImmutable) {
                $from = $startOfDay($from);
                $to   = $endOfDay($to);

                if ($to < $from) {
                    [$from, $to] = [$to, $from];
                }

                return ['from' => $from, 'to' => $to];
            }
        }

        /**
         * 3) Quartal: "1Qtl 26" / "1 Qtl 26" / "1 Quartal 26"
         */
        if (preg_match('/\b([1-4])\s*(qtl|quartal)\s*(\d{2})\b/u', $m, $x)) {
            $q = (int)$x[1];
            $yy = (int)$x[3];
            $year = 2000 + $yy;

            $startMonth = ($q - 1) * 3 + 1;

            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $startMonth));
            $to = $from->modify('+3 months')->modify('-1 day');

            return ['from' => $startOfDay($from), 'to' => $endOfDay($to)];
        }

        /**
         * 2) "seit 01.01.2026" oder "ab 01.01.26"
         */
        if (preg_match('/\b(seit|ab)\s+(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})\b/u', $m, $x)) {
            $from = $parseDotDate($x[2], $x[3], $x[4]);
            if ($from instanceof \DateTimeImmutable) {
                return ['from' => $startOfDay($from), 'to' => $endOfDay($nowPlus('+10 years'))];
            }
        }

        /**
         * 1) "Keyword Newsletter 01.01.2026" (ohne 'seit') => ab Datum bis "weit in Zukunft"
         * dd.mm.(yy|yyyy)
         */
        if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})\b/u', $message, $x)) {
            $from = $parseDotDate($x[1], $x[2], $x[3]);
            if ($from instanceof \DateTimeImmutable) {
                return ['from' => $startOfDay($from), 'to' => $endOfDay($nowPlus('+10 years'))];
            }
        }

        // yyyy-mm-dd
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $message, $x)) {
            $from = \DateTimeImmutable::createFromFormat('Y-m-d', "{$x[1]}-{$x[2]}-{$x[3]}");
            if ($from instanceof \DateTimeImmutable) {
                return ['from' => $startOfDay($from), 'to' => $endOfDay($nowPlus('+10 years'))];
            }
        }

        return null;
    }

    /**
     * Extrahiert simple Zeiträume:
     * - dd.mm.yyyy - dd.mm.yyyy
     * - "seit <Monat> <Jahr>"
     * - "<Monat> <Jahr>"
     * - "Frühjahr <Jahr>" (01.01–31.03), "Sommer" (01.06–31.08), "Herbst" (01.09–30.11), "Winter" (01.12–28/29.02)
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}|null
     */




    /**
     * @return string[] lowercase tokens
     */


    private function normalizeNewsletterTokens(array $tokens): array
    {
        $out = [];

        foreach ($tokens as $t) {
            $t = mb_strtolower(trim((string)$t));
            if ($t === '') continue;

            // Stopwords raus (kannst du erweitern)
            if (in_array($t, ['newsletter', 'seit', 'ab', 'in', 'im', 'der', 'die', 'das', 'und'], true)) {
                continue;
            }

            $out[] = $t;

            // ✅ Heuristik: -ung <-> -ungen (Reduzierung/Reduzierungen)
            if (str_ends_with($t, 'ung')) {
                $out[] = $t . 'en';          // reduzierungen
            }
            if (str_ends_with($t, 'ungen')) {
                $out[] = mb_substr($t, 0, -2); // reduzierung  (ungen -> ung)
            }

            // Optional: Umlaut-Normalisierung (falls ihr gemischt speichert)
            $out[] = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $t);
        }

        $out = array_values(array_unique($out));
        return $out;
    }


}
