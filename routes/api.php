<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\TripsController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\DriverModerationController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\PricingController;
use App\Http\Controllers\Admin\RidesController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\NotificationsController as AdminNotificationsController;
use App\Http\Controllers\Admin\DeveloperPassengerInboxController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\GeocodingController;
use App\Http\Controllers\VoiceController;
use App\Http\Controllers\RatingsController;
use App\Http\Controllers\PassengerAddressController;

use App\Http\Controllers\WalletController;
use App\Http\Controllers\FcmTokenController;
use App\Http\Controllers\Api\Admin\NotificationController;
use App\Http\Controllers\DriverProfileController;
use App\Http\Controllers\WithdrawController;
use App\Http\Controllers\Admin\PromotionController;
use App\Http\Controllers\Api\MobileLogController;
use App\Http\Controllers\Api\Passenger\PassengerNotificationController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\TopupController;
use App\Http\Controllers\AppVersionController;
use App\Http\Controllers\BiddingController;
use App\Http\Controllers\SubscriptionController;

// Health check endpoint (public)
Route::get('/health', fn() => response()->json(['status' => 'ok', 'timestamp' => now()]));
Route::get('/app/version-check', [AppVersionController::class, 'show'])->middleware('throttle:120,1');
Route::get('/promotions', [PromotionController::class, 'indexPublic']);

// Servir les fichiers de stockage via l'API (photos de profil, etc.)
Route::get('/storage/{path}', [StorageController::class, 'show'])->where('path', '.*');

Route::prefix('auth')->group(function () {
    Route::post('/request-otp', [OtpController::class, 'requestOtp'])->middleware('throttle:otp');
    Route::post('/verify-otp', [OtpController::class, 'verifyOtp'])->middleware('throttle:otp');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [OtpController::class, 'logout']);
        Route::get('/me', [OtpController::class, 'me']);
        Route::put('/profile', [OtpController::class, 'updateProfile']);
        Route::delete('/account', [OtpController::class, 'deleteAccount']);

        // FCM Tokens
        Route::post('/fcm/register', [FcmTokenController::class, 'register']);
        Route::post('/fcm/unregister', [FcmTokenController::class, 'unregister']);
    });
});

