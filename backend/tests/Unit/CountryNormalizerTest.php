<?php

namespace Tests\Unit;

use App\Services\Geo\CountryNormalizer;
use PHPUnit\Framework\TestCase;

class CountryNormalizerTest extends TestCase
{
    public function test_normalizes_direct_input(): void
    {
        $this->assertSame('CR', CountryNormalizer::normalize('Costa Rica'));
        $this->assertSame('CO', CountryNormalizer::normalize('Colombia'));
        $this->assertSame('MX', CountryNormalizer::normalize('México'));
        $this->assertSame('US', CountryNormalizer::normalize('Estados Unidos'));
        $this->assertSame('AR', CountryNormalizer::normalize('Argentina'));
        $this->assertSame('CL', CountryNormalizer::normalize('Chile'));
        $this->assertSame('ES', CountryNormalizer::normalize('España'));
        $this->assertSame('PA', CountryNormalizer::normalize('Panamá'));
        $this->assertSame('GT', CountryNormalizer::normalize('Guatemala'));
    }

    public function test_normalizes_conversational_phrases(): void
    {
        $this->assertSame('CR', CountryNormalizer::normalize('Soy de Costa Rica'));
        $this->assertSame('CR', CountryNormalizer::normalize('Vivo en Costa Rica'));
        $this->assertSame('CO', CountryNormalizer::normalize('De Colombia'));
        $this->assertSame('MX', CountryNormalizer::normalize('Desde México'));
        $this->assertSame('PA', CountryNormalizer::normalize('Estoy en Panamá'));
        $this->assertSame('CL', CountryNormalizer::normalize('Radico en Chile'));
        $this->assertSame('PE', CountryNormalizer::normalize('Vengo de Perú'));
        $this->assertSame('EC', CountryNormalizer::normalize('Hablo desde Ecuador'));
        $this->assertSame('AR', CountryNormalizer::normalize('Yo soy de Argentina'));
    }

    public function test_normalizes_complex_conversational_phrases(): void
    {
        $this->assertSame('CR', CountryNormalizer::normalize('Hola, vivo en Costa Rica actualmente'));
        $this->assertSame('CR', CountryNormalizer::normalize('Vivo en Costa Rica amigo'));
        $this->assertSame('CO', CountryNormalizer::normalize('Hola buenas tardes, soy de Colombia por favor'));
        $this->assertSame('MX', CountryNormalizer::normalize('Hola! Vengo desde México'));
        $this->assertSame('US', CountryNormalizer::normalize('Vivo en Estados Unidos actualmente'));
    }

    public function test_normalizes_common_variants_and_acronyms(): void
    {
        $this->assertSame('CR', CountryNormalizer::normalize('costarica'));
        $this->assertSame('CR', CountryNormalizer::normalize('cr'));
        $this->assertSame('US', CountryNormalizer::normalize('EEUU'));
        $this->assertSame('US', CountryNormalizer::normalize('EE.UU.'));
        $this->assertSame('US', CountryNormalizer::normalize('USA'));
        $this->assertSame('US', CountryNormalizer::normalize('united states'));
        $this->assertSame('MX', CountryNormalizer::normalize('México'));
        $this->assertSame('MX', CountryNormalizer::normalize('mejico'));
        $this->assertSame('DO', CountryNormalizer::normalize('republica dominicana'));
        $this->assertSame('DO', CountryNormalizer::normalize('rep dominicana'));
        $this->assertSame('PR', CountryNormalizer::normalize('Puerto Rico'));
        $this->assertSame('SV', CountryNormalizer::normalize('El Salvador'));
        $this->assertSame('SV', CountryNormalizer::normalize('salvador'));
    }

    public function test_rejects_invalid_inputs(): void
    {
        $this->assertNull(CountryNormalizer::normalize('manzana'));
        $this->assertNull(CountryNormalizer::normalize('perro caliente'));
        $this->assertNull(CountryNormalizer::normalize('hola'));
        $this->assertNull(CountryNormalizer::normalize('yo soy'));
        $this->assertNull(CountryNormalizer::normalize(''));
        $this->assertNull(CountryNormalizer::normalize(null));
        $this->assertNull(CountryNormalizer::normalize('12345'));
    }
}
