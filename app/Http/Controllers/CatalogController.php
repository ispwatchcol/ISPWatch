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
     */
    public function users(Request $request)
    {
        return response()->json(
            User::where('tenant_id', $request->user()->tenant_id)
                ->orderBy('name')
                ->get(['id', 'name'])
        );
    }
}
