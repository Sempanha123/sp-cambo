<?php

use App\Http\Controllers\Api\V1\AccountSecurityController;
use App\Http\Controllers\Api\V1\Admin\AccessController as AdminAccessController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\ModelAliasController as AdminModelAliasController;
use App\Http\Controllers\Api\V1\Admin\ModelPricingController as AdminModelPricingController;
use App\Http\Controllers\Api\V1\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Api\V1\Admin\OperationsController as AdminOperationsController;
use App\Http\Controllers\Api\V1\Admin\PackageController as AdminPackageController;
use App\Http\Controllers\Api\V1\Admin\PlaygroundSettingController as AdminPlaygroundSettingController;
use App\Http\Controllers\Api\V1\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Api\V1\Admin\ProviderAliasController;
use App\Http\Controllers\Api\V1\Admin\ProviderConnectionRevisionController;
use App\Http\Controllers\Api\V1\Admin\ProviderController;
use App\Http\Controllers\Api\V1\Admin\ProviderModelController;
use App\Http\Controllers\Api\V1\Admin\RedeemCodeController as AdminRedeemCodeController;
use App\Http\Controllers\Api\V1\Admin\SystemHealthController as AdminSystemHealthController;
use App\Http\Controllers\Api\V1\Admin\TelegramStoreController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Catalog\ModelCatalogController;
use App\Http\Controllers\Api\V1\Catalog\PackageCatalogController;
use App\Http\Controllers\Api\V1\EntitlementController;
use App\Http\Controllers\Api\V1\FulfillmentClaimController;
use App\Http\Controllers\Api\V1\Internal\GatewayBillingController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PlaygroundController;
use App\Http\Controllers\Api\V1\PlaygroundChatController;
use App\Http\Controllers\Api\V1\PromotionPreviewController;
use App\Http\Controllers\Api\V1\RedeemCodeController;
use App\Http\Controllers\Api\V1\ResellerCustomerController;
use App\Http\Controllers\Api\V1\ResellerCustomerKeyController;
use App\Http\Controllers\Api\V1\ResellerManagementKeyController;
use App\Http\Controllers\Api\V1\StatusController;
use App\Http\Controllers\Api\V1\TelegramWebhookController;
use App\Http\Controllers\Api\V1\TelegramAccountController;
use App\Http\Controllers\Api\V1\UsageController;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    if (app()->environment('testing')) {
        Route::post('test-csrf-exception', static fn () => throw new TokenMismatchException('private csrf detail'));
    }

    Route::get('health', static fn (): JsonResponse => response()->json([
        'data' => [
            'status' => 'ok',
        ],
    ]));
    Route::get('status', StatusController::class)->middleware('throttle:120,1');
    Route::post('telegram/webhook', TelegramWebhookController::class)->middleware('throttle:120,1');
    Route::get('catalog/models', ModelCatalogController::class)->middleware('throttle:120,1');
    Route::get('catalog/packages', PackageCatalogController::class)->middleware('throttle:120,1');
    Route::post('keys/check', [ApiKeyController::class, 'check'])->middleware('throttle:10,1');

    Route::middleware(['gateway.auth', 'throttle:600,1'])->prefix('internal/gateway')->group(function (): void {
        Route::post('inspect', [GatewayBillingController::class, 'inspect']);
        Route::post('preflight', [GatewayBillingController::class, 'preflight']);
        Route::post('reservations/{reservation}/state', [GatewayBillingController::class, 'state']);
        Route::post('reservations/{reservation}/settle', [GatewayBillingController::class, 'settle']);
        Route::post('reservations/{reservation}/release', [GatewayBillingController::class, 'release']);
        Route::post('reservations/{reservation}/reconcile', [GatewayBillingController::class, 'reconcile']);
    });

    Route::middleware(['auth:sanctum', 'account.active', 'permission:catalog.manage'])->prefix('admin')->group(function (): void {
        Route::get('providers', [ProviderController::class, 'index']);
        Route::post('providers', [ProviderController::class, 'store']);
        Route::put('providers/{provider}', [ProviderController::class, 'update']);
        Route::delete('providers/{provider}', [ProviderController::class, 'destroy']);

        Route::get('providers/{provider}/connection-revisions', [ProviderConnectionRevisionController::class, 'index']);
        Route::post('providers/{provider}/connection-revisions', [ProviderConnectionRevisionController::class, 'store']);
        Route::put('providers/{provider}/connection-revisions/{revision}', [ProviderConnectionRevisionController::class, 'update']);
        Route::delete('providers/{provider}/connection-revisions/{revision}', [ProviderConnectionRevisionController::class, 'destroy']);
        Route::post('providers/{provider}/connection-revisions/{revision}/probe', [ProviderConnectionRevisionController::class, 'probe']);
        Route::patch('providers/{provider}/connection-revisions/{revision}/status', [ProviderConnectionRevisionController::class, 'updateStatus']);
        Route::put('providers/{provider}/active-connection-revision', [ProviderConnectionRevisionController::class, 'updateActive']);

        Route::get('providers/{provider}/models', [ProviderModelController::class, 'index']);
        Route::post('providers/{provider}/models', [ProviderModelController::class, 'store']);
        Route::post('providers/{provider}/models/discover', [ProviderModelController::class, 'discover']);
        Route::post('providers/{provider}/models/import', [ProviderModelController::class, 'import']);
        Route::put('providers/{provider}/models/{model}', [ProviderModelController::class, 'update']);
        Route::delete('providers/{provider}/models/{model}', [ProviderModelController::class, 'destroy']);

        Route::get('providers/{provider}/aliases', [ProviderAliasController::class, 'index']);
        Route::post('providers/{provider}/aliases', [ProviderAliasController::class, 'store']);
        Route::put('providers/{provider}/aliases/{alias}', [ProviderAliasController::class, 'update']);
        Route::delete('providers/{provider}/aliases/{alias}', [ProviderAliasController::class, 'destroy']);
        Route::post('providers/{provider}/aliases/{alias}/publish', [ProviderAliasController::class, 'publish']);
        Route::post('providers/{provider}/aliases/{alias}/map-model', [ProviderAliasController::class, 'mapModel']);
        Route::get('packages', [AdminPackageController::class, 'index']);
        Route::post('packages', [AdminPackageController::class, 'store']);
        Route::put('packages/{package}', [AdminPackageController::class, 'update']);
        Route::post('packages/{package}/stock', [AdminPackageController::class, 'addStock'])->middleware('throttle:30,1');
        Route::get('packages/{package}/profitability', [AdminPackageController::class, 'profitability']);
        Route::get('model-aliases', [AdminModelPricingController::class, 'index']);
        Route::put('model-aliases/{modelAlias}/pricing', [AdminModelPricingController::class, 'update']);
        Route::post('model-pricing/{modelAlias}/verify-upstream-cost', [AdminModelPricingController::class, 'verifyUpstreamCost']);
        // Model Alias CRUD
        Route::post('model-aliases', [AdminModelAliasController::class, 'store']);
        Route::get('model-aliases/{modelAlias}', [AdminModelAliasController::class, 'show']);
        Route::put('model-aliases/{modelAlias}', [AdminModelAliasController::class, 'update']);
        Route::delete('model-aliases/{modelAlias}', [AdminModelAliasController::class, 'destroy']);
        Route::get('promotions', [AdminPromotionController::class, 'index']);
        Route::post('promotions', [AdminPromotionController::class, 'store']);
        Route::put('promotions/{promotion}', [AdminPromotionController::class, 'update']);
        Route::get('playground-settings', [AdminPlaygroundSettingController::class, 'show']);
        Route::put('playground-settings', [AdminPlaygroundSettingController::class, 'update']);
        Route::get('redeem-codes', [AdminRedeemCodeController::class, 'index']);
        Route::post('redeem-codes', [AdminRedeemCodeController::class, 'store'])->middleware('throttle:20,1');
        Route::put('redeem-codes/{redeemCode}', [AdminRedeemCodeController::class, 'update']);
        Route::get('telegram-store', [TelegramStoreController::class, 'show']);
        Route::post('telegram-store/announcements', [TelegramStoreController::class, 'broadcast'])->middleware('throttle:10,1');
        Route::post('telegram-store/announcements/{announcement}/retry-failed', [TelegramStoreController::class, 'retryFailed'])->middleware('throttle:10,1');
        Route::post('telegram-store/purchase-alerts/{alert}/retry', [TelegramStoreController::class, 'retryPurchaseAlert'])->middleware('throttle:10,1');
        Route::post('operations/recover', [AdminOperationsController::class, 'recover'])->middleware('throttle:10,1');
        Route::post('operations/payments/{paymentAttempt}/verify', [AdminOperationsController::class, 'verifyPayment'])->middleware('throttle:10,1');
        Route::post('operations/telegram-purchases/{telegramPurchase}/retry', [AdminOperationsController::class, 'retryTelegramPurchase'])->middleware('throttle:10,1');
    });

    Route::middleware(['auth:sanctum', 'account.active', 'permission:access.manage'])->prefix('admin')->group(function (): void {
        Route::get('access/model-aliases', [AdminAccessController::class, 'modelAliases']);
        Route::patch('access/customers/{customer}/status', [AdminAccessController::class, 'updateCustomerStatus'])->middleware('throttle:20,1');
        Route::post('access/api-keys', [AdminAccessController::class, 'storeKey'])->middleware('throttle:10,1');
        Route::patch('access/api-keys/{apiKey}/status', [AdminAccessController::class, 'updateKeyStatus'])->middleware('throttle:20,1');
        Route::post('access/entitlements/{entitlementLot}/expire', [AdminAccessController::class, 'expireEntitlement'])->middleware('throttle:20,1');
        Route::post('operations/reservations/{reservation}/release-confirmed', [AdminOperationsController::class, 'releaseReconciliation'])->middleware('throttle:10,1');
    });

    Route::middleware(['auth:sanctum', 'account.active', 'permission:admin.view'])->prefix('admin')->group(function (): void {
        Route::get('overview', AdminOverviewController::class);
        Route::get('system-health', AdminSystemHealthController::class);
        Route::get('audit-logs', [AdminAuditLogController::class, 'index']);
        Route::get('operations', [AdminOperationsController::class, 'show']);
        Route::get('operations/reconciliation-reservations', [AdminOperationsController::class, 'reconciliationReservations']);
        Route::get('access/customers', [AdminAccessController::class, 'customers']);
        Route::get('access/api-keys', [AdminAccessController::class, 'keys']);
        Route::get('access/entitlements', [AdminAccessController::class, 'entitlements']);
        Route::get('access/usage', [AdminAccessController::class, 'usage']);
    });

    Route::middleware(['auth:sanctum', 'account.active', 'permission:reseller.manage'])->prefix('reseller')->group(function (): void {
        Route::get('customers', [ResellerCustomerController::class, 'index']);
        Route::post('customers', [ResellerCustomerController::class, 'store'])->middleware('throttle:10,1');
        Route::patch('customers/{resellerCustomer}/status', [ResellerCustomerController::class, 'updateStatus'])->middleware('throttle:20,1');
        Route::post('customers/{resellerCustomer}/allocations', [ResellerCustomerController::class, 'allocate'])->middleware('throttle:20,1');
        Route::get('customers/{resellerCustomer}/api-keys', [ResellerCustomerKeyController::class, 'index']);
        Route::post('customers/{resellerCustomer}/api-keys', [ResellerCustomerKeyController::class, 'store'])->middleware('throttle:10,1');
        Route::post('customers/{resellerCustomer}/api-keys/{apiKey}/revoke', [ResellerCustomerKeyController::class, 'revoke'])->middleware('throttle:10,1');
        Route::get('management-keys', [ResellerManagementKeyController::class, 'index']);
        Route::post('management-keys', [ResellerManagementKeyController::class, 'store'])->middleware('throttle:5,1');
        Route::post('management-keys/{managementKey}/revoke', [ResellerManagementKeyController::class, 'revoke'])->middleware('throttle:10,1');
    });

    Route::middleware(['management.auth', 'throttle:60,1'])->prefix('reseller-management')->group(function (): void {
        Route::get('customers', [ResellerCustomerController::class, 'index'])->middleware('management.scope:customers:read');
        Route::post('customers', [ResellerCustomerController::class, 'store'])->middleware('management.scope:customers:write');
        Route::patch('customers/{resellerCustomer}/status', [ResellerCustomerController::class, 'updateStatus'])->middleware('management.scope:customers:write');
        Route::post('customers/{resellerCustomer}/allocations', [ResellerCustomerController::class, 'allocate'])->middleware('management.scope:allocations:write');
        Route::get('customers/{resellerCustomer}/api-keys', [ResellerCustomerKeyController::class, 'index'])->middleware('management.scope:keys:read');
        Route::post('customers/{resellerCustomer}/api-keys', [ResellerCustomerKeyController::class, 'store'])->middleware('management.scope:keys:write');
        Route::post('customers/{resellerCustomer}/api-keys/{apiKey}/revoke', [ResellerCustomerKeyController::class, 'revoke'])->middleware('management.scope:keys:write');
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('register', RegisterController::class)->middleware('throttle:5,1');
        Route::post('login', LoginController::class);
        Route::post('logout', LogoutController::class)->middleware(['auth:sanctum', 'account.active']);
        Route::post('forgot-password', ForgotPasswordController::class)->middleware('throttle:5,1');
        Route::post('reset-password', ResetPasswordController::class)->middleware('throttle:5,1');
        Route::get('google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:10,1');
        Route::post('google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:10,1');
        Route::post('google/link', [GoogleAuthController::class, 'link'])->middleware(['auth:sanctum', 'account.active', 'throttle:10,1']);
    });

    Route::get('me', MeController::class)->middleware(['auth:sanctum', 'account.active']);
    Route::get('me/telegram', [TelegramAccountController::class, 'show'])->middleware(['auth:sanctum', 'account.active']);
    Route::post('me/telegram/link-token', [TelegramAccountController::class, 'token'])->middleware(['auth:sanctum', 'account.active', 'throttle:5,1']);
    Route::delete('me/telegram', [TelegramAccountController::class, 'destroy'])->middleware(['auth:sanctum', 'account.active']);
    Route::patch('me', [AccountSecurityController::class, 'updateProfile'])->middleware(['auth:sanctum', 'account.active']);
    Route::post('me/password', [AccountSecurityController::class, 'updatePassword'])->middleware(['auth:sanctum', 'account.active', 'throttle:5,1']);
    Route::get('me/sessions', [AccountSecurityController::class, 'sessions'])->middleware(['auth:sanctum', 'account.active']);
    Route::delete('me/sessions/{session}', [AccountSecurityController::class, 'revokeSession'])->middleware(['auth:sanctum', 'account.active']);
    Route::get('me/external-identities', [AccountSecurityController::class, 'identities'])->middleware(['auth:sanctum', 'account.active']);
    Route::delete('me/external-identities/{identity}', [AccountSecurityController::class, 'unlinkIdentity'])->middleware(['auth:sanctum', 'account.active']);
    Route::get('me/balance', [EntitlementController::class, 'balance'])->middleware(['auth:sanctum', 'account.active']);
    Route::get('me/entitlements', [EntitlementController::class, 'index'])->middleware(['auth:sanctum', 'account.active']);
    Route::get('me/activity', [UsageController::class, 'activity'])->middleware(['auth:sanctum', 'account.active']);
    Route::get('me/usage/summary', [UsageController::class, 'summary'])->middleware(['auth:sanctum', 'account.active']);
    Route::get('me/playground/quota', [PlaygroundController::class, 'quota'])->middleware(['auth:sanctum', 'account.active', 'throttle:60,1']);
    Route::post('me/playground/run', [PlaygroundController::class, 'run'])->middleware(['auth:sanctum', 'account.active', 'throttle:12,1']);
    Route::post('me/playground/stream', [PlaygroundController::class, 'stream'])->middleware(['auth:sanctum', 'account.active', 'throttle:12,1']);
    Route::get('me/playground/chats', [PlaygroundChatController::class, 'index'])->middleware(['auth:sanctum', 'account.active', 'throttle:60,1']);
    Route::post('me/playground/chats', [PlaygroundChatController::class, 'store'])->middleware(['auth:sanctum', 'account.active', 'throttle:30,1']);
    Route::delete('me/playground/chats', [PlaygroundChatController::class, 'clear'])->middleware(['auth:sanctum', 'account.active', 'throttle:10,1']);
    Route::get('me/playground/chats/{chat}', [PlaygroundChatController::class, 'show'])->middleware(['auth:sanctum', 'account.active', 'throttle:60,1']);
    Route::put('me/playground/chats/{chat}', [PlaygroundChatController::class, 'update'])->middleware(['auth:sanctum', 'account.active', 'throttle:60,1']);
    Route::delete('me/playground/chats/{chat}', [PlaygroundChatController::class, 'destroy'])->middleware(['auth:sanctum', 'account.active', 'throttle:30,1']);
    Route::get('me/usage/keys/{apiKey}/summary', [UsageController::class, 'keySummary'])->middleware(['auth:sanctum', 'account.active']);
    Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
        Route::get('orders', [OrderController::class, 'index']);
        Route::delete('orders/history', [OrderController::class, 'clearHistory'])->middleware('throttle:10,1');
        Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:20,1');
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::delete('orders/{order}', [OrderController::class, 'hide'])->middleware('throttle:20,1');
        Route::get('orders/{order}/payment', [PaymentController::class, 'show']);
        Route::post('orders/{order}/payment', [PaymentController::class, 'store'])->middleware('throttle:10,1');
        Route::post('orders/{order}/payment/auto-check', [PaymentController::class, 'autoCheck'])->middleware('throttle:120,1');
        Route::post('orders/{order}/payment/verify', [PaymentController::class, 'verify'])->middleware('throttle:20,1');
        Route::post('promotions/preview', PromotionPreviewController::class)->middleware('throttle:30,1');
        Route::post('redeem-codes/redeem', [RedeemCodeController::class, 'store'])->middleware('throttle:10,1');
    });
    Route::middleware(['auth:sanctum', 'account.active'])->prefix('me/api-keys')->group(function (): void {
        Route::get('/', [ApiKeyController::class, 'index']);
        Route::post('/', [ApiKeyController::class, 'store'])->middleware('throttle:10,1');
        Route::get('{apiKey}', [ApiKeyController::class, 'show']);
        Route::post('{apiKey}/reveal', [ApiKeyController::class, 'reveal'])->middleware('throttle:10,1');
        Route::post('{apiKey}/rotate', [ApiKeyController::class, 'rotate'])->middleware('throttle:5,1');
        Route::patch('{apiKey}/status', [ApiKeyController::class, 'updateStatus']);
        Route::post('{apiKey}/revoke', [ApiKeyController::class, 'revoke']);
        Route::get('{apiKey}/status', [ApiKeyController::class, 'status']);
    });
    Route::middleware(['auth:sanctum', 'account.active'])->prefix('me/api-key-claims')->group(function (): void {
        Route::get('/', [FulfillmentClaimController::class, 'index']);
        Route::post('{claim}/claim', [FulfillmentClaimController::class, 'claim'])->middleware('throttle:10,1');
    });
});
