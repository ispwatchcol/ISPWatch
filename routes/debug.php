<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::get('/debug/type-plans', function () {
    $typePlans = DB::table('type_plans')->orderBy('id')->get();

    return response()->json([
        'type_plans' => $typePlans,
        'message' => 'Verifica el ID de cada tipo de plan'
    ]);
});
