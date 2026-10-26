<?php

declare(strict_types=1);

namespace CableATierra\Datos\Domain;

/**
 * Regions covered by the data band. Case order is the display order.
 */
enum Region: string
{
    case Nuble = 'nuble';
    case Biobio = 'biobio';
    case Araucania = 'araucania';
}
