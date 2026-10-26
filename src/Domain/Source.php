<?php

declare(strict_types=1);

namespace CableATierra\Datos\Domain;

/**
 * Where a dataset result came from.
 */
enum Source: string
{
    case Cache = 'cache';
    case Api = 'api';
    case LastGood = 'last_good';
    case None = 'none';
}
