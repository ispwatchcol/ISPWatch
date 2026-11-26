<?php

namespace Tests\Feature\Ui;

use App\Models\InventoryMovement;
use Tests\TestCase;

/**
 * Guardas de pantalla del reporte de Chaguaní (2026-11-26, bitácora § 136).
 *
 * El recorrido de navegador (`tests/Browser/inventario-conteos-y-descontar.mjs`)
 * prueba el comportamiento, pero no corre en CI. Estas guardas fijan en la
 * fuente las tres causas que el cliente sufrió, para que no vuelvan sin que
 * nadie lo note.
 */
class InventoryAndInstallationsScreensTest extends TestCase
{
    private function source(string $page): string
    {
        return file_get_contents(resource_path("js/pages/{$page}"));
    }

    public function test_la_lista_de_equipos_pagina_y_cuenta_lo_filtrado(): void
    {
        $vue = $this->source('Inventory.vue');

        // El bug: el total y las páginas salían del inventario entero, así que
        // con una búsqueda había páginas vacías sin paginador para volver.
        $this->assertStringNotContainsString('const totalDevices = computed(() => devices.value.length)', $vue);
        $this->assertStringContainsString('const totalDevices = computed(() => filteredDevices.value.length)', $vue);
        $this->assertStringContainsString('v-for="device in pageDevices"', $vue);
        // Filtrar vuelve a la página 1 y la página nunca pasa de la última.
        $this->assertStringContainsString('() => { currentPage.value = 1 }', $vue);
        $this->assertStringContainsString('watch(totalPages', $vue);
        // Las tarjetas cuentan por estado sobre lo buscado.
        $this->assertStringContainsString('data-testid="status-cards"', $vue);
    }

    public function test_entregas_no_abre_siempre_en_la_bodega_sin_sucursal(): void
    {
        $vue = $this->source('InventoryTransfers.vue');

        // «Bodega (sin sucursal)» nunca tiene materiales: abrir ahí escondía la sección.
        $this->assertStringNotContainsString("const fromKey = ref('branch:')", $vue);
        $this->assertStringContainsString('inventoryApi.holdersSummary(', $vue);
        $this->assertStringContainsString('fromKey.value = pickDefaultOrigin()', $vue);
        $this->assertStringContainsString('data-testid="materials-elsewhere"', $vue);
        // Y descontar material se hace aquí mismo.
        $this->assertStringContainsString('inventoryApi.writeOff(', $vue);
    }

    public function test_instalaciones_tiene_buscador(): void
    {
        $vue = $this->source('Installations.vue');

        $this->assertStringContainsString('data-testid="installations-search"', $vue);
        $this->assertStringContainsString('v-for="inst in visibleInstallations"', $vue);
    }

    /**
     * Un tipo nuevo del kardex que la pantalla no conoce sale «crudo» y no se
     * puede filtrar. Movimientos tiene que ofrecer y rotular todos.
     */
    public function test_movimientos_conoce_todos_los_tipos_del_kardex(): void
    {
        $vue = $this->source('InventoryMovements.vue');

        foreach (InventoryMovement::TYPES as $type) {
            $this->assertStringContainsString("<option value=\"{$type}\">", $vue, "Falta el filtro «{$type}» en Movimientos.");
            $this->assertMatchesRegularExpression("/^\s*{$type}:\s*'/m", $vue, "Falta el rótulo de «{$type}» en Movimientos.");
        }
    }
}
