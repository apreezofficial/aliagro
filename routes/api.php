<?php

/*
|--------------------------------------------------------------------------
| AliAgro API routes ($router is provided by Core\Kernel)
|--------------------------------------------------------------------------
| Order matters: the first matching route wins, so static paths such as
| products/trending must come before products/{product}.
*/

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\BadgeController;
use App\Controllers\CartController;
use App\Controllers\CategoryController;
use App\Controllers\CouponController;
use App\Controllers\DeliveryAddressController;
use App\Controllers\FarmerProfileController;
use App\Controllers\FollowController;
use App\Controllers\GdprController;
use App\Controllers\KycController;
use App\Controllers\LoginActivityController;
use App\Controllers\LoyaltyController;
use App\Controllers\OrderController;
use App\Controllers\PaymentController;
use App\Controllers\ProductController;
use App\Controllers\ProfileController;
use App\Controllers\ReferralController;
use App\Controllers\ReviewController;
use App\Controllers\SearchController;
use App\Controllers\SocialAuthController;
use App\Controllers\WalletController;
use App\Controllers\WishlistController;

/** @var App\Core\Router $router */

// Health check / landing
$router->get('/', [App\Controllers\HealthController::class, 'index']);
$router->get('/up', [App\Controllers\HealthController::class, 'index']);

