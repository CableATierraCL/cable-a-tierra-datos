<?php

declare(strict_types=1);

namespace CableATierra\Datos\Data;

/**
 * Validates and whitelists the NoFires BURNED layer response.
 *
 * Shape: {"success":true,"data":{"layers":{"BURNED":{year,citation,values:{GID:{...}}}}}}.
 * `success` must be exactly true. `values` MUST be an object keyed by comuna id; anything
 * else is a failure. Returns null on any failure; never throws.
 *
 * The citation is cleaned (trimmed, control characters removed, length-capped), NOT escaped:
 * it must still be escaped for its output context.
 */
final class BurnedNormalizer
{
    private const MIN_YEAR = 1985;
    private const MAX_YEAR = 2100;

    /**
     * @return array{year: int, citation: ?string, values: array<string, array{available: bool, burnedFraction: ?float, burnedHa: ?float}>}|null
     */
    public function normalize(string $body): ?array
    {
        $json = json_decode($body, true);
        if (!is_array($json) || ($json['success'] ?? null) !== true) {
            return null;
        }

        $layer = $json['data']['layers']['BURNED'] ?? null;
        $year = is_array($layer) ? $this->year($layer) : null;
        if ($year === null) {
            return null;
        }

        $raw = $layer['values'] ?? null;
        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            return null;
        }

        $values = [];
        foreach ($raw as $id => $entry) {
            // json_decode turns "123" keys into ints; real GADM GIDs (CHL.6.1.1_1) are never numeric.
            if (is_string($id) && $id !== '' && !is_numeric($id) && is_array($entry)) {
                $values[$id] = $this->entry($entry);
            }
        }
        if ($values === []) {
            return null;
        }

        return [
            'year' => $year,
            'citation' => Text::clean($layer['citation'] ?? null, 1000),
            'values' => $values,
        ];
    }

    /**
     * Accepts int, integral float or digit string within 1985-2100 and, when the layer declares
     * valid minYear/maxYear ints, within them.
     *
     * @param array<mixed> $layer
     */
    private function year(array $layer): ?int
    {
        $raw = $layer['year'] ?? null;
        if (is_string($raw)) {
            $raw = preg_match('/\A\d{1,4}\z/', $raw) === 1 ? (int) $raw : null;
        }
        $year = Num::nonNegativeInt($raw);
        if ($year === null || $year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return null;
        }

        $min = $layer['minYear'] ?? null;
        $max = $layer['maxYear'] ?? null;
        if ((is_int($min) && $year < $min) || (is_int($max) && $year > $max)) {
            return null;
        }

        return $year;
    }

    /**
     * Unavailable (or non-boolean `available`) entries keep nulls; an "available" entry without
     * a valid area is not trusted. An invalid fraction alone becomes null without losing the area.
     *
     * @param array<mixed> $entry
     * @return array{available: bool, burnedFraction: ?float, burnedHa: ?float}
     */
    private function entry(array $entry): array
    {
        $ha = Num::floatInRange($entry['burnedHa'] ?? null, 0.0, PHP_FLOAT_MAX);
        if (($entry['available'] ?? null) !== true || $ha === null) {
            return ['available' => false, 'burnedFraction' => null, 'burnedHa' => null];
        }

        return [
            'available' => true,
            'burnedFraction' => Num::floatInRange($entry['burnedFraction'] ?? null, 0.0, 1.0),
            'burnedHa' => $ha,
        ];
    }
}