// Role-based route groups (scaffolding)
Route::prefix('admin')->group(function () {
    // Admin authentication (email/phone + password)
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'role:admin,developer,super-admin'])->group(function () {
        // Authenticated admin endpoints
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);
        // Health check
        Route::get('/ping', fn() => response()->json(['ok' => true, 'area' => 'admin']));
        Route::get('/drivers/{id}/profile', [DriverModerationController::class, 'showProfile']);

        // Driver moderation
        Route::get('/drivers/pending', [DriverModerationController::class, 'indexPending']);
        Route::get('/drivers/approved', [DriverModerationController::class, 'indexApproved']);
        Route::get('/drivers/online', [DriverModerationController::class, 'online']);
        Route::patch('/drivers/{id}/status', [DriverModerationController::class, 'updateStatus']);
        Route::get('/drivers/{id}/location', [DriverModerationController::class, 'location']);
        Route::get('/drivers/{id}/profile', [DriverModerationController::class, 'showProfile']);
        Route::post('/drivers/{id}/force-offline', [DriverModerationController::class, 'forceOffline']);
        Route::post('/drivers/{id}/force-online', [DriverModerationController::class, 'forceOnline']);

        // Users
        Route::post('/users', [UsersController::class, 'store']);
        Route::get('/users', [UsersController::class, 'index']);
        Route::get('/users/{id}', [UsersController::class, 'show']);
        Route::patch('/users/{id}', [UsersController::class, 'update']);
        Route::delete('/users/{id}', [UsersController::class, 'destroy']);

        // Pricing
        Route::get('/pricing', [PricingController::class, 'get']);
        Route::put('/pricing', [PricingController::class, 'update']);

        // Rides
        Route::get('/rides', [RidesController::class, 'index']);
        Route::post('/rides', [RidesController::class, 'store']);
        Route::post('/rides/{id}/cancel', [RidesController::class, 'cancel']);
        Route::post('/rides/{id}/complete', [RidesController::class, 'complete']);
        Route::post('/rides/{id}/assign', [RidesController::class, 'assign']);
        Route::get('/rides/status-breakdown', [RidesController::class, 'statusBreakdown']);
        Route::get('/passengers/{id}/rides', [RidesController::class, 'byPassenger']);

        // Finance
        Route::get('/finance/summary', [FinanceController::class, 'summary']);
        Route::get('/finance/transactions', [FinanceController::class, 'transactions']);
        Route::get('/finance/overview', [FinanceController::class, 'overview']);
        Route::get('/finance/report', [FinanceController::class, 'report']);
        Route::get('/finance/fleet-economics', [FinanceController::class, 'fleetEconomics']);

        // Wallet admin helpers
        Route::post('/users/{id}/wallet/reset', [WalletController::class, 'adminReset']);

        // Notifications
        Route::post('/notifications/send', [NotificationController::class, 'store']);
        Route::get('/notifications/history', [NotificationController::class, 'index']);
        Route::get('/notifications/campaigns', [NotificationController::class, 'campaigns']);
        Route::put('/notifications/{id}', [NotificationController::class, 'update']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->whereNumber('id');

        // Moderation (accounts, reports)
        Route::get('/moderation/queue', [ModerationController::class, 'queue']);
        Route::get('/moderation/logs', [ModerationController::class, 'logs']);

        Route::get('/stats/drivers/daily', [StatsController::class, 'driversDaily']);
        Route::get('/stats/drivers/daily/global', [StatsController::class, 'driversDailyGlobal']);
        Route::get('/stats/drivers/daily/top', [StatsController::class, 'topDriversDaily']);
        Route::get('/stats/drivers/scores', [StatsController::class, 'driverScores']);
        Route::get('/stats/passengers/segments', [StatsController::class, 'passengerSegments']);
        Route::get('/stats/overview', [StatsController::class, 'overview']);
        Route::get('/stats/trends', [StatsController::class, 'trends']);
        Route::get('/stats/alerts', [StatsController::class, 'alerts']);
        Route::get('/stats/dispatch', [StatsController::class, 'dispatch']);
        Route::get('/stats/dispatch/rides', [StatsController::class, 'dispatchRides']);
        Route::get('/stats/dispatch/rides/{id}', [StatsController::class, 'dispatchRideDetail']);
        Route::get('/stats/map', [StatsController::class, 'strategicMap']);

        // Metrics & Analytics
        Route::get('/metrics', [\App\Http\Controllers\Admin\MetricsController::class, 'index']);
        // Settings
        Route::get('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index']);
        Route::post('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update']);
        
        // Roles & Permissions (RBAC)
        Route::get('/roles-permissions/roles', [\App\Http\Controllers\Admin\RolePermissionController::class, 'index']);
        Route::get('/roles-permissions/permissions', [\App\Http\Controllers\Admin\RolePermissionController::class, 'getPermissions']);
        Route::post('/roles-permissions/roles/{role}/sync', [\App\Http\Controllers\Admin\RolePermissionController::class, 'syncRolePermissions']);
        Route::get('/roles-permissions/staff', [\App\Http\Controllers\Admin\RolePermissionController::class, 'getStaffUsers']);
        Route::post('/roles-permissions/users/{user}/assign', [\App\Http\Controllers\Admin\RolePermissionController::class, 'assignRoleToUser']);

        // Driver Wallet & Debt Management
        Route::get('/drivers/debts', [\App\Http\Controllers\Admin\WalletController::class, 'driversDebts']);
        Route::post('/wallets/{walletId}/adjust', [\App\Http\Controllers\Admin\WalletController::class, 'adjustBalance']);
        Route::get('/wallets/{walletId}/transactions', [\App\Http\Controllers\Admin\WalletController::class, 'transactions']);
        Route::post('/drivers/{driverId}/block', [\App\Http\Controllers\Admin\WalletController::class, 'blockDriver']);
        Route::post('/drivers/{driverId}/unblock', [\App\Http\Controllers\Admin\WalletController::class, 'unblockDriver']);
        Route::post('/external-revenue', [\App\Http\Controllers\Admin\ExternalRevenueController::class, 'store']);

        // Promotions
        Route::get('/promotions', [PromotionController::class, 'index']);
        Route::post('/promotions', [PromotionController::class, 'store']);
        Route::post('/promotions/{id}', [PromotionController::class, 'update']); // Using POST for update to support FormData image upload
        Route::delete('/promotions/{id}', [PromotionController::class, 'destroy']);
        // Promo Codes (discounts)
        Route::get('/promo-codes', [\App\Http\Controllers\Admin\PromoCodeAdminController::class, 'index']);
        Route::post('/promo-codes', [\App\Http\Controllers\Admin\PromoCodeAdminController::class, 'store']);
        Route::put('/promo-codes/{id}', [\App\Http\Controllers\Admin\PromoCodeAdminController::class, 'update']);
        Route::delete('/promo-codes/{id}', [\App\Http\Controllers\Admin\PromoCodeAdminController::class, 'destroy']);
    });

    Route::middleware(['auth:sanctum', 'role:developer'])->group(function () {
        Route::get('/dev/logs', [\App\Http\Controllers\Admin\DeveloperController::class, 'logs']);
        Route::post('/dev/reset-data', [\App\Http\Controllers\Admin\DeveloperController::class, 'resetData']);
        Route::post('/dev/purge-stats', [\App\Http\Controllers\Admin\DeveloperController::class, 'purgeStats']);
        Route::post('/dev/clear-cache', [\App\Http\Controllers\Admin\DeveloperController::class, 'clearCache']);
        Route::post('/dev/rides/confirm-payment', [\App\Http\Controllers\Admin\DeveloperController::class, 'confirmRidePayment']);
        Route::get('/dev/drivers/documents', [\App\Http\Controllers\Admin\DeveloperController::class, 'driverDocuments']);
        Route::post('/dev/drivers/documents/validate', [\App\Http\Controllers\Admin\DeveloperController::class, 'validateDriverDocument']);


        // Analytics (developer only)
        Route::get('/analytics/reconnections', [\App\Http\Controllers\Admin\AnalyticsController::class, 'reconnections']);
        Route::get('/analytics/funnel', [\App\Http\Controllers\Admin\AnalyticsController::class, 'funnel']);

        // Mobile Logs retrieval (developer only)
        Route::get('/dev/mobile-logs', [MobileLogController::class, 'index']);
        Route::post('/dev/mobile-logs/clear', [MobileLogController::class, 'clear']);

        // Inbox passager (écran Notifications app) — QA depuis developer-dashboard
        Route::get('/dev/passenger-inbox/{userId}', [DeveloperPassengerInboxController::class, 'index'])->whereNumber('userId');
        Route::post('/dev/passenger-inbox', [DeveloperPassengerInboxController::class, 'store']);
        Route::patch('/dev/passenger-inbox/{id}', [DeveloperPassengerInboxController::class, 'update'])->whereNumber('id');
        Route::delete('/dev/passenger-inbox/{id}', [DeveloperPassengerInboxController::class, 'destroy'])->whereNumber('id');

        // Moderation actions (restored)
        Route::post('/moderation/{userId}/suspend', [ModerationController::class, 'suspend']);
        Route::post('/moderation/{userId}/ban', [ModerationController::class, 'ban']);
        Route::post('/moderation/{userId}/warn', [ModerationController::class, 'warn']);
        Route::post('/moderation/{userId}/reinstate', [ModerationController::class, 'reinstate']);
    });
});

