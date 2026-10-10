<?php

namespace Tests\Feature\Ui;

use Tests\TestCase;

/**
 * KAN-81: el portal de pago no puede mostrarle al abonado de cualquier ISP un
 * teléfono inventado y fijo en el código.
 */
class PaymentPortalContactTest extends TestCase
{
    public function test_el_portal_no_muestra_el_numero_fijo_de_ejemplo(): void
    {
        $html = $this->get('/portal-pago')->assertOk()->getContent();

        $this->assertStringNotContainsString('3001234567', $html);
        $this->assertStringNotContainsString('tel:+57', $html);
        $this->assertStringNotContainsString('wa.me/', $html);
    }

    public function test_le_dice_al_abonado_donde_encontrar_el_contacto_de_su_isp(): void
    {
        $this->get('/portal-pago')
            ->assertOk()
            ->assertSee('Usa el teléfono o el WhatsApp de tu proveedor de internet', false);
    }
}
