<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

// Remove 'api' prefix middleware and use 'web' instead for CSRF
Route::post('/login', [AuthController::class, 'login'])->middleware('web');
Route::post('/logout', [AuthController::class, 'logout'])->middleware(['web', 'auth:sanctum']);

// Protected Routes
Route::middleware(['web', 'auth:sanctum'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
});