Route::middleware(['auth:sanctum'])->prefix('driver')->group(function () {
    Route::get('/profile', [DriverProfileController::class, 'show']);
    Route::post('/profile', [DriverProfileController::class, 'store']);
    Route::post('/profile/documents', [DriverProfileController::class, 'uploadDocument']);
    // Accepter le contrat : accessible même si le statut est 'pending'
    Route::post('/contract/accept', [DriverProfileController::class, 'acceptContract']);
    Route::get('/daily-tip', [\App\Http\Controllers\SettingController::class, 'getDailyTip']);
    Route::get('/notifications', [\App\Http\Controllers\Api\Driver\NotificationController::class, 'index']);
    // Status endpoint should be available to all authenticated drivers, not just approved ones
    Route::post('/status', [TripsController::class, 'updateDriverStatus']);
    // Vehicle update endpoint
    Route::post('/update-vehicle', [TripsController::class, 'updateVehicle']);
});

Route::middleware(['auth:sanctum', 'role:driver', 'driver.approved'])->prefix('driver')->group(function () {
    Route::get('/ping', fn() => response()->json(['ok' => true, 'area' => 'driver']));
    Route::post('/location', [TripsController::class, 'updateDriverLocation']);
    Route::get('/rides', [TripsController::class, 'driverRides']);
    Route::get('/rides/{id}', [TripsController::class, 'driverRideShow']);
    Route::get('/current-ride', [TripsController::class, 'driverCurrentRide']);
    Route::get('/stats', [TripsController::class, 'driverStats']);
    // Portefeuille chauffeur (même contrôleur que passager, basé sur user_id)
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/wallet/transactions/today', [WalletController::class, 'todayTransactions']);
    Route::post('/wallet/withdraw', [WithdrawController::class, 'store']);
    Route::get('/next-offer', [TripsController::class, 'driverNextOffer']);
    Route::post('/trips/{id}/accept', [TripsController::class, 'accept']);
    Route::post('/trips/{id}/decline', [TripsController::class, 'decline']);
    Route::post('/trips/{id}/arrived', [TripsController::class, 'arrived']);
    Route::post('/trips/{id}/start', [TripsController::class, 'start']);
    Route::post('/trips/{id}/complete', [TripsController::class, 'complete']);
    Route::post('/trips/{id}/cancel', [TripsController::class, 'cancelByDriver']);
    Route::post('/trips/{id}/start-stop', [TripsController::class, 'startStop']);
    Route::post('/trips/{id}/end-stop', [TripsController::class, 'endStop']);

    Route::post('/rides/{id}/bid', [BiddingController::class, 'submitBid']);
    Route::get('/rides/{id}/bids', [BiddingController::class, 'listBids']);
    Route::post('/rides/{id}/accept-bid/{bidId}', [BiddingController::class, 'acceptBid']);
    
    // Abonnement Chauffeur (Subscription)
    Route::post('/subscription/renew', [SubscriptionController::class, 'renew']);
});


