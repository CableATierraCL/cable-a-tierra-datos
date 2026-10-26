<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Support;

use Brain\Monkey\Functions;
use CableATierra\Datos\Data\DatasetResult;
use CableATierra\Datos\Detail\RegionDetail;

/** Shared helpers for the detail tables: real-escaping stubs, builders and XPath access. Needs SummaryFixtures. */
trait DetailHtml
{
    private const FRESH = '2026-10-25T10:00:00Z';
    private const NBSP = "\u{00A0}";

    /** Behave like the real WordPress escapers, so assertions prove output safety. */
    private function stubEscapers(): void
    {
        Functions\when('esc_html')->alias(static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8', false));
        Functions\when('esc_attr')->alias(static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8', false));
        Functions\when('esc_url')->alias(static fn (string $u): string => htmlspecialchars((string) preg_replace('/[<>"\s]/', '', $u), ENT_QUOTES, 'UTF-8', false));
    }

    /** @param list<array<string, mixed>>|null $rows */
    private function detail(string $region, ?array $rows = null, ?DatasetResult $burned = null, ?int $now = null): RegionDetail
    {
        $rows ??= [self::row($region . '1', 'ALTO', 2, 'FWI', self::FRESH, 'Alfa'), self::row($region . '2', 'NORMAL', 1, null, self::FRESH, 'Beta')];

        return RegionDetail::build(
            $region,
            $rows === [] ? DatasetResult::none() : self::risk($rows),
            $burned ?? self::burned([$region . '1' => self::ha(1000.0), $region . '2' => self::ha(234.4)]),
            $now ?? self::now(),
        );
    }

    /** @return list<RegionDetail> */
    private function three(): array
    {
        return [$this->detail('nuble'), $this->detail('biobio'), $this->detail('araucania')];
    }

    /** Parses as XML: proves the output is well formed and correctly nested. */
    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($html);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($ok, 'Output is not well-formed: ' . ($errors[0]->message ?? '') . "\n" . $html);

        return new \DOMXPath($doc);
    }

    private function text(\DOMXPath $x, string $query, ?\DOMNode $ctx = null): string
    {
        $nodes = $ctx === null ? $x->query($query) : $x->query($query, $ctx);
        self::assertNotFalse($nodes);

        return trim((string) preg_replace('/[ \t\r\n]+/', ' ', $nodes->length > 0 ? (string) $nodes->item(0)?->textContent : ''));
    }

    private function panel(\DOMXPath $x, int $i): \DOMNode
    {
        $panel = $x->query('//section[contains(@class,"cat-detail__panel")]')->item($i);
        self::assertNotNull($panel);

        return $panel;
    }
}
