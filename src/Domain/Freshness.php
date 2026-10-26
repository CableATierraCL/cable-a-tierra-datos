<?php

declare(strict_types=1);

namespace CableATierra\Datos\Domain;

/**
 * Data age state for a region.
 */
enum Freshness: string
{
    case Ok = 'ok';
    case Stale = 'stale';
    case VeryStale = 'very_stale';
    case Unavailable = 'unavailable';
}
