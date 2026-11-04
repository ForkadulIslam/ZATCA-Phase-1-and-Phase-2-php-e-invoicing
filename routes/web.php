<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/phase-1', [App\Http\Controllers\ZatcaController::class, 'phase1']);
Route::post('/api/zatca/phase-2', [App\Http\Controllers\ZatcaController::class, 'phase2']);
Route::get('/api/zatca/invoices', [App\Http\Controllers\ZatcaController::class, 'getInvoices']);
Route::get('/api/zatca/invoices/{invoice}/download-xml', [App\Http\Controllers\ZatcaController::class, 'downloadXml']);
