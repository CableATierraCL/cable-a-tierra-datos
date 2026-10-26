<?php

declare(strict_types=1);

namespace CableATierra\Datos\Domain;

/**
 * Whitelist of NoFires alert drivers. Anything else is treated as "no driver".
 */
enum AlertDriver: string
{
    case FirmsToday = 'FIRMS_TODAY';
    case Fwi = 'FWI';
    case Score = 'SCORE';
    case FirmsCluster = 'FIRMS_CLUSTER';
    case FirmsFrp = 'FIRMS_FRP';
}
