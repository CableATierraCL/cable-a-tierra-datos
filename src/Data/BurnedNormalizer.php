<?php

declare(strict_types=1);

namespace CableATierra\Datos\Data;

/**
 * Validates and whitelists the NoFires BURNED layer response.
 *
 * Shape: {"success":true,"data":{"layers":{"BURNED":{year,citation,values:{GID:{...}}}}}}.
 * `values` MUST be an object keyed by comuna id; anything else is a failure.
 * Returns null on any failure; never throws.
 */
final class BurnedNormalizer
{
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
        if (!is_array($layer) || !is_int($layer['year'] ?? null)) {
            return null;
        }

        $raw = $layer['values'] ?? null;
        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            return null;
        }

        $values = [];
        foreach ($raw as $id => $entry) {
            if ((string) $id !== '' && is_array($entry) && is_bool($entry['available'] ?? null)) {
                $values[(string) $id] = $this->entry($entry);
            }
        }
        if ($values === []) {
            return null;
        }

        $citation = $layer['citation'] ?? null;

        return [
            'year' => $layer['year'],
            'citation' => is_string($citation) && trim($citation) !== '' ? $citation : null,
            'values' => $values,
        ];
    }

    /**
     * Unavailable entries keep nulls; an "available" entry without a numeric area is not trusted.
     *
     * @param array<mixed> $entry
     * @return array{available: bool, burnedFraction: ?float, burnedHa: ?float}
     */
    private function entry(array $entry): array
    {
        $ha = $entry['burnedHa'] ?? null;
        if ($entry['available'] !== true || !is_numeric($ha)) {
            return ['available' => false, 'burnedFraction' => null, 'burnedHa' => null];
        }

        $fraction = $entry['burnedFraction'] ?? null;

        return [
            'available' => true,
            'burnedFraction' => is_numeric($fraction) ? (float) $fraction : null,
            'burnedHa' => (float) $ha,
        ];
    }
}
