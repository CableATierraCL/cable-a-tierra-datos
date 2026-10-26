<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Domain;

use CableATierra\Datos\Domain\Attribution;
use CableATierra\Datos\Render\BandRenderer;
use PHPUnit\Framework\TestCase;

final class AttributionTest extends TestCase
{
    public function test_nofires_url(): void
    {
        $this->assertSame('https://nofires-latam.vercel.app', Attribution::NOFIRES_URL);
    }

    public function test_mapbiomas_citation_is_the_official_wording(): void
    {
        $this->assertStringStartsWith('MapBiomas – Colección 1 de la Serie Anual de Mapas de Área Quemada de Chile', Attribution::MAPBIOMAS_CITATION);
        $this->assertStringEndsWith('https://storage.googleapis.com/mapbiomas-public/initiatives/chile/fire/collection1/', Attribution::MAPBIOMAS_CITATION);
    }

    public function test_band_default_citation_is_the_same_constant(): void
    {
        $this->assertSame(Attribution::MAPBIOMAS_CITATION, BandRenderer::DEFAULT_CITATION);
    }
}
