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
}
