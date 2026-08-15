<?php

namespace App\Http\Controllers;

use App\Models\CutType;
use App\Models\ScriptVersion;
use App\Models\TypeBilling;
use App\Models\User;
use App\Support\TicketCatalogs;
use Illuminate\Http\Request;

/**
 * Read-only endpoints for global reference catalogs.
 *
 * These tables (cut_type, script_version, type_billing) hold shared lookup
 * data with NO tenant_id, so they are not tenant-scoped. They were previously
 * read directly from Supabase with the anon key; moving them behind the
 * authenticated API removes that exposure.
 */
class CatalogController extends Controller
{
    /**
     * Catálogos del ticket de soporte, en un solo viaje.
     *
     * FASE 1 · R2 — sustituye a los mapas de etiquetas que estaban escritos a
     * mano en cinco componentes de Vue. Tenerlos duplicados significaba que
     * añadir un estado obligaba a acordarse de los cinco, y que la interfaz
     * podía discrepar de lo que la base de datos aceptaba.
     *
     * Se devuelven los tres juntos y no un endpoint por catálogo porque la
     * pantalla de soporte los necesita a la vez: tres peticiones para doce
     * filas no compensan.
     *
     * Sólo salen las filas VIGENTES: es el listado de lo que se puede elegir.
     * Un ticket antiguo con un estado ya retirado se sigue mostrando bien,
     * porque su etiqueta viaja en el propio ticket (`status_label`).
     */
    public function ticketCatalogs(TicketCatalogs $catalogs)
    {
        $presentar = fn ($tabla) => $catalogs->vigentes($tabla)->map(fn ($fila) => [
            'code'  => $fila->code,
            'label' => $fila->label,
        ])->values();

        return response()->json([
            'statuses'   => $presentar(TicketCatalogs::STATUS),
            'priorities' => $presentar(TicketCatalogs::PRIORITY),
            'categories' => $presentar(TicketCatalogs::CATEGORY),
            // Para que un consumidor sepa si su copia sigue vigente sin
            // volver a descargarla entera.
            'versions'   => [
                'status'   => $catalogs->version('status'),
                'priority' => $catalogs->version('priority'),
                'category' => $catalogs->version('category'),
            ],
        ]);
    }

    public function cutTypes()
    {
        return response()->json(CutType::orderBy('name')->get(['id', 'name']));
    }

    public function scriptVersions()
    {
        return response()->json(ScriptVersion::orderBy('version')->get(['id', 'version']));
    }

    public function typeBillings()
    {
        return response()->json(TypeBilling::orderBy('type')->get(['id', 'type']));
    }

    /**
     * Tenant users as id+name options (e.g. for "assigned to" dropdowns).
     * The User model has no tenant global scope, so we filter explicitly here.
     *
     * Con `?staff=1` se excluye a los clientes y quedan sólo las personas del
     * ISP. El discriminador es la AUSENCIA de `customer_profile`, no la
     * presencia de `staff_profile`: esa tabla está vacía en producción
     * (verificado: 0 filas frente a 214 perfiles de cliente), así que filtrar
     * por ella dejaría el desplegable en blanco.
     */
    public function users(Request $request)
    {
        $query = User::where('tenant_id', $request->user()->tenant_id);

        if ($request->boolean('staff')) {
            $query->whereDoesntHave('customerProfile');
        }

        return response()->json($query->orderBy('name')->get(['id', 'name']));
    }
}
