<?php

use App\Http\Controllers\Api\V1\AdminSettingsController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CannedResponseController;
use App\Http\Controllers\Api\V1\ChannelController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\FlowAnalyticsController;
use App\Http\Controllers\Api\V1\FlowController;
use App\Http\Controllers\Api\V1\FlowSimulationController;
use App\Http\Controllers\Api\V1\PresenceController;
use App\Http\Controllers\Api\V1\Public\FacebookMessengerWebhookController;
use App\Http\Controllers\Api\V1\Public\InstagramWebhookController;
use App\Http\Controllers\Api\V1\Public\TelegramWebhookController;
use App\Http\Controllers\Api\V1\Public\WebchatController;
use App\Http\Controllers\Api\V1\Public\WhatsAppWebhookController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SavedFunnelController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Global Route Patterns for UUID models
Route::pattern('conversation', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('channel', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('flow', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('version', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('cannedResponse', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('contact', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('execution', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

// Health Checks
Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);
Route::get('/health/realtime', [HealthController::class, 'realtime']);

// API V1 Routes
Route::prefix('v1')->group(function () {
    // Public Auth
    Route::post('/auth/login', [AuthController::class, 'login']);

    // The Reverb app key is public by design. Never publish a configuration
    // that includes Laravel's private encryption key.
    Route::get('/public/realtime/config', static function () {
        if (\App\Support\RealtimeConfiguration::hasUnsafeAppKey()) {
            return response()->json(['message' => 'Realtime configuration unavailable.'], 503)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'data' => [
                'app_key' => config('reverb.apps.apps.0.key'),
            ],
        ])->header('Cache-Control', 'no-store');
    });

    // Public Webhooks API (WhatsApp Cloud API / Meta Graph API)
    Route::prefix('public/webhooks/whatsapp')->group(function () {
        Route::get('/{channel}', [WhatsAppWebhookController::class, 'verify']);
        Route::post('/{channel}', [WhatsAppWebhookController::class, 'handle'])->middleware('throttle:whatsapp-webhook');
    });

    // Public Webhooks API (Facebook Messenger)
    Route::prefix('public/webhooks/messenger')->group(function () {
        Route::get('/{channel}', [FacebookMessengerWebhookController::class, 'verify']);
        Route::post('/{channel}', [FacebookMessengerWebhookController::class, 'handle'])->middleware('throttle:messenger-webhook');
    });

    // Public Webhooks API (Instagram Direct)
    Route::prefix('public/webhooks/instagram')->group(function () {
        Route::get('/{channel}', [InstagramWebhookController::class, 'verify']);
        Route::post('/{channel}', [InstagramWebhookController::class, 'handle'])->middleware('throttle:instagram-webhook');
    });

    // Public Webhooks API (Telegram Bot)
    Route::prefix('public/webhooks/telegram')->group(function () {
        Route::post('/{channel}', [TelegramWebhookController::class, 'handle'])->middleware('throttle:telegram-webhook');
    });

    // Public Webchat API
    Route::prefix('public/webchat')->group(function () {
        Route::match(['post', 'options'], '/{publicKey}/embed', [WebchatController::class, 'embed'])->middleware('throttle:webchat-embed');
        Route::match(['post', 'options'], '/{publicKey}/sessions', [WebchatController::class, 'createOrResumeSession'])->middleware('throttle:webchat-session');

        Route::middleware('webchat.visitor')->group(function () {
            Route::get('/session', [WebchatController::class, 'session']);
            Route::post('/conversations/start', [WebchatController::class, 'startConversation'])->middleware('throttle:webchat-conversation-start');
            Route::get('/conversations/current', [WebchatController::class, 'currentConversation']);
            Route::get('/messages', [WebchatController::class, 'messages']);
            Route::post('/messages', [WebchatController::class, 'sendMessage'])->middleware('throttle:webchat-messages');
            Route::post('/broadcast/auth', [WebchatController::class, 'broadcastAuth']);
        });
    });

    // Broadcasting Auth for Authenticated Users (Admin / Operator / Agent)
    Broadcast::routes(['middleware' => ['auth:sanctum', EnsureUserIsActive::class]]);

    // Authenticated Routes
    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        // Auth session
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/password', [AuthController::class, 'changePassword']);
        Route::post('/presence/heartbeat', [PresenceController::class, 'heartbeat']);

        // Administrator-only system settings
        Route::get('/admin/settings/discord-webhook', [AdminSettingsController::class, 'show']);
        Route::put('/admin/settings/discord-webhook', [AdminSettingsController::class, 'updateDiscordWebhook']);
        Route::delete('/admin/settings/discord-webhook', [AdminSettingsController::class, 'clearDiscordWebhook']);
        Route::post('/admin/settings/discord-webhook/test', [AdminSettingsController::class, 'testDiscordWebhook'])->middleware('throttle:5,1');

        // Users
        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view,users.manage,agents.view,conversations.assign');
        Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.manage');
        Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view,users.manage');
        Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.manage');
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.manage');
        Route::put('/users/{user}/channels', [UserController::class, 'updateChannels'])->middleware('permission:users.manage');
        Route::put('/users/{user}/roles', [UserController::class, 'updateRoles'])->middleware('permission:roles.manage,users.manage');

        // Teams and routing queues
        Route::get('/teams', [TeamController::class, 'index'])->middleware('permission:teams.view,teams.manage');
        Route::post('/teams', [TeamController::class, 'store'])->middleware('permission:teams.manage');
        Route::put('/teams/{team}', [TeamController::class, 'update'])->middleware('permission:teams.manage');

        // Roles & Permissions
        Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view,roles.manage');
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage');
        Route::get('/permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view,roles.manage');

        // Channels
        Route::get('/channels/access-options', [ChannelController::class, 'accessOptions'])->middleware('permission:users.manage');
        Route::get('/channels', [ChannelController::class, 'index'])->middleware('permission:channels.view,channels.manage,conversations.view_assigned,conversations.view_all');
        Route::post('/channels', [ChannelController::class, 'store'])->middleware('permission:channels.manage');
        Route::get('/channels/{channel}', [ChannelController::class, 'show'])->middleware('permission:channels.view,channels.manage');
        Route::get('/channels/{channel}/whatsapp-templates', [ChannelController::class, 'whatsappTemplates'])->middleware('permission:conversations.reply,conversations.view_assigned,conversations.view,conversations.manage,channels.view,channels.manage');
        Route::post('/channels/{channel}/telegram/setup-webhook', [ChannelController::class, 'setupTelegramWebhook'])->middleware('permission:channels.manage');
        Route::get('/channels/{channel}/telegram/webhook-info', [ChannelController::class, 'getTelegramWebhookInfo'])->middleware('permission:channels.view,channels.manage');
        Route::put('/channels/{channel}', [ChannelController::class, 'update'])->middleware('permission:channels.manage');
        Route::patch('/channels/{channel}/status', [ChannelController::class, 'updateStatus'])->middleware('permission:channels.manage');
        Route::post('/channels/{channel}/webchat/regenerate-key', [ChannelController::class, 'regeneratePublicKey'])->middleware('permission:channels.manage');
        Route::delete('/channels/{channel}', [ChannelController::class, 'destroy'])->middleware('permission:channels.manage');

        // Flows
        Route::get('/flows', [FlowController::class, 'index'])->middleware('permission:flows.view');
        Route::post('/flows', [FlowController::class, 'store'])->middleware('permission:flows.create');
        Route::get('/flows/{flow}', [FlowController::class, 'show'])->middleware('permission:flows.view');
        Route::get('/flows/{flow}/analytics', [FlowAnalyticsController::class, 'show'])->middleware('permission:flows.view');
        Route::put('/flows/{flow}', [FlowController::class, 'update'])->middleware('permission:flows.edit');
        Route::delete('/flows/{flow}', [FlowController::class, 'destroy'])->middleware('permission:flows.delete');
        Route::post('/flows/{flow}/attachments', [FlowController::class, 'uploadAttachment'])->middleware('permission:flows.edit,flows.create');
        Route::get('/flows/{flow}/versions', [FlowController::class, 'listVersions'])->middleware('permission:flows.view');
        Route::get('/flows/{flow}/versions/{version}', [FlowController::class, 'showVersion'])->middleware('permission:flows.view');
        Route::post('/flows/{flow}/versions', [FlowController::class, 'createVersion'])->middleware('permission:flows.edit');
        Route::delete('/flows/{flow}/versions/{version}', [FlowController::class, 'destroyVersion'])->middleware('permission:flows.delete');
        Route::put('/flows/{flow}/versions/{version}/graph', [FlowController::class, 'saveVersionGraph'])->middleware('permission:flows.edit');
        Route::post('/flows/{flow}/versions/{version}/validate', [FlowController::class, 'validateVersion'])->middleware('permission:flows.view');
        Route::post('/flows/{flow}/versions/{version}/publish', [FlowController::class, 'publishVersion'])->middleware('permission:flows.publish');
        Route::post('/flows/{flow}/versions/{version}/rollback', [FlowController::class, 'rollbackVersion'])->middleware('permission:flows.publish');
        Route::get('/flows/{flow}/versions/{version}/compare/{targetVersion}', [FlowController::class, 'compareVersions'])->middleware('permission:flows.view');
        Route::post('/flows/{flow}/versions/{version}/simulation/start', [FlowSimulationController::class, 'start'])->middleware('permission:flows.view');
        Route::post('/flows/{flow}/versions/{version}/simulation/step', [FlowSimulationController::class, 'step'])->middleware('permission:flows.view');
        Route::post('/flows/{flow}/versions/{version}/simulation/reset', [FlowSimulationController::class, 'reset'])->middleware('permission:flows.view');
        Route::post('/flows/{flow}/versions/{version}/simulation/stop', [FlowSimulationController::class, 'stop'])->middleware('permission:flows.view');
        Route::get('/flows/{flow}/versions/{version}/simulation/{execution}', [FlowSimulationController::class, 'show'])->middleware('permission:flows.view');

        // Dashboards & Analytics
        Route::prefix('analytics')->group(function () {
            Route::get('/funnels', [AnalyticsController::class, 'funnels'])->middleware('permission:flows.view,conversations.view_all');
            Route::get('/funnels/contacts', [AnalyticsController::class, 'funnelContacts'])->middleware('permission:flows.view,conversations.view_all');
            Route::get('/funnels/step-contacts', [AnalyticsController::class, 'stepContacts'])->middleware('permission:flows.view,conversations.view_all');
            Route::get('/funnels/saved', [SavedFunnelController::class, 'index'])->middleware('permission:flows.view,conversations.view_all');
            Route::post('/funnels/saved', [SavedFunnelController::class, 'store'])->middleware('permission:flows.view,conversations.view_all');
            Route::put('/funnels/saved/{id}', [SavedFunnelController::class, 'update'])->middleware('permission:flows.view,conversations.view_all');
            Route::delete('/funnels/saved/{id}', [SavedFunnelController::class, 'destroy'])->middleware('permission:flows.view,conversations.view_all');
            Route::get('/users-and-sessions', [AnalyticsController::class, 'usersAndSessions'])->middleware('permission:flows.view,conversations.view_all');
            Route::get('/users-and-sessions/export', [AnalyticsController::class, 'exportUsersAndSessions'])->middleware('permission:flows.view,conversations.view_all');
            Route::get('/realtime', [AnalyticsController::class, 'realtime'])->middleware('permission:flows.view,conversations.view_all');
            Route::get('/agents', [AnalyticsController::class, 'agents'])->middleware('permission:conversations.view_assigned,conversations.view_all');
            Route::get('/agents/export-summary', [AnalyticsController::class, 'exportAgentsSummary'])->middleware('permission:conversations.view_assigned,conversations.view_all');
            Route::get('/agents/export-conversations', [AnalyticsController::class, 'exportConversationsDetail'])->middleware('permission:conversations.view_assigned,conversations.view_all');
        });

        // Conversations & Inbox
        Route::get('/conversations', [ConversationController::class, 'index'])->middleware('permission:conversations.view_assigned,conversations.view_all');
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->middleware('permission:conversations.view_assigned,conversations.view_all');
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages'])->middleware('permission:conversations.view_assigned,conversations.view_all');
        Route::post('/conversations/{conversation}/claim', [ConversationController::class, 'claim'])->middleware('permission:conversations.view_assigned,conversations.reply');
        Route::post('/conversations/{conversation}/assign', [ConversationController::class, 'assign'])->middleware('permission:conversations.assign');
        Route::patch('/conversations/{conversation}', [ConversationController::class, 'update'])->middleware('permission:conversations.assign');
        Route::post('/conversations/{conversation}/close', [ConversationController::class, 'close'])->middleware('permission:conversations.view_assigned,conversations.view_all');
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markAsRead'])->middleware('permission:conversations.view_assigned,conversations.view_all');
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'sendMessage'])->middleware('permission:conversations.reply');
        Route::get('/conversations/{conversation}/execution', [ConversationController::class, 'execution'])->middleware('permission:conversations.view_assigned,conversations.view_all');
        Route::post('/conversations/{conversation}/toggle-bot', [ConversationController::class, 'toggleBot'])->middleware('permission:conversations.reply,conversations.assign');
        Route::post('/conversations/{conversation}/restart-flow', [ConversationController::class, 'restartFlow'])->middleware('permission:conversations.reply,conversations.assign');
        Route::patch('/conversations/{conversation}/contact', [ConversationController::class, 'updateContact'])->middleware('permission:conversations.view_assigned,conversations.reply');
        Route::post('/conversations/{conversation}/transfer', [ConversationController::class, 'transfer'])->middleware('permission:conversations.view_assigned,conversations.reply,conversations.assign');
        Route::post('/conversations/{conversation}/tags', [ConversationController::class, 'syncTags'])->middleware('permission:conversations.view_assigned,conversations.reply');
        Route::delete('/conversations/{conversation}/tags/{tag}', [ConversationController::class, 'removeTag'])->middleware('permission:conversations.view_assigned,conversations.reply');
        Route::patch('/contacts/{contact}', [ContactController::class, 'update'])->middleware('permission:conversations.view_assigned,conversations.reply');

        // Tags
        Route::get('/tags', [TagController::class, 'index'])->middleware('permission:conversations.view_assigned,conversations.view_all');
        Route::post('/tags', [TagController::class, 'store'])->middleware('permission:conversations.assign');
        Route::put('/tags/{tag}', [TagController::class, 'update'])->whereUuid('tag')->middleware('permission:conversations.assign');
        Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->whereUuid('tag')->middleware('permission:conversations.assign');

        // Canned Responses
        Route::get('/canned-responses', [CannedResponseController::class, 'index'])->middleware('permission:canned_responses.view,canned_responses.manage,conversations.reply');
        Route::post('/canned-responses', [CannedResponseController::class, 'store'])->middleware('permission:canned_responses.manage');
        Route::get('/canned-responses/{cannedResponse}', [CannedResponseController::class, 'show'])->middleware('permission:canned_responses.view,canned_responses.manage');
        Route::put('/canned-responses/{cannedResponse}', [CannedResponseController::class, 'update'])->middleware('permission:canned_responses.manage');
        Route::patch('/canned-responses/{cannedResponse}', [CannedResponseController::class, 'update'])->middleware('permission:canned_responses.manage');
        Route::delete('/canned-responses/{cannedResponse}', [CannedResponseController::class, 'destroy'])->middleware('permission:canned_responses.manage');

        // Audit Logs
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit_logs.view');
    });
});
