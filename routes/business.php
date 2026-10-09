<?php

// Author: ramanpal singh | URL: https://kwebby.com

use App\Http\Controllers\Business\BillingController;
use App\Http\Controllers\Business\EmployeeController;
use App\Http\Controllers\Business\FinanceController;
use App\Http\Controllers\Business\OperationsController;
use App\Http\Controllers\Business\PipelineController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware('auth')->group(function () {
    Route::get('{kind}', [BillingController::class, 'workIndex'])->whereIn('kind', ['time-entries', 'expenses']);
    Route::post('{kind}', [BillingController::class, 'workCreate'])->whereIn('kind', ['time-entries', 'expenses']);
    Route::get('{kind}/{id}', [BillingController::class, 'workShow'])->whereIn('kind', ['time-entries', 'expenses']);
    Route::patch('{kind}/{id}', [BillingController::class, 'workUpdate'])->whereIn('kind', ['time-entries', 'expenses']);
    Route::delete('{kind}/{id}', [BillingController::class, 'workArchive'])->whereIn('kind', ['time-entries', 'expenses']);
    Route::post('{kind}/{id}/{action}', [BillingController::class, 'workTransition'])->whereIn('kind', ['time-entries', 'expenses'])->whereIn('action', ['submit', 'approve', 'reject'])->middleware('fresh');
    Route::post('billing/draft-invoice', [BillingController::class, 'draftInvoice'])->middleware('fresh');
    Route::get('recurring-invoices', [BillingController::class, 'recurringIndex']);
    Route::post('recurring-invoices', [BillingController::class, 'recurringCreate'])->middleware('fresh');
    Route::get('recurring-invoices/{id}', [BillingController::class, 'recurringShow']);
    Route::patch('recurring-invoices/{id}', [BillingController::class, 'recurringUpdate'])->middleware('fresh');
    Route::post('recurring-invoices/{id}/approve', [BillingController::class, 'recurringApprove'])->middleware('fresh');
    Route::post('recurring-invoices/{id}/pause', [BillingController::class, 'recurringPause'])->middleware('fresh');

    Route::get('records/{collection}', [OperationsController::class, 'index']);
    Route::post('records/{collection}', [OperationsController::class, 'store']);
    Route::get('records/{collection}/{id}', [OperationsController::class, 'show']);
    Route::patch('records/{collection}/{id}', [OperationsController::class, 'update']);
    Route::delete('records/{collection}/{id}', [OperationsController::class, 'destroy']);
    Route::get('leads/{id}/conflict-matches', [OperationsController::class, 'conflictMatches']);
    Route::post('leads/{id}/conflict-review', [OperationsController::class, 'conflictReview']);
    Route::post('leads/{id}/engagement', [OperationsController::class, 'engagement']);
    Route::post('leads/{id}/convert', [OperationsController::class, 'convert']);
    Route::get('invoices', [FinanceController::class, 'invoices']);
    Route::post('invoices', [FinanceController::class, 'createInvoice']);
    Route::get('invoices/{id}', [FinanceController::class, 'invoice']);
    Route::patch('invoices/{id}', [FinanceController::class, 'updateInvoice']);
    Route::post('invoices/{id}/issue', [FinanceController::class, 'issueInvoice'])->middleware('fresh');
    Route::get('invoices/{id}/history', [FinanceController::class, 'invoiceHistory']);
    Route::get('credit-notes/{id}/pdf', [FinanceController::class, 'creditPdf']);
    Route::get('invoices/{id}/pdf', [FinanceController::class, 'invoicePdf']);
    Route::post('invoices/{id}/payments', [FinanceController::class, 'payInvoice'])->middleware('fresh');
    Route::post('invoices/{id}/credits', [FinanceController::class, 'creditInvoice'])->middleware('fresh');
    Route::post('invoices/{id}/checkout', [FinanceController::class, 'checkout']);
    Route::get('payment-gateways', [FinanceController::class, 'gateways']);
    Route::post('checkouts/{id}/reconcile', [FinanceController::class, 'reconcile']);
    Route::post('payments/{id}/refund', [FinanceController::class, 'refund'])->middleware('fresh');
    Route::get('payments/{id}/receipt', [FinanceController::class, 'receipt']);
    Route::get('payroll-runs', [FinanceController::class, 'payrollRuns']);
    Route::post('payroll-runs', [FinanceController::class, 'createPayroll'])->middleware('fresh');
    Route::patch('payroll-runs/{id}', [FinanceController::class, 'updatePayroll'])->middleware('fresh');
    Route::post('payroll-runs/{id}/{action}', [FinanceController::class, 'transitionPayroll'])->whereIn('action', ['review', 'approve', 'release'])->middleware('fresh');
    Route::get('payroll-runs/{id}/csv', [FinanceController::class, 'payrollCsv'])->middleware('fresh');
    Route::get('payslips', [FinanceController::class, 'payslips']);
    Route::get('payslips/{id}/pdf', [FinanceController::class, 'payslipPdf']);
    Route::post('payslips/{id}/payment', [FinanceController::class, 'payslipPayment'])->middleware('fresh');
    Route::get('invoice-design', [FinanceController::class, 'design']);
    Route::patch('invoice-design', [FinanceController::class, 'saveDesign'])->middleware('fresh');
    Route::get('invoice-design/preview', [FinanceController::class, 'previewDesign']);
});

// CSRF exclusions are narrowly configured by the application bootstrap for these signed endpoints.
Route::post('api/v1/payments/webhooks/{provider}', [FinanceController::class, 'webhook'])->whereIn('provider', ['stripe', 'paypal'])->middleware('throttle:120,1');

Route::middleware('auth')->prefix('api/v1')->group(function () {
    Route::get('employee-inputs/{kind}', [EmployeeController::class, 'index']);
    Route::post('employee-inputs/{kind}', [EmployeeController::class, 'store']);
    Route::post('employee-inputs/{kind}/{id}/decision', [EmployeeController::class, 'decide'])->middleware('fresh');
    Route::post('payroll-from-inputs', [EmployeeController::class, 'generate'])->middleware('fresh');
});

Route::middleware('auth')->prefix('api/v1')->group(function () {
    Route::get('pipelines', [PipelineController::class, 'index']);
    Route::post('pipelines', [PipelineController::class, 'save'])->middleware('fresh');
    Route::patch('pipelines/{id}', [PipelineController::class, 'save'])->middleware('fresh');
    Route::post('leads/{id}/pipeline', [PipelineController::class, 'move']);
});
