<?php

use App\Http\Controllers\Inventory\CrewController;
use App\Http\Controllers\Inventory\CrewRationController;
use App\Http\Controllers\Inventory\DashboardController;
use App\Http\Controllers\Inventory\OrderRequestController;
use App\Http\Controllers\Inventory\ItemController;
use App\Http\Controllers\Inventory\PeriodController;
use App\Http\Controllers\Inventory\ReceiptController;
use App\Http\Controllers\Inventory\ReportController;
use App\Http\Controllers\Inventory\RequisitionController;
use App\Http\Controllers\Inventory\SupplierController;
use App\Http\Controllers\Inventory\VesselAccountController;
use App\Http\Controllers\Inventory\VesselController;
use App\Http\Controllers\Inventory\WithdrawalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vessel Provision Inventory — API routes
|--------------------------------------------------------------------------
|
| A separate operation from procurement. The office signs in with the same
| accounts it uses there; each vessel also has two of its own, the master and
| the cook, which reach this module and nothing else.
|
| inventory.access is the gate: which rank may do what, and the vessel a crew
| account is pinned to. Defined once in App\Support\Inventory\VesselAccess.
|
| Required from api.php: require __DIR__.'/inventory.php';
|
*/

Route::middleware(['auth:sanctum', 'active', 'inventory.access'])
    ->prefix('inventory')
    ->group(function () {

        // Dashboard — budget, order value, balance, status counts
        Route::get('dashboard', [DashboardController::class, 'index']);

        // The fleet, and the reference values behind every calculation
        Route::get('vessels', [VesselController::class, 'index']);
        Route::post('vessels', [VesselController::class, 'store']);
        Route::get('settings', [VesselController::class, 'show']);
        Route::put('vessels/{vessel}', [VesselController::class, 'update']);
        Route::delete('vessels/{vessel}', [VesselController::class, 'destroy']);
        Route::post('vessels/{vessel}/restore', [VesselController::class, 'restore']);
        // Catch a ship up with the master provision list.
        Route::post('vessels/{vessel}/catalogue', [VesselController::class, 'catalogue']);

        // The logins that sail on her — the master and the cook. Office only.
        Route::get('vessels/{vessel}/accounts', [VesselAccountController::class, 'index']);
        Route::post('vessels/{vessel}/accounts', [VesselAccountController::class, 'store']);
        Route::put('vessels/{vessel}/accounts/{user}', [VesselAccountController::class, 'update']);
        Route::delete('vessels/{vessel}/accounts/{user}', [VesselAccountController::class, 'destroy']);

        // Inventory — the main working screen
        Route::get('categories', [ItemController::class, 'categories']);
        Route::get('items', [ItemController::class, 'index']);
        Route::post('items', [ItemController::class, 'store']);
        Route::post('items/stock', [ItemController::class, 'bulkStock']);
        Route::post('items/prices', [ItemController::class, 'bulkPrices']);
        // Fill the catalogue's blank prices from the quotations the office
        // already sent this vessel
        Route::post('items/prices/from-offers', [ItemController::class, 'pricesFromOffers']);
        Route::patch('items/{item}/stock', [ItemController::class, 'updateStock']);
        Route::put('items/{item}', [ItemController::class, 'update']);
        Route::delete('items/{item}', [ItemController::class, 'destroy']);

        // Requisition — the calculated order, and the saved history of it.
        // It travels: the cook writes it, the master approves it, the office
        // buys it. The inbox is the office's end of that, across the fleet.
        Route::get('requisition/preview', [RequisitionController::class, 'preview']);
        // Both verbs, one method. It asks a question and changes nothing, so
        // GET is the honest verb — but the ship sends the lines she is ordering
        // rather than a total she cannot work out, and a few hundred of those
        // do not belong in a query string.
        Route::get('requisition/budget', [RequisitionController::class, 'budget']);
        Route::post('requisition/budget', [RequisitionController::class, 'budget']);
        Route::get('requisitions', [RequisitionController::class, 'index']);
        Route::get('requisitions/inbox', [RequisitionController::class, 'inbox']);
        Route::post('requisitions', [RequisitionController::class, 'store']);
        Route::post('requisitions/{requisition}/submit', [RequisitionController::class, 'submit']);
        Route::post('requisitions/{requisition}/pull-back', [RequisitionController::class, 'pullBack']);
        Route::post('requisitions/{requisition}/approve', [RequisitionController::class, 'approve']);
        Route::post('requisitions/{requisition}/decline', [RequisitionController::class, 'decline']);
        // The handover: an approved order becomes an ordinary enquiry, stamped
        // as having come from the provisions side
        Route::post('requisitions/{requisition}/to-enquiry', [RequisitionController::class, 'toEnquiry']);
        Route::get('requisitions/{requisition}', [RequisitionController::class, 'show']);
        Route::get('requisitions/{requisition}/pdf', [RequisitionController::class, 'pdf']);
        Route::get('requisitions/{requisition}/receiving-sheet', [ReceiptController::class, 'fromRequisition']);
        Route::patch('requisitions/{requisition}', [RequisitionController::class, 'update']);
        Route::delete('requisitions/{requisition}', [RequisitionController::class, 'destroy']);

        // Receiving — the only thing that raises stock
        Route::get('receipts', [ReceiptController::class, 'index']);
        Route::post('receipts', [ReceiptController::class, 'store']);
        Route::get('receipts/{receipt}', [ReceiptController::class, 'show']);
        Route::put('receipts/{receipt}', [ReceiptController::class, 'update']);
        Route::post('receipts/{receipt}/confirm', [ReceiptController::class, 'confirm']);
        Route::post('receipts/{receipt}/reopen', [ReceiptController::class, 'reopen']);
        Route::delete('receipts/{receipt}', [ReceiptController::class, 'destroy']);

        // Suppliers — price comparison, office use
        Route::get('suppliers', [SupplierController::class, 'index']);
        Route::post('suppliers', [SupplierController::class, 'store']);
        Route::get('suppliers/comparison', [SupplierController::class, 'comparison']);
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy']);
        Route::post('suppliers/{supplier}/prices', [SupplierController::class, 'savePrices']);
        Route::post('suppliers/{supplier}/apply-prices', [SupplierController::class, 'applyPrices']);

        // Withdrawals — what left the store, costed FIFO against its batches,
        // and the batches themselves with what they cost and when they go off
        Route::get('withdrawals', [WithdrawalController::class, 'index']);
        Route::post('withdrawals', [WithdrawalController::class, 'store']);
        Route::delete('withdrawals/{withdrawal}', [WithdrawalController::class, 'destroy']);
        Route::get('stock-lots', [WithdrawalController::class, 'lots']);

        // Supply periods — the stocktake that makes consumption measurable
        Route::get('periods', [PeriodController::class, 'index']);
        Route::post('periods', [PeriodController::class, 'store']);
        Route::get('periods/{period}', [PeriodController::class, 'show']);
        Route::post('periods/{period}/counts', [PeriodController::class, 'saveCounts']);
        Route::post('periods/{period}/close', [PeriodController::class, 'close']);
        Route::delete('periods/{period}', [PeriodController::class, 'destroy']);

        // "Order some of this anyway" — what the cook wants beyond what the
        // calculation raised, kept apart from it and merged into the next order
        Route::get('order-requests', [OrderRequestController::class, 'index']);
        Route::post('order-requests', [OrderRequestController::class, 'store']);
        Route::delete('order-requests/{orderRequest}', [OrderRequestController::class, 'destroy']);

        // Who is aboard — the roster the ration report names
        Route::get('crew', [CrewController::class, 'index']);
        Route::post('crew', [CrewController::class, 'store']);
        Route::post('crew/bulk', [CrewController::class, 'bulk']);
        Route::put('crew/{crew}', [CrewController::class, 'update']);
        Route::delete('crew/{crew}', [CrewController::class, 'destroy']);

        // Crew rations — the daily meal count behind the victualling budget,
        // and the ration report the owner is invoiced from
        Route::get('crew-rations', [CrewRationController::class, 'index']);
        Route::post('crew-rations', [CrewRationController::class, 'save']);
        Route::get('crew-rations/report', [CrewRationController::class, 'report']);
        Route::get('crew-rations/invoice', [CrewRationController::class, 'invoice']);
        Route::get('crew-rations/invoice/pdf', [CrewRationController::class, 'pdf']);

        // Reports — monthly budget vs order value, and spend by category
        Route::get('reports/monthly', [ReportController::class, 'monthly']);
        Route::get('reports/by-category', [ReportController::class, 'byCategory']);
    });
