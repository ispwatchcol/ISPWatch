<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/dashboard');
});

// Portal de pago para usuarios suspendidos
Route::get('/portal-pago', function () {
    return view('payment-portal');
});



Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');
