<?php

namespace Tests\Feature\Ui;

use Tests\TestCase;

/**
 * Guardas de pantalla del 2026-11-26 (bitácora § 137): pruebas técnicas
 * opcionales en el flujo simple, cobros gobernados por su permiso y el rol a la
 * vista al asignar técnico a una instalación.
 */
class TicketRolesAndMeasurementsScreensTest extends TestCase
{
    private function source(string $path): string
    {
        return file_get_contents(resource_path("js/{$path}"));
    }

    public function test_las_pruebas_tecnicas_solo_se_exigen_en_el_flujo_formal(): void
    {
        $componente = $this->source('components/TicketMeasurements.vue');
        $detalle    = $this->source('pages/SupportDetail.vue');

        // El aviso «sin medición final no se puede cerrar» sólo cuando la regla aplica.
        $this->assertStringContainsString('pruebaFinalObligatoria === true && !pruebaFinal.present', $componente);
        $this->assertStringContainsString('data-testid="measurements-optional"', $componente);
        // Y el detalle le dice al componente qué flujo es (lo decide el servidor).
        $this->assertStringContainsString(':prueba-final-obligatoria="workflow.cargado ? workflow.requiresFinalTest : null"', $detalle);
    }

    public function test_los_cobros_del_ticket_se_gobiernan_con_ticket_charge(): void
    {
        $detalle = $this->source('pages/SupportDetail.vue');
        $alta    = $this->source('pages/SupportCreate.vue');

        $this->assertStringContainsString("hasPermission('ticket_charge')", $detalle);
        $this->assertStringContainsString('v-if="canCharge" ref="chargesCard"', $detalle);
        $this->assertStringNotContainsString('v-if="canEdit" ref="chargesCard"', $detalle);
        $this->assertStringContainsString("hasPermission('ticket_charge')", $alta);
    }

    public function test_el_reloj_del_menu_no_dispara_el_cartel_de_permisos(): void
    {
        // `GET /tenants/{id}` exige `manage_tenant`: sin esta guarda, quien no
        // administra la empresa (un técnico) veía «No tienes permiso» en cada
        // pantalla, porque el menú lateral lo pide para el reloj.
        $sidebar = $this->source('components/Sidebar.vue');

        $this->assertStringContainsString("!authStore.hasPermission('manage_tenant')) return", $sidebar);
        $this->assertStringContainsString('{ silentForbidden: true }', $sidebar);
    }

    public function test_al_asignar_tecnico_a_una_instalacion_se_ve_el_rol(): void
    {
        $instalaciones = $this->source('pages/Installations.vue');

        // Ahora salen técnicos y administradores: el rol los distingue.
        $this->assertSame(2, substr_count($instalaciones, ':items="technicians" item-sublabel="role_name"'));
    }
}
