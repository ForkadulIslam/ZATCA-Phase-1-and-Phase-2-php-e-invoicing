<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/phase-1', [App\Http\Controllers\ZatcaController::class, 'phase1']);
Route::post('/api/zatca/phase-2', [App\Http\Controllers\ZatcaController::class, 'phase2']);
Route::get('/api/zatca/invoices', [App\Http\Controllers\ZatcaController::class, 'getInvoices']);
Route::get('/api/zatca/invoices/{invoice}/download-xml', [App\Http\Controllers\ZatcaController::class, 'downloadXml']);


Route::post('/api/zatca/onboard', [App\Http\Controllers\ZatcaController::class, 'onboard']);
Route::post('/api/zatca/issue-invoice', [App\Http\Controllers\ZatcaController::class, 'issueInvoice']);
Route::post('/api/zatca/issue-standard-invoice', [App\Http\Controllers\ZatcaController::class, 'issueStandardInvoice']);
Route::post('/api/zatca/issue-credit-note', [App\Http\Controllers\ZatcaController::class, 'issueCreditNote']);
Route::post('/api/zatca/issue-standard-credit-note', [App\Http\Controllers\ZatcaController::class, 'issueStandardCreditNote']);
Route::post('/api/zatca/issue-debit-note', [App\Http\Controllers\ZatcaController::class, 'issueDebitNote']);
Route::post('/api/zatca/issue-standard-debit-note', [App\Http\Controllers\ZatcaController::class, 'issueStandardDebitNote']);
Route::post('/api/zatca/request-pcsid', [App\Http\Controllers\ZatcaController::class, 'requestPcsid']);
Route::match(['get', 'post'], '/api/zatca/match-branch/{branch_product_key?}', [App\Http\Controllers\ZatcaController::class, 'matchBranchProductKey']);
Route::match(['get', 'post'], '/api/zatca/verify-branch/{branch_product_key?}', [App\Http\Controllers\ZatcaController::class, 'matchBranchProductKey']);
Route::match(['get', 'post'], '/api/zatca/branch-product-key/{branch_product_key?}', [App\Http\Controllers\ZatcaController::class, 'matchBranchProductKey']);
