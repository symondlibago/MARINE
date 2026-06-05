<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MmsUpdateController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\PublicPurchaseOrderController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\RfqController;
use App\Http\Controllers\RfqPdfController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseInvoiceController;
use App\Http\Controllers\ReportsController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public Routes (Prefix will be /api/...)
Route::post('/login', [AuthController::class, 'login']);
Route::get('/media', [MediaController::class, 'index']);
Route::get('/updates', [MmsUpdateController::class, 'index']);

// Public vendor quote endpoints (magic link — NO auth). The {token} resolves to a
// single vendor's view of one enquiry via rfq_vendors.
Route::get('/quote/{token}', [QuoteController::class, 'show'])->where('token', '[A-Za-z0-9\-_]+');
Route::post('/quote/{token}', [QuoteController::class, 'submit'])->where('token', '[A-Za-z0-9\-_]+');

// Public purchase-order acceptance (magic link — NO auth). The {token} resolves
// to one vendor's view of a single purchase order so they can confirm it.
Route::get('/po/{token}', [PublicPurchaseOrderController::class, 'show'])->where('token', '[A-Za-z0-9\-_]+');
Route::post('/po/{token}/accept', [PublicPurchaseOrderController::class, 'accept'])->where('token', '[A-Za-z0-9\-_]+');

// Protected Routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::post('/user/update-profile', [AuthController::class, 'updateProfile']);
    Route::post('/user/update-password', [AuthController::class, 'updatePassword']);

    Route::post('/user/email/initiate', [AuthController::class, 'initiateEmailUpdate']);
    Route::post('/user/email/complete', [AuthController::class, 'completeEmailUpdate']);

    Route::post('/media/upload', [MediaController::class, 'storeItem']);
    Route::put('/media/category/{id}', [MediaController::class, 'updateCategory']);
    Route::delete('/media/item/{id}', [MediaController::class, 'deleteItem']);
    Route::delete('/media/category/{id}', [MediaController::class, 'deleteCategory']);

    Route::post('/updates', [MmsUpdateController::class, 'store']);
    Route::put('/updates/{id}', [MmsUpdateController::class, 'update']);
    Route::delete('/updates/{id}', [MmsUpdateController::class, 'destroy']);
});

// Authenticated staff portal API for the procurement module.
// Gated by Sanctum auth + active account + spatie role.
Route::middleware(['auth:sanctum', 'active', 'role:admin|staff'])
    ->prefix('portal')
    ->group(function () {
        // Smoke endpoint — confirms auth + active + role gating works end to end.
        Route::get('/ping', function (Request $request) {
            return response()->json([
                'success' => true,
                'message' => 'portal pong',
                'data' => [
                    'user' => $request->user()->only(['id', 'name', 'email', 'role']),
                    'roles' => $request->user()->getRoleNames(),
                ],
            ]);
        });

        // Phase 1 masters
        Route::apiResource('vendors', VendorController::class);

        // Phase 2 — enquiry (RFQ) spine
        Route::get('item-suggestions', [RfqController::class, 'itemSuggestions']);
        Route::apiResource('rfqs', RfqController::class);
        Route::get('rfqs/{rfq}/compare', [RfqController::class, 'compare']);
        Route::post('rfqs/{rfq}/send', [RfqController::class, 'send']);
        Route::post('rfqs/{rfq}/awards', [RfqController::class, 'saveAwards']);
        Route::post('rfqs/{rfq}/finish', [RfqController::class, 'finish']);
        Route::post('rfqs/{rfq}/reopen', [RfqController::class, 'reopen']);
        Route::patch('quotes/{quote}', [RfqController::class, 'updateQuoteRate']);
        Route::get('rfqs/{rfq}/vendors/{vendor}/award-pdf', [RfqPdfController::class, 'vendorAward']);
        Route::get('rfqs/{rfq}/quotation-pdf', [RfqPdfController::class, 'summary']);

        // Phase 3 — purchase orders (awards -> POs to vendors)
        Route::post('rfqs/{rfq}/purchase-orders', [PurchaseOrderController::class, 'generate']);
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::get('purchase-orders/{purchaseOrder}/pdf', [PurchaseOrderController::class, 'pdf']);
        Route::post('purchase-orders/{purchaseOrder}/email', [PurchaseOrderController::class, 'email']);
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
        Route::patch('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
        Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy']);

        // Phase 4 — purchase invoices + Navision/Business Central CSV export
        Route::post('purchase-orders/{purchaseOrder}/invoice', [PurchaseInvoiceController::class, 'createFromPo']);
        Route::post('purchase-invoices/export', [PurchaseInvoiceController::class, 'export']);
        Route::get('purchase-invoices', [PurchaseInvoiceController::class, 'index']);
        Route::post('purchase-invoices', [PurchaseInvoiceController::class, 'store']);
        Route::get('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'show']);
        Route::patch('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'update']);
        Route::delete('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'destroy']);

        // Phase 5 — reports / analytics
        Route::get('reports/spend', [ReportsController::class, 'spend']);
        Route::get('reports/vendors', [ReportsController::class, 'vendors']);
        Route::get('reports/pipeline', [ReportsController::class, 'pipeline']);
    });
