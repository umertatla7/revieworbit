<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutomationController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\GenericEventController;
use App\Http\Controllers\Api\V1\IntegrationKeyController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MessagingConfigurationController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\PlatformBusinessController;
use App\Http\Controllers\Api\V1\PlatformPlanController;
use App\Http\Controllers\Api\V1\PlatformToastController;
use App\Http\Controllers\Api\V1\PlatformTwilioController;
use App\Http\Controllers\Api\V1\PosIntegrationController;
use App\Http\Controllers\Api\V1\ReviewLinkActivityController;
use App\Http\Controllers\Api\V1\SquareIntegrationController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\ToastIntegrationController;
use App\Http\Controllers\Api\V1\ToastWebhookController;
use App\Http\Controllers\Api\V1\TwilioWebhookController;
use App\Http\Controllers\Api\V1\VisitController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

Route::get('/health/live', fn (): JsonResponse => response()->json([
    'status' => 'ok',
    'service' => 'revieworbit-api',
]));

Route::get('/health/ready', function (): JsonResponse {
    DB::select('select 1');
    Redis::connection()->ping();

    return response()->json([
        'status' => 'ready',
        'checks' => ['database' => 'ok', 'redis' => 'ok'],
    ]);
});

Route::prefix('api/v1')->group(function (): void {
    Route::get('/status', fn (): JsonResponse => response()->json([
        'data' => [
            'name' => 'ReviewOrbit API',
            'version' => 'v1',
        ],
    ]));

    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/auth/mobile/login', [AuthController::class, 'mobileLogin'])->middleware('throttle:5,1');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
    Route::post('/integrations/generic/events', [GenericEventController::class, 'ingest'])->middleware(['integration.key', 'throttle:120,1']);
    Route::post('/webhooks/generic', [GenericEventController::class, 'webhook'])->middleware('throttle:120,1');
    Route::post('/webhooks/twilio/status', [TwilioWebhookController::class, 'status'])->middleware('throttle:600,1');
    Route::post('/webhooks/twilio/inbound', [TwilioWebhookController::class, 'inbound'])->middleware('throttle:300,1');
    Route::post('/webhooks/toast/{environment}/{category}', [ToastWebhookController::class, 'ingest'])->middleware('throttle:600,1');
    Route::get('/integrations/square/callback', [SquareIntegrationController::class, 'callback'])->middleware('throttle:30,1');
    Route::get('/m/{token}', [MediaController::class, 'serve'])->middleware('throttle:120,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/mobile/logout', [AuthController::class, 'mobileLogout']);
        Route::post('/invitations/{token}/accept', [BusinessController::class, 'acceptInvitation'])->middleware('throttle:10,1');

        Route::prefix('admin')->middleware('platform.role:super_admin,platform_manager')->group(function (): void {
            Route::get('/businesses', [PlatformBusinessController::class, 'index']);
            Route::post('/businesses', [PlatformBusinessController::class, 'store']);
            Route::get('/businesses/{business}', [PlatformBusinessController::class, 'show']);
            Route::patch('/businesses/{business}', [PlatformBusinessController::class, 'update']);
            Route::post('/businesses/{business}/support-sessions', [PlatformBusinessController::class, 'startSupportSession']);
            Route::get('/plans', [PlatformPlanController::class, 'index']);
            Route::get('/usage', [PlatformPlanController::class, 'usage']);
        });

        Route::patch('/admin/plans/{plan}', [PlatformPlanController::class, 'update'])->middleware('platform.role:super_admin');

        Route::prefix('admin/twilio')->middleware('platform.role:super_admin')->group(function (): void {
            Route::get('/', [PlatformTwilioController::class, 'show']);
            Route::put('/', [PlatformTwilioController::class, 'update']);
            Route::post('/verify', [PlatformTwilioController::class, 'verify'])->middleware('throttle:10,1');
        });

        Route::prefix('admin/toast')->middleware('platform.role:super_admin')->group(function (): void {
            Route::get('/', [PlatformToastController::class, 'show']);
            Route::put('/', [PlatformToastController::class, 'update']);
            Route::post('/verify', [PlatformToastController::class, 'verify'])->middleware('throttle:10,1');
        });

        Route::middleware('business')->group(function (): void {
            Route::get('/business', [BusinessController::class, 'show']);
            Route::get('/onboarding', [OnboardingController::class, 'show']);
            Route::get('/pos-integrations', [PosIntegrationController::class, 'index']);
            Route::get('/toast/connections', [ToastIntegrationController::class, 'index']);
            Route::get('/square/appointments', [SquareIntegrationController::class, 'appointments']);
            Route::get('/messaging-configuration', [MessagingConfigurationController::class, 'show']);
            Route::get('/message-deliveries', [MessagingConfigurationController::class, 'deliveries']);
            Route::middleware('business.role:owner,manager')->group(function (): void {
                Route::patch('/business', [BusinessController::class, 'update']);
                Route::patch('/onboarding', [OnboardingController::class, 'update']);
                Route::post('/onboarding/complete', [OnboardingController::class, 'complete']);
                Route::post('/locations', [BusinessController::class, 'storeLocation']);
                Route::patch('/locations/{location}', [BusinessController::class, 'updateLocation']);
                Route::post('/invitations', [BusinessController::class, 'invite'])->middleware('business.role:owner');

                Route::post('/customers/import', [CustomerController::class, 'import']);
                Route::post('/customers/import-csv', [CustomerController::class, 'importCsv']);
                Route::post('/customers/{customer}/consents', [CustomerController::class, 'consent']);
                Route::post('/customers/{customer}/suppressions', [CustomerController::class, 'suppress']);
                Route::delete('/customers/{customer}/suppressions', [CustomerController::class, 'releaseSuppression']);
                Route::patch('/customers/{customer}/review-status', [CustomerController::class, 'reviewStatus']);
                Route::post('/customers', [CustomerController::class, 'store']);
                Route::patch('/customers/{customer}', [CustomerController::class, 'update']);

                Route::post('/templates/preview', [TemplateController::class, 'preview']);
                Route::post('/templates', [TemplateController::class, 'store']);
                Route::patch('/templates/{template}', [TemplateController::class, 'update']);
                Route::delete('/templates/{template}', [TemplateController::class, 'destroy']);
                Route::post('/templates/{template}/duplicate', [TemplateController::class, 'duplicate']);
                Route::post('/templates/{template}/test', [TemplateController::class, 'sendTest'])->middleware('throttle:5,1');
                Route::post('/templates/{template}/media', [TemplateController::class, 'upload']);
                Route::post('/media-templates', [MediaController::class, 'store']);
                Route::post('/media-templates/{mediaTemplate}/update', [MediaController::class, 'update']);
                Route::delete('/media-templates/{mediaTemplate}', [MediaController::class, 'destroy']);
                Route::post('/media-templates/{mediaTemplate}/generate', [MediaController::class, 'generate']);

                Route::post('/automations', [AutomationController::class, 'store']);
                Route::patch('/automations/{automation}', [AutomationController::class, 'update']);
                Route::post('/visits', [VisitController::class, 'store']);
                Route::post('/review-links/{reviewLink}/resend', [ReviewLinkActivityController::class, 'resend'])->middleware('throttle:10,1');
                Route::post('/pos-integrations', [PosIntegrationController::class, 'store']);
                Route::patch('/pos-integrations/{integration}', [PosIntegrationController::class, 'update']);
                Route::post('/pos-integrations/square/authorize', [SquareIntegrationController::class, 'authorize']);
                Route::post('/pos-integrations/{integration}/square/sync', [SquareIntegrationController::class, 'sync']);
                Route::delete('/pos-integrations/{integration}/square', [SquareIntegrationController::class, 'disconnect']);
                Route::post('/pos-integrations/toast/connect', [ToastIntegrationController::class, 'start']);
                Route::delete('/pos-integrations/toast/requests/{connectionRequest}', [ToastIntegrationController::class, 'cancel']);
                Route::post('/pos-integrations/toast/connections/{connection}/sync', [ToastIntegrationController::class, 'sync']);
                Route::put('/messaging-configuration', [MessagingConfigurationController::class, 'update']);
                Route::post('/messaging-configuration/verify', [MessagingConfigurationController::class, 'verify']);

                Route::get('/integration-keys', [IntegrationKeyController::class, 'index'])->middleware('business.role:owner');
                Route::post('/integration-keys', [IntegrationKeyController::class, 'store'])->middleware('business.role:owner');
                Route::delete('/integration-keys/{key}', [IntegrationKeyController::class, 'revoke'])->middleware('business.role:owner');
            });

            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
            Route::get('/templates', [TemplateController::class, 'index']);
            Route::get('/media/{media}/url', [TemplateController::class, 'mediaUrl']);
            Route::get('/media-templates', [MediaController::class, 'index']);
            Route::get('/generated-media/{generatedMedia}', [MediaController::class, 'showGenerated']);
            Route::get('/automations', [AutomationController::class, 'index']);
            Route::get('/automation-history', [AutomationController::class, 'history']);
            Route::get('/visits', [VisitController::class, 'index']);
            Route::get('/review-links', [ReviewLinkActivityController::class, 'index']);
        });
    });
});
