<?php

use App\Http\Controllers\Payroll\EmployeeController;
use App\Http\Controllers\Payroll\PdfController;
use App\Http\Controllers\Payroll\RunController;
use App\Http\Controllers\Payroll\SettingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payroll — API routes
|--------------------------------------------------------------------------
|
| A separate operation from procurement and from provisions, but the same staff
| accounts: every endpoint sits behind the same Sanctum + active + role gate and
| is prefixed with /api/payroll.
|
| Required from api.php: require __DIR__.'/payroll.php';
|
*/

// page.access closes the whole of /api/payroll to an admin who was not given
// Payroll — salaries are the reason these checkboxes exist.
Route::middleware(['auth:sanctum', 'active', 'role:super_admin|admin', 'page.access'])
    ->prefix('payroll')
    ->group(function () {

        // Employee master — entered once, read by every month
        Route::get('employees', [EmployeeController::class, 'index']);
        Route::post('employees', [EmployeeController::class, 'store']);
        Route::put('employees/{employee}', [EmployeeController::class, 'update']);
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy']);

        // Payroll months
        Route::get('runs', [RunController::class, 'index']);
        Route::post('runs', [RunController::class, 'store']);
        Route::get('runs/{run}', [RunController::class, 'show']);
        Route::patch('runs/{run}', [RunController::class, 'update']);
        Route::delete('runs/{run}', [RunController::class, 'destroy']);

        Route::post('runs/{run}/lines', [RunController::class, 'addLine']);
        Route::patch('runs/{run}/lines/{line}', [RunController::class, 'updateLine']);
        Route::delete('runs/{run}/lines/{line}', [RunController::class, 'removeLine']);

        // Pull corrected employee details into an open month
        Route::post('runs/{run}/refresh', [RunController::class, 'syncFromEmployees']);

        Route::post('runs/{run}/finalise', [RunController::class, 'finalise']);
        Route::post('runs/{run}/reopen', [RunController::class, 'reopen']);

        // The two printed documents
        Route::get('runs/{run}/summary.pdf', [PdfController::class, 'summary']);
        Route::get('runs/{run}/lines/{line}/payslip.pdf', [PdfController::class, 'payslip']);

        // Letterhead and the statutory figures on show
        Route::get('settings', [SettingController::class, 'show']);
        Route::put('settings', [SettingController::class, 'update']);
    });
