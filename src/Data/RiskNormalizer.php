<?php

declare(strict_types=1);

namespace CableATierra\Datos\Data;

use CableATierra\Datos\Domain\AlertLevel;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Validates and whitelists the NoFires risk-score response.
 *
 * Real shape: {"success":true,"data":{"<GADM GID>":{row}}}. The comuna id is the map key.
 * Returns null on any failure; never throws.
 */
final class RiskNormalizer
{
    /**
     * @return list<array{comunaId: string, nombreComuna: string, alertLevel: string, alertDriver: ?string, scoreComposite: ?float, firmsCount: ?int, computedAt: string}>|null
     */
    public function normalize(string $body): ?array
    {
        $json = json_decode($body, true);
        if (!is_array($json) || ($json['success'] ?? null) === false) {
            return null;
        }

        $data = $json['data'] ?? null;
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return null;
        }

        $rows = [];
        foreach ($data as $id => $raw) {
            $row = is_array($raw) ? $this->row((string) $id, $raw) : null;
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
        $name = $raw['nombreComuna'] ?? null;
        $level = $raw['alertLevel'] ?? null;
        $computedAt = is_string($raw['computedAt'] ?? null) ? $this->utc($raw['computedAt']) : null;
        if ($id === '' || !is_string($name) || $name === '' || !is_string($level) || $computedAt === null) {
            return null;
        }

        $driver = $raw['alertDriver'] ?? null;
        $score = $raw['scoreComposite'] ?? null;
        $firms = $raw['firmsCount'] ?? null;

        return [
            'comunaId' => $id,
            'nombreComuna' => $name,
            'alertLevel' => AlertLevel::fromApi($level)->value,
            'alertDriver' => is_string($driver) && $driver !== '' ? $driver : null,
            'scoreComposite' => is_numeric($score) ? (float) $score : null,
            'firmsCount' => is_numeric($firms) ? (int) $firms : null,
            'computedAt' => $computedAt,
        ];
    }

    /** Offset-less timestamps are UTC; returns "Y-m-dTH:i:sZ" or null when unparseable. */
    private function utc(string $value): ?string
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/';
        if (preg_match($pattern, $value) !== 1) {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
