<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CuponController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\PedidoPaymentController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProductoController;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductoController::class, 'index']);
Route::get('/products/{id}', [ProductoController::class, 'show']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'updateInfo']);
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    Route::post('/coupon/validate', [CuponController::class, 'validateCoupon']);

    Route::post('/orders', [PedidoController::class, 'store']);
    Route::get('/orders', [PedidoController::class, 'index']);
    Route::get('/orders/{id}', [PedidoController::class, 'show']);
    Route::put('/orders/{id}/cancel', [PedidoController::class, 'cancel']);

    Route::post('/orders/{id}/payments/prepare', [PedidoPaymentController::class, 'prepare']);
    Route::post('/orders/{id}/payments/confirm', [PedidoPaymentController::class, 'confirm']);
    Route::post('/orders/{id}/payments/checkout-session', [PedidoPaymentController::class, 'checkoutSession']);
    Route::post('/orders/{id}/payments/checkout-verify', [PedidoPaymentController::class, 'checkoutVerify']);
});
