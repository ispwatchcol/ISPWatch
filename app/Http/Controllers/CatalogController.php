<?php

namespace App\Http\Controllers;

use App\Models\CutType;
use App\Models\ScriptVersion;
use App\Models\TypeBilling;
use App\Models\User;
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
