<?php

namespace App\Http\Controllers;

use App\Services\Inventory\InventoryAvailability;
use Illuminate\Http\Request;

/**
 * Catálogo con el que se planifica una instalación al agendarla o editarla.
 *
 * Es de SOLO LECTURA y no reserva nada. Lo usa quien agenda —que no siempre
 * administra inventario—, así que la disponibilidad va agregada para todo el
 * tenant y, sin `view_inventory`, sin precios ni detalle de bodegas o personas.
 */
class InstallationPlanningController extends Controller
{
    public function __construct(private InventoryAvailability $availability)
    {
    }

    public function catalog(Request $request)
    {
        $actor = $request->user();
        abort_if(!$actor?->tenant_id, 403, 'No autorizado.');

        return response()->json($this->availability->planningCatalog($actor));
    }
}
