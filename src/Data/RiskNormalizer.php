<?php

declare(strict_types=1);

namespace CableATierra\Datos\Data;

use CableATierra\Datos\Domain\AlertDriver;
use CableATierra\Datos\Domain\AlertLevel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Validates and whitelists the NoFires risk-score response.
 *
 * Real shape: {"success":true,"data":{"<GADM GID>":{row}}}. The comuna id is the map key.
 * `success` must be exactly true. Returns null on any failure; never throws.
 *
 * Output strings are cleaned (trimmed, control characters removed, length-capped), NOT escaped:
 * every string must still be escaped for its output context.
 */
final class RiskNormalizer
{
    /**
     * @return list<array{comunaId: string, nombreComuna: string, alertLevel: string, alertDriver: ?string, scoreComposite: ?float, firmsCount: ?int, computedAt: string}>|null
     */
    public function normalize(string $body): ?array
    {
        $json = json_decode($body, true);
        if (!is_array($json) || ($json['success'] ?? null) !== true) {
            return null;
        }

        $data = $json['data'] ?? null;
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return null;
        }

        $rows = [];
        foreach ($data as $id => $raw) {
            // json_decode turns "123" keys into ints; real GADM GIDs (CHL.6.1.1_1) are never numeric.
            $row = is_string($id) && $id !== '' && !is_numeric($id) && is_array($raw) ? $this->row($id, $raw) : null;
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * @param array<mixed> $raw
     * @return array{comunaId: string, nombreComuna: string, alertLevel: string, alertDriver: ?string, scoreComposite: ?float, firmsCount: ?int, computedAt: string}|null
     */
    private function row(string $id, array $raw): ?array
    {
        $name = Text::clean($raw['nombreComuna'] ?? null, 120);
        $level = $raw['alertLevel'] ?? null;
        $computedAt = is_string($raw['computedAt'] ?? null) ? $this->utc($raw['computedAt']) : null;
        if ($name === null || !is_string($level) || $computedAt === null) {
            return null;
        }

        $driver = $raw['alertDriver'] ?? null;

        return [
            'comunaId' => $id,
            'nombreComuna' => $name,
            'alertLevel' => AlertLevel::fromApi($level)->value,
            'alertDriver' => is_string($driver) ? AlertDriver::tryFrom($driver)?->value : null,
            'scoreComposite' => Num::floatInRange($raw['scoreComposite'] ?? null, 0.0, 1.0),
            'firmsCount' => Num::nonNegativeInt($raw['firmsCount'] ?? null),
            'computedAt' => $computedAt,
        ];
    }

    /**
     * Offset-less timestamps are UTC; returns "Y-m-dTH:i:sZ" or null for malformed or impossible
     * dates (2026-02-31, T24:00:00, :60 seconds, years outside 2000-2100).
     */
    private function utc(string $value): ?string
    {
        $pattern = '/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,9})?(?:Z|([+-])(\d{2}):?(\d{2}))?\z/';
        if (preg_match($pattern, $value, $m) !== 1) {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $m[1], $utc);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:s') !== $m[1]
            || (int) $date->format('Y') < 2000
            || (int) $date->format('Y') > 2100
        ) {
            return null;
        }

        $seconds = 0;
        if (isset($m[2])) {
            if ((int) $m[3] > 14 || (int) $m[4] > 59) {
                return null;
            }
            $seconds = ($m[2] === '-' ? -1 : 1) * ((int) $m[3] * 3600 + (int) $m[4] * 60);
        }

        return $date->setTimestamp($date->getTimestamp() - $seconds)->format('Y-m-d\TH:i:s\Z');
    }
}
