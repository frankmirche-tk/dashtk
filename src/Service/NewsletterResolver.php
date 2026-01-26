<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SupportSolution;
use App\Repository\SupportSolutionRepository;

final class NewsletterResolver
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly SupportSolutionRepository $solutions,
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

        $range = $this->extractDateRange($message);

        // Zeitraum ist "first-class": ohne Zeitraum -> aktiv nachfordern
        if ($range === null) {
            return [
                'answer' => "Ich kann Newsletter sehr gut finden – mir fehlt nur der **Zeitraum**.\n\n"
                    . "Bitte gib mir z.B.:\n"
                    . "- „seit Oktober 2025“\n"
                    . "- „01.01.2025 - 31.03.2025“\n"
                    . "- „Frühjahr 2025“\n\n"
                    . "Welchen Zeitraum soll ich durchsuchen?",
                'matches' => [],
                'choices' => [],
                'modeHint' => 'newsletter_need_range',
            ];
        }

        [$from, $to] = $range;

        // 1) Kandidaten über bestehendes Matching holen (Keywords/Title/ContextNotes)
        // Tipp an Redaktion: pro Newsletter Keywords wie "newsletter", "kw 5", "sale", "rabatt", "kampagne" pflegen
        $raw = $this->solutions->findBestMatches($message . ' newsletter', 80);

        $mapped = [];
        foreach ($raw as $m) {
            $s = $m['solution'] ?? null;
            if (!$s instanceof SupportSolution) {
                continue;
            }

            // wir behandeln Newsletter als FORM-Dokumente
            if ($s->getType() !== 'FORM') {
                continue;
            }

            // Filter Zeitraum über createdAt (Montag der KW empfohlen)
            // neuer Code: publishedAt bevorzugen, fallback auf createdAt
            $dt = $s->getPublishedAt() ?? $s->getCreatedAt();
            if ($dt < $from || $dt > $to) {
                continue;
            }


            $mapped[] = [
                'id' => (int)$s->getId(),
                'title' => (string)$s->getTitle(),
                'score' => (int)($m['score'] ?? 0),
                'url' => '/api/support_solutions/' . (int)$s->getId(),
                'type' => 'FORM',
                'updatedAt' => $s->getUpdatedAt()->format('Y-m-d H:i'),
                'symptoms' => (string)($s->getSymptoms() ?? ''),

                'mediaType' => $s->getMediaType(),
                'externalMediaProvider' => $s->getExternalMediaProvider(),
                'externalMediaUrl' => $s->getExternalMediaUrl(),
                'externalMediaId' => $s->getExternalMediaId(),

                // nice-to-have: Datum fürs Listing
                'createdAt' => ($s->getPublishedAt() ?? $s->getCreatedAt())->format('Y-m-d'),

            ];
        }

        // Desc nach createdAt
        usort($mapped, static function (array $a, array $b) {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });

        $total = count($mapped);
        $page = array_slice($mapped, 0, self::PAGE_SIZE);

        if ($page === []) {
            return [
                'answer' => "Ich habe im Zeitraum **" . $from->format('d.m.Y') . " – " . $to->format('d.m.Y') . "** keine passenden Newsletter gefunden.\n"
                    . "Gib mir bitte 1–2 zusätzliche Stichwörter (z.B. „sale“, „rabatt“, „kampagne“, „outlet“, „fs-ware“).",
                'matches' => [],
                'choices' => [],
                'modeHint' => 'newsletter_empty',
            ];
        }

        $lines = [];
        $lines[] = "Ich habe **{$total}** passende Newsletter im Zeitraum **" . $from->format('d.m.Y') . " – " . $to->format('d.m.Y') . "** gefunden.";
        $lines[] = "Hier sind die neuesten Treffer (sortiert absteigend):";
        $lines[] = "";

        $choices = [];
        $i = 1;
        foreach ($page as $hit) {
            $title = (string)($hit['title'] ?? '');
            $date = (string)($hit['createdAt'] ?? '');
            $lines[] = "{$i}) {$title}" . ($date !== '' ? " (Datum: {$date})" : '');

            // Wir nutzen absichtlich kind=form -> bestehender Open-Flow funktioniert sofort.
            $choices[] = [
                'kind' => 'form',
                'label' => $title,
                'payload' => $hit,
            ];
            $i++;
        }

        $lines[] = "";
        $lines[] = "Antworte mit **1–" . count($choices) . "**, um den Newsletter zu öffnen.";

        if ($total > self::PAGE_SIZE) {
            $lines[] = "Wenn du mehr willst: schreibe **mehr** (dann kommen die nächsten " . self::PAGE_SIZE . ").";
        }

        return [
            'answer' => implode("\n", $lines),
            'matches' => [],
            'choices' => $choices,
            'modeHint' => 'newsletter_list',
            'newsletterPaging' => [
                'total' => $total,
                'pageSize' => self::PAGE_SIZE,
                'offset' => self::PAGE_SIZE,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'query' => $message,
            ],
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

    /**
     * Extrahiert simple Zeiträume:
     * - dd.mm.yyyy - dd.mm.yyyy
     * - "seit <Monat> <Jahr>"
     * - "<Monat> <Jahr>"
     * - "Frühjahr <Jahr>" (01.01–31.03), "Sommer" (01.06–31.08), "Herbst" (01.09–30.11), "Winter" (01.12–28/29.02)
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}|null
     */
    private function extractDateRange(string $message): ?array
    {
        // neuer Code: "seit 01.01.2026"
        if (preg_match('/seit\s+(\d{2})\.(\d{2})\.(\d{4})/u', $message, $mm)) {
            $from = \DateTimeImmutable::createFromFormat('!d.m.Y', "{$mm[1]}.{$mm[2]}.{$mm[3]}");
            if ($from) {
                $to = (new \DateTimeImmutable('now'))->setTime(23, 59, 59);
                return [$from, $to];
            }
        }

        $m = mb_strtolower($message);

        // 1) Explizite Spanne dd.mm.yyyy - dd.mm.yyyy
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})\s*[-–]\s*(\d{2})\.(\d{2})\.(\d{4})/u', $m, $mm)) {
            $from = \DateTimeImmutable::createFromFormat('!d.m.Y', "{$mm[1]}.{$mm[2]}.{$mm[3]}");
            $to   = \DateTimeImmutable::createFromFormat('!d.m.Y', "{$mm[4]}.{$mm[5]}.{$mm[6]}");
            if ($from && $to) {
                return [$from, $to->setTime(23, 59, 59)];
            }
        }

        // 2) "seit <Monat> <Jahr>"
        if (preg_match('/seit\s+([a-zäöü]+)\s+(\d{4})/u', $m, $mm)) {
            $month = $this->monthToNumber($mm[1]);
            $year = (int)$mm[2];
            if ($month !== null) {
                $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
                $to = (new \DateTimeImmutable('now'))->setTime(23, 59, 59);
                return [$from, $to];
            }
        }

        // 3) "<Monat> <Jahr>"
        if (preg_match('/\b(januar|februar|märz|maerz|april|mai|juni|juli|august|september|oktober|november|dezember)\s+(\d{4})\b/u', $m, $mm)) {
            $month = $this->monthToNumber($mm[1]);
            $year = (int)$mm[2];
            if ($month !== null) {
                $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
                $to = $from->modify('last day of this month')->setTime(23, 59, 59);
                return [$from, $to];
            }
        }

        // 4) Jahreszeiten
        if (preg_match('/\b(frühjahr|fruehjahr|sommer|herbst|winter)\s+(\d{4})\b/u', $m, $mm)) {
            $season = $mm[1];
            $year = (int)$mm[2];

            return match ($season) {
                'frühjahr', 'fruehjahr' => [new \DateTimeImmutable("$year-01-01 00:00:00"), new \DateTimeImmutable("$year-03-31 23:59:59")],
                'sommer' => [new \DateTimeImmutable("$year-06-01 00:00:00"), new \DateTimeImmutable("$year-08-31 23:59:59")],
                'herbst' => [new \DateTimeImmutable("$year-09-01 00:00:00"), new \DateTimeImmutable("$year-11-30 23:59:59")],
                'winter' => [new \DateTimeImmutable("$year-12-01 00:00:00"), new \DateTimeImmutable(($year + 1) . "-02-28 23:59:59")],
                default => null,
            };
        }

        return null;
    }

    private function monthToNumber(string $month): ?int
    {
        $m = mb_strtolower($month);

        return match ($m) {
            'januar' => 1,
            'februar' => 2,
            'märz', 'maerz' => 3,
            'april' => 4,
            'mai' => 5,
            'juni' => 6,
            'juli' => 7,
            'august' => 8,
            'september' => 9,
            'oktober' => 10,
            'november' => 11,
            'dezember' => 12,
            default => null,
        };
    }
}
