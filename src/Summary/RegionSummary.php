<?php

declare(strict_types=1);

namespace CableATierra\Datos\Summary;

use CableATierra\Datos\Domain\Freshness;
use CableATierra\Datos\Format\LocalTime;

/**
 * Aggregated view of one region. Strings are raw (not escaped); escape at output.
 *
 * @phpstan-type Burned array{available: bool, year: ?int, totalHa: ?float, comunasWithData: int, comunasTotal: int, citation: ?string}
 * @phpstan-type Critical array{comunaId: string, nombre: string, driverKey: ?string, computedAt: string}
 */
final readonly class RegionSummary
{
    /**
     * @param array<string, int> $levelCounts
     * @param list<array{comunaId: string, nombre: string, driverKey: ?string, computedAt: string}> $criticalComunas
     * @param array{available: bool, year: ?int, totalHa: ?float, comunasWithData: int, comunasTotal: int, citation: ?string} $burned
     * @param array{risk: string, burned: string} $sources
     */
    public function __construct(
        public string $region,
        public string $label,
        public Freshness $state,
        public ?int $firmsTotal,
        public int $firmsMissing,
        public array $levelCounts,
        public int $unclassifiedCount,
        public int $comunaCount,
        public array $criticalComunas,
        public array $burned,
        public ?int $freshnessTs,
        public ?int $ageSeconds,
        public array $sources,
    ) {
    }

    /**
     * No usable risk data: zero counts, null stats (never fabricated zeros), state `unavailable`.
     *
     * @param ?array{available: bool, year: ?int, totalHa: ?float, comunasWithData: int, comunasTotal: int, citation: ?string} $burned
     * @param ?array{risk: string, burned: string} $sources
     */
    public static function unavailable(string $region, string $label = '', ?array $burned = null, ?array $sources = null): self
    {
        return new self(
            $region,
            $label !== '' ? $label : $region,
            Freshness::Unavailable,
            null,
            0,
            array_fill_keys(RegionSummarizer::LEVELS, 0),
            0,
            0,
            [],
            $burned ?? ['available' => false, 'year' => null, 'totalHa' => null, 'comunasWithData' => 0, 'comunasTotal' => 0, 'citation' => null],
            null,
            null,
            $sources ?? ['risk' => 'none', 'burned' => 'none'],
        );
    }

    /**
     * Stable v1 shape (see cat_datos_get_region_summary()).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'region' => $this->region,
            'label' => $this->label,
            'state' => $this->state->value,
            'firmsTotal' => $this->firmsTotal,
            'firmsMissing' => $this->firmsMissing,
            'levelCounts' => $this->levelCounts,
            'unclassifiedCount' => $this->unclassifiedCount,
            'comunaCount' => $this->comunaCount,
            'criticalComunas' => $this->criticalComunas,
            'burned' => $this->burned,
            'freshnessAt' => $this->freshnessTs === null ? null : LocalTime::utcIso($this->freshnessTs),
            'freshnessAtLocal' => $this->freshnessTs === null ? null : LocalTime::at($this->freshnessTs)->format('Y-m-d H:i'),
            'ageSeconds' => $this->ageSeconds,
            'sources' => $this->sources,
        ];
    }
}