Route::middleware(['auth:sanctum', 'role:passenger'])->prefix('passenger')->group(function () {
    Route::get('/ping', fn() => response()->json(['ok' => true, 'area' => 'passenger']));
    Route::get('/rides', [TripsController::class, 'passengerRides']);
    Route::get('/rides/current', [TripsController::class, 'currentPassengerRide']);
    Route::get('/rides/active-count', [TripsController::class, 'activeRidesCount']);
    Route::get('/drivers/nearby', [TripsController::class, 'nearbyDrivers']);
    Route::get('/rides/{id}', [TripsController::class, 'passengerRideShow']);
    Route::get('/rides/{id}/wait-assignment', [TripsController::class, 'passengerRideWaitAssignment']);
    Route::get('/addresses', [PassengerAddressController::class, 'index']);
    Route::post('/addresses', [PassengerAddressController::class, 'store']);
    Route::put('/addresses/{id}', [PassengerAddressController::class, 'update']);
    Route::delete('/addresses/{id}', [PassengerAddressController::class, 'destroy']);
    Route::post('/rides/{id}/cancel', [TripsController::class, 'cancelByPassenger']);
    Route::post('/rides/{id}/sos', [TripsController::class, 'sos']);
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/wallet/transactions/history', [WalletController::class, 'transactionsHistory']);
    Route::get('/wallet/transactions', [WalletController::class, 'todayTransactions']);
    Route::post('/wallet/topup', [WalletController::class, 'topup'])->middleware('throttle:20,1');
    Route::post('/wallet/topup/geniuspay', [TopupController::class, 'initiate'])->middleware('throttle:10,1');
    /** Alias neutre pour les clients (même handler que geniuspay). */
    Route::post('/wallet/topup/checkout', [TopupController::class, 'initiate'])->middleware('throttle:10,1');
    Route::get('/wallet/topup/{reference}/status', [TopupController::class, 'status']);
    Route::post('/rides/{id}/pay', [WalletController::class, 'payRide']);
    /** Paiement course (Mobile Money / carte / QR) via agrégateur GeniusPay — renvoie l’URL de checkout. */
    Route::post('/rides/{id}/checkout', [TopupController::class, 'initiateRideCheckout'])->middleware('throttle:10,1');
    Route::get('/rides/{id}/driver-location', [TripsController::class, 'passengerRideDriverLocation']);
    Route::post('/ratings', [RatingsController::class, 'store']);

    // Inbox notifications (liste app + compteur cloche)
    Route::get('/notifications/unread-count', [PassengerNotificationController::class, 'unreadCount']);
    Route::get('/notifications', [PassengerNotificationController::class, 'index']);
    Route::post('/notifications/read-all', [PassengerNotificationController::class, 'readAll']);
    Route::post('/notifications/dev-seed', [PassengerNotificationController::class, 'devSeed'])->middleware('throttle:30,1');
    Route::post('/notifications/dev-create', [PassengerNotificationController::class, 'devCreate'])->middleware('throttle:30,1');
    Route::patch('/notifications/{id}', [PassengerNotificationController::class, 'updateInbox'])->middleware('throttle:60,1');
    Route::delete('/notifications/{id}', [PassengerNotificationController::class, 'destroyInbox'])->middleware('throttle:60,1');
    Route::post('/notifications/{id}/read', [PassengerNotificationController::class, 'markRead']);

    // Promo Validation
    Route::get('/promo/validate', [\App\Http\Controllers\PromoController::class, 'validateCode']);

    // Bidding / Négociation : soumettre, lister et accepter des offres
    Route::post('/rides/{id}/bid', [BiddingController::class, 'submitBid']);
    Route::get('/rides/{id}/bids', [BiddingController::class, 'listBids']);
    Route::post('/rides/{id}/accept-bid/{bidId}', [BiddingController::class, 'acceptBid']);
});

