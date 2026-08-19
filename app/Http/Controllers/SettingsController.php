<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    /**
     * Clear the application cache.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearCache()
    {
        try {
            // Run the optimize:clear command which clears:
            // - Events
            // - Views
            // - Cache
            // - Route
            // - Config
            // - Compiled
            Artisan::call('optimize:clear');

            return response()->json([
                'success' => true,
                'message' => 'System cache cleared successfully',
                'output' => Artisan::output()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to clear cache: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cache: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Versión del producto que está corriendo en este despliegue.
     *
     * POR QUÉ ES UN ENDPOINT Y NO UN TEXTO EN EL FRONTEND
     * El número vivía escrito a mano dentro de `Settings.vue` y decía `v1.0.0`
     * pasara lo que pasara: la pantalla no informaba de la versión, informaba de
     * lo que alguien tecleó una vez. Un dato que no puede estar equivocado —
     * porque lo primero que pregunta soporte es «¿qué versión te aparece?»—
     * tiene que venir del servidor que atiende, no del bundle del navegador,
     * que además puede estar cacheado de un despliegue anterior.
     *
     * SIN PERMISO A PROPÓSITO
     * Saber qué versión usa uno mismo no es un dato reservado, y exigir
     * `view_settings` dejaría sin poder responder esa pregunta justamente al
     * usuario que llama a soporte.
     */
    public function version()
    {
        return response()->json([
            'version'     => config('version.number'),
            'released_at' => config('version.released_at'),
        ]);
    }
}
