<?php

declare(strict_types=1);

namespace CableATierra\Datos\Data;

use CableATierra\Datos\Domain\Source;

/**
 * What the repository hands back for one (region, dataset): a validated payload and where it
 * came from. `fetchedAt` is when the payload was last fetched successfully (Unix seconds).
 */
final readonly class DatasetResult
{
    /** @param array<mixed>|null $payload */
    public function __construct(
        public Source $source,
        public ?array $payload = null,
        public ?int $fetchedAt = null,
    ) {
    }

    public static function none(): self
    {
        return new self(Source::None);
    }
}
