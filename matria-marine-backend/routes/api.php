<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MmsUpdateController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public Routes (Prefix will be /api/...)
Route::post('/login', [AuthController::class, 'login']);
Route::get('/media', [MediaController::class, 'index']);
Route::get('/updates', [MmsUpdateController::class, 'index']);

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