Route::middleware(['auth:sanctum'])->prefix('trips')->group(function () {
    Route::post('/estimate', [TripsController::class, 'estimate']);
    Route::post('/create', [TripsController::class, 'create'])->middleware('throttle:30,1');

});

// Analytics endpoint pour les apps mobiles
Route::middleware(['auth:sanctum'])->prefix('analytics')->group(function () {
    Route::post('/reconnection', [\App\Http\Controllers\Admin\AnalyticsController::class, 'trackReconnection']);
    Route::post('/event', [\App\Http\Controllers\Admin\AnalyticsController::class, 'trackEvent']);
    Route::post('/log', [MobileLogController::class, 'store']);
});

// GeniusPay webhook & redirects (public, no auth)
Route::post('/topup/webhook', [TopupController::class, 'webhook']);
Route::get('/topup/success', [TopupController::class, 'success']);
Route::get('/topup/error', [TopupController::class, 'error']);

/** Retours navigateur après checkout GeniusPay (course) — JSON pour WebView / in-app browser. */
Route::get('/ride-payment/success', function () {
    return response()->json([
        'ok' => true,
        'message' => 'Si le paiement a réussi, vous pouvez fermer cette page et retourner dans l’application.',
        'ride_id' => request()->query('ride_id'),
    ]);
});
Route::get('/ride-payment/cancel', function () {
    return response()->json([
        'ok' => false,
        'message' => 'Paiement annulé ou interrompu.',
        'ride_id' => request()->query('ride_id'),
    ]);
});

// Public geocoding proxy (throttled)
Route::prefix('geocoding')->middleware('throttle:300,1')->group(function () {
    Route::get('/search', [GeocodingController::class, 'search']);
    Route::get('/reverse', [GeocodingController::class, 'reverse']);
});

// Public voice search (throttled)
Route::prefix('voice')->middleware('throttle:60,1')->group(function () {
    Route::post('/search', [VoiceController::class, 'search']);
});

// Public routing estimate (throttled)
Route::prefix('routing')->middleware('throttle:300,1')->group(function () {
    Route::post('/estimate', [TripsController::class, 'estimateFromCoords']);
});

