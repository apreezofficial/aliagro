<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\DeliveryAddressController;
use App\Http\Controllers\Api\FarmerProfileController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AliAgro API Routes
|--------------------------------------------------------------------------
*/

// ── Public Auth ──────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('register',        [AuthController::class, 'register']);
    Route::post('login',           [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',  [AuthController::class, 'resetPassword']);

    // Google OAuth
    Route::get('google',           [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('google/callback',  [SocialAuthController::class, 'handleGoogleCallback']);

    // Email verification
    Route::get('verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->name('verification.verify');
});

// ── Public: Marketplace ───────────────────────────────────────────────────────
Route::get('products',                  [ProductController::class, 'index']);
Route::get('products/{product}',        [ProductController::class, 'show']);
Route::get('products/{product}/reviews',[ReviewController::class, 'index']);
Route::get('categories',                [CategoryController::class, 'index']);
Route::get('categories/{category}',     [CategoryController::class, 'show']);
Route::get('farmers/{userId}/profile',  [FarmerProfileController::class, 'publicProfile']);

// ── Authenticated Routes ──────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('auth/logout',                  [AuthController::class, 'logout']);
    Route::get('auth/me',                       [AuthController::class, 'me']);
    Route::post('auth/email/resend',            [AuthController::class, 'sendVerificationEmail']);
    Route::post('auth/change-password',         [AuthController::class, 'changePassword']);

    // Profile
    Route::put('profile',                       [ProfileController::class, 'update']);
    Route::post('profile/avatar',               [ProfileController::class, 'uploadAvatar']);
    Route::delete('profile',                    [ProfileController::class, 'destroy']);

    // KYC
    Route::post('kyc',                          [KycController::class, 'submit']);
    Route::get('kyc/status',                    [KycController::class, 'status']);

    // Cart
    Route::get('cart',                          [CartController::class, 'index']);
    Route::post('cart',                         [CartController::class, 'add']);
    Route::put('cart/{cartItem}',               [CartController::class, 'update']);
    Route::delete('cart/{cartItem}',            [CartController::class, 'remove']);
    Route::delete('cart',                       [CartController::class, 'clear']);

    // Wishlist
    Route::get('wishlist',                      [WishlistController::class, 'index']);
    Route::post('wishlist/toggle',              [WishlistController::class, 'toggle']);

    // Delivery Addresses
    Route::apiResource('addresses', DeliveryAddressController::class);

    // Coupon validation
    Route::post('coupons/validate',             [CouponController::class, 'validate']);

    // ── Consumer Routes ───────────────────────────────────────────────────────
    Route::middleware('role:consumer,farmer,admin')->group(function () {
        Route::post('orders',                   [OrderController::class, 'store']);
        Route::get('orders',                    [OrderController::class, 'myOrders']);
        Route::get('orders/{order}',            [OrderController::class, 'show']);
        Route::post('orders/{order}/cancel',    [OrderController::class, 'cancel']);
        Route::post('products/{product}/reviews', [ReviewController::class, 'store']);
        Route::delete('reviews/{review}',       [ReviewController::class, 'destroy']);
    });

    // ── Farmer Routes ─────────────────────────────────────────────────────────
    Route::middleware('role:farmer,admin')->group(function () {
        // Farm profile
        Route::get('farmer/profile',            [FarmerProfileController::class, 'show']);
        Route::post('farmer/profile',           [FarmerProfileController::class, 'upsert']);
        Route::post('farmer/profile/images',    [FarmerProfileController::class, 'uploadImages']);

        // Products
        Route::get('farmer/products',           [ProductController::class, 'myProducts']);
        Route::post('products',                 [ProductController::class, 'store']);
        Route::put('products/{product}',        [ProductController::class, 'update']);
        Route::post('products/{product}/images',[ProductController::class, 'addImages']);
        Route::delete('products/{product}',     [ProductController::class, 'destroy']);

        // Farmer orders
        Route::get('farmer/orders',             [OrderController::class, 'farmerOrders']);
        Route::put('order-items/{itemId}/status', [OrderController::class, 'updateItemStatus']);
    });

    // ── Admin Routes ──────────────────────────────────────────────────────────
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('dashboard',                 [AdminController::class, 'dashboard']);

        // Users
        Route::get('users',                     [AdminController::class, 'users']);
        Route::put('users/{user}/status',       [AdminController::class, 'toggleUserStatus']);

        // KYC
        Route::get('kyc',                       [KycController::class, 'index']);
        Route::post('kyc/{kyc}/approve',        [KycController::class, 'approve']);
        Route::post('kyc/{kyc}/reject',         [KycController::class, 'reject']);

        // Orders
        Route::get('orders',                    [AdminController::class, 'orders']);
        Route::put('orders/{order}/status',     [AdminController::class, 'updateOrderStatus']);

        // Products
        Route::get('products',                  [AdminController::class, 'products']);
        Route::post('products/{product}/feature', [AdminController::class, 'toggleFeatured']);

        // Categories
        Route::post('categories',               [CategoryController::class, 'store']);
        Route::put('categories/{category}',     [CategoryController::class, 'update']);
        Route::delete('categories/{category}',  [CategoryController::class, 'destroy']);

        // Coupons
        Route::get('coupons',                   [CouponController::class, 'index']);
        Route::post('coupons',                  [CouponController::class, 'store']);
        Route::delete('coupons/{coupon}',       [CouponController::class, 'destroy']);

        // Transactions
        Route::get('transactions',              [AdminController::class, 'transactions']);
    });
});
