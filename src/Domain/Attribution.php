<?php

declare(strict_types=1);

namespace CableATierra\Datos\Domain;

/** Source credits shared by every view that shows data. */
final class Attribution
{
    public const NOFIRES_URL = 'https://nofires-latam.vercel.app';

    /** Official MapBiomas wording (without access date). Used only when the API omits `citation`. */
    public const MAPBIOMAS_CITATION = 'MapBiomas – Colección 1 de la Serie Anual de Mapas de Área Quemada de Chile (Fuego, 2013–2025). https://storage.googleapis.com/mapbiomas-public/initiatives/chile/fire/collection1/';
}
