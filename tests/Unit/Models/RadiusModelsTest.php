<?php

namespace Tests\Unit\Models;

use App\Models\RadiusCoaCommand;
use App\Models\RadiusSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lógica pura de los modelos RADIUS. Sin BD: son cálculos que o están bien o
 * corrompen datos en silencio.
 */
class RadiusModelsTest extends TestCase
{
    #[Test]
    public function los_octetos_sin_gigawords_se_devuelven_tal_cual(): void
    {
        $this->assertSame(1024, RadiusSession::combineOctets(1024, 0));
        $this->assertSame(0, RadiusSession::combineOctets(null, null));
    }

    #[Test]
    public function cada_gigaword_suma_cuatro_gigabytes(): void
    {
        // Este es el bug que la función existe para evitar: Acct-Input-Octets
        // es de 32 bits y vuelve a cero cada 4 GB. Quedarse solo con él
        // subcuenta el tráfico de toda sesión larga, y nadie lo nota porque el
        // número que sale sigue siendo plausible.
        $cuatroGb = 4 * 1024 ** 3;

        $this->assertSame($cuatroGb, RadiusSession::combineOctets(0, 1));
        $this->assertSame($cuatroGb + 500, RadiusSession::combineOctets(500, 1));
        $this->assertSame(3 * $cuatroGb, RadiusSession::combineOctets(0, 3));
    }

    #[Test]
    public function el_backoff_de_coa_crece_exponencialmente(): void
    {
        $this->assertSame(30, RadiusCoaCommand::backoffSeconds(1));
        $this->assertSame(60, RadiusCoaCommand::backoffSeconds(2));
        $this->assertSame(120, RadiusCoaCommand::backoffSeconds(3));
        $this->assertSame(240, RadiusCoaCommand::backoffSeconds(4));
        $this->assertSame(480, RadiusCoaCommand::backoffSeconds(5));
    }

    #[Test]
    public function el_backoff_no_se_rompe_con_cero_intentos(): void
    {
        // Defensivo: un contador en cero no debe producir un exponente
        // negativo (que daría un float y un intervalo sin sentido).
        $this->assertSame(30, RadiusCoaCommand::backoffSeconds(0));
    }
}