$router->group(['prefix' => 'api'], function ($r) {

    // ── Public auth (10 req/min per IP) ──────────────────────────────────
    $r->group(['prefix' => 'auth', 'middleware' => ['throttle:auth']], function ($r) {
        $r->post('register',        [AuthController::class, 'register']);
        $r->post('login',           [AuthController::class, 'login']);
        $r->post('forgot-password', [AuthController::class, 'forgotPassword']);
        $r->post('reset-password',  [AuthController::class, 'resetPassword']);

        $r->get('google',          [SocialAuthController::class, 'redirectToGoogle']);
        $r->get('google/callback', [SocialAuthController::class, 'handleGoogleCallback']);

        $r->get('verify/{id}/{hash}', [AuthController::class, 'verifyEmail']);
    });

    // ── Public marketplace ───────────────────────────────────────────────
    $r->get('products',                   [ProductController::class, 'index']);
    $r->get('products/trending',          [SearchController::class, 'trending']);
    $r->get('products/{product}',         [ProductController::class, 'show']);
    $r->get('products/{product}/reviews', [ReviewController::class, 'index']);
    $r->get('categories',                 [CategoryController::class, 'index']);
    $r->get('categories/{category}',      [CategoryController::class, 'show']);
    $r->get('farmers/{userId}/profile',   [FarmerProfileController::class, 'publicProfile']);
    $r->get('farmers/{userId}/badges',    [BadgeController::class, 'farmerBadges']);
    $r->get('farmers/{userId}/followers', [FollowController::class, 'followers']);
    $r->get('search',                     [SearchController::class, 'search']);

    // ── Payment webhooks (signature checked in the controller) ───────────
    $r->post('payments/paystack/webhook',    [PaymentController::class, 'paystackWebhook']);
    $r->post('payments/flutterwave/webhook', [PaymentController::class, 'flutterwaveWebhook']);

    // ── Authenticated ────────────────────────────────────────────────────
    $r->group(['middleware' => ['auth']], function ($r) {

        $r->post('auth/logout',          [AuthController::class, 'logout']);
        $r->get('auth/me',               [AuthController::class, 'me']);
        $r->post('auth/email/resend',    [AuthController::class, 'sendVerificationEmail']);
        $r->post('auth/change-password', [AuthController::class, 'changePassword']);

        $r->put('profile',         [ProfileController::class, 'update']);
        $r->post('profile/avatar', [ProfileController::class, 'uploadAvatar']);
        $r->delete('profile',      [ProfileController::class, 'destroy']);

        $r->post('kyc',        [KycController::class, 'submit']);
        $r->get('kyc/status',  [KycController::class, 'status']);

        $r->get('cart',             [CartController::class, 'index']);
        $r->post('cart',            [CartController::class, 'add']);
        $r->put('cart/{cartItem}',  [CartController::class, 'update']);
        $r->delete('cart/{cartItem}', [CartController::class, 'remove']);
        $r->delete('cart',          [CartController::class, 'clear']);

        $r->get('wishlist',         [WishlistController::class, 'index']);
        $r->post('wishlist/toggle', [WishlistController::class, 'toggle']);

        $r->apiResource('addresses', DeliveryAddressController::class, 'address');

        $r->post('coupons/validate', [CouponController::class, 'validate']);

        $r->get('search/recently-viewed',     [SearchController::class, 'recentlyViewed']);
        $r->get('search/recommended',         [SearchController::class, 'recommended']);
        $r->post('products/{productId}/view', [SearchController::class, 'trackView']);

        $r->post('payments/initialize', [PaymentController::class, 'initializeOrderPayment']);
        $r->post('payments/topup',      [PaymentController::class, 'initializeTopup']);
        $r->post('payments/verify',     [PaymentController::class, 'verifyPayment']);

        $r->get('wallet',      [WalletController::class, 'index']);
        $r->post('wallet/pay', [WalletController::class, 'payWithWallet']);

        $r->get('loyalty',          [LoyaltyController::class, 'index']);
        $r->post('loyalty/redeem',  [LoyaltyController::class, 'redeem']);

        $r->get('referrals',            [ReferralController::class, 'index']);
        $r->post('referrals/validate',  [ReferralController::class, 'validate']);

        $r->get('badges',      [BadgeController::class, 'index']);
        $r->get('badges/mine', [BadgeController::class, 'myBadges']);

        $r->post('farmers/{farmerId}/follow', [FollowController::class, 'toggle']);
        $r->get('following',                  [FollowController::class, 'following']);

        $r->get('login-activity', [LoginActivityController::class, 'index']);

        $r->get('gdpr/export',          [GdprController::class, 'export']);
        $r->delete('gdpr/delete-account', [GdprController::class, 'requestDeletion']);

        // ── Consumers ────────────────────────────────────────────────────
        $r->group(['middleware' => ['role:consumer,farmer,admin']], function ($r) {
            $r->post('orders',                      [OrderController::class, 'store']);
            $r->get('orders',                       [OrderController::class, 'myOrders']);
            $r->get('orders/{order}',               [OrderController::class, 'show']);
            $r->post('orders/{order}/cancel',       [OrderController::class, 'cancel']);
            $r->post('products/{product}/reviews',  [ReviewController::class, 'store']);
            $r->delete('reviews/{review}',          [ReviewController::class, 'destroy']);
        });

        // ── Farmers ──────────────────────────────────────────────────────
        $r->group(['middleware' => ['role:farmer,admin']], function ($r) {
            $r->get('farmer/profile',         [FarmerProfileController::class, 'show']);
            $r->post('farmer/profile',        [FarmerProfileController::class, 'upsert']);
            $r->post('farmer/profile/images', [FarmerProfileController::class, 'uploadImages']);

            $r->get('farmer/products',               [ProductController::class, 'myProducts']);
            $r->post('products',                     [ProductController::class, 'store']);
            $r->put('products/{product}',            [ProductController::class, 'update']);
            $r->post('products/{product}/images',    [ProductController::class, 'addImages']);
            $r->delete('products/{product}',         [ProductController::class, 'destroy']);

            $r->get('farmer/orders',                 [OrderController::class, 'farmerOrders']);
            $r->put('order-items/{itemId}/status',   [OrderController::class, 'updateItemStatus']);
        });

        // ── Admin ────────────────────────────────────────────────────────
        $r->group(['prefix' => 'admin', 'middleware' => ['role:admin']], function ($r) {
            $r->get('dashboard', [AdminController::class, 'dashboard']);

            $r->get('users',                 [AdminController::class, 'users']);
            $r->put('users/{user}/status',   [AdminController::class, 'toggleUserStatus']);

            $r->get('kyc',                [KycController::class, 'index']);
            $r->post('kyc/{kyc}/approve', [KycController::class, 'approve']);
            $r->post('kyc/{kyc}/reject',  [KycController::class, 'reject']);

            $r->get('orders',                 [AdminController::class, 'orders']);
            $r->put('orders/{order}/status',  [AdminController::class, 'updateOrderStatus']);

            $r->get('products',                  [AdminController::class, 'products']);
            $r->post('products/{product}/feature', [AdminController::class, 'toggleFeatured']);

            $r->post('categories',              [CategoryController::class, 'store']);
            $r->put('categories/{category}',    [CategoryController::class, 'update']);
            $r->delete('categories/{category}', [CategoryController::class, 'destroy']);

            $r->get('coupons',              [CouponController::class, 'index']);
            $r->post('coupons',             [CouponController::class, 'store']);
            $r->delete('coupons/{coupon}',  [CouponController::class, 'destroy']);

            $r->get('transactions', [AdminController::class, 'transactions']);

            $r->post('badges',        [BadgeController::class, 'store']);
            $r->post('badges/award',  [BadgeController::class, 'award']);
            $r->post('badges/revoke', [BadgeController::class, 'revoke']);

            $r->get('wallets/{userId}', [WalletController::class, 'adminView']);

            $r->get('users/{userId}/login-activity', [LoginActivityController::class, 'adminIndex']);
        });
    });
});
