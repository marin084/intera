<?php

namespace App\Providers;

use App\Events\MessageCreated;
use App\Listeners\DeliverOutboundChannelMessage;
use App\Models\WebchatSession;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 0. Outbound Message Delivery Listener (WhatsApp, Omnichannel)
        Event::listen(MessageCreated::class, DeliverOutboundChannelMessage::class);

        // 1. PostgreSQL Connection Hardening: Statement and Lock timeouts
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            if ($event->connection->getDriverName() === 'pgsql') {
                $statementTimeout = (int) config('database.connections.pgsql.statement_timeout', 30000);
                $lockTimeout = (int) config('database.connections.pgsql.lock_timeout', 10000);

                if ($statementTimeout > 0) {
                    $event->connection->unprepared("SET statement_timeout = {$statementTimeout};");
                }
                if ($lockTimeout > 0) {
                    $event->connection->unprepared("SET lock_timeout = {$lockTimeout};");
                }
            }
        });

        // 2. High-Concurrency & Peak-Traffic Calibrated Rate Limiters
        RateLimiter::for('webchat-embed', function (Request $request) {
            $channelKey = (string) $request->route('publicKey');
            $ip = (string) $request->ip();
            $limit = (int) config('webchat.rate_limit_embed', 120);

            return Limit::perMinute($limit)->by("embed:{$channelKey}:{$ip}")->response(function () {
                return response()->json(['message' => 'Too Many Requests. Límite de solicitudes de integración excedido.'], 429);
            });
        });

        RateLimiter::for('webchat-session', function (Request $request) {
            $channelKey = (string) $request->route('publicKey');
            $visitorToken = (string) ($request->input('visitor_token') ?? $request->header('X-Visitor-Token') ?? '');
            $ip = (string) $request->ip();
            $limit = (int) config('webchat.rate_limit_session', 120);

            $key = $visitorToken !== ''
                ? "session:{$channelKey}:token:".hash('sha256', $visitorToken).":{$ip}"
                : "session:{$channelKey}:ip:{$ip}";

            return Limit::perMinute($limit)->by($key)->response(function () {
                return response()->json(['message' => 'Too Many Requests. Límite de creación o reanudación de sesiones excedido.'], 429);
            });
        });

        RateLimiter::for('webchat-messages', function (Request $request) {
            /** @var WebchatSession|null $session */
            $session = $request->attributes->get('webchat_session');
            $visitorToken = (string) ($request->header('X-Visitor-Token') ?? '');
            $sessionKey = $session?->id ?? ($visitorToken !== '' ? hash('sha256', $visitorToken) : 'anonymous');
            $ip = (string) $request->ip();
            $limit = (int) config('webchat.rate_limit_messages', 120);

            return Limit::perMinute($limit)->by("message:{$sessionKey}:{$ip}")->response(function () {
                return response()->json(['message' => 'Too Many Requests. Límite de envío de mensajes excedido.'], 429);
            });
        });

        RateLimiter::for('webchat-conversation-start', function (Request $request) {
            /** @var WebchatSession|null $session */
            $session = $request->attributes->get('webchat_session');
            $visitorToken = (string) ($request->header('X-Visitor-Token') ?? '');
            $sessionKey = $session?->id ?? ($visitorToken !== '' ? hash('sha256', $visitorToken) : 'anonymous');
            $ip = (string) $request->ip();
            $limit = (int) config('webchat.rate_limit_messages', 120);

            return Limit::perMinute($limit)->by("conv-start:{$sessionKey}:{$ip}")->response(function () {
                return response()->json(['message' => 'Too Many Requests. Límite de inicio de conversaciones excedido.'], 429);
            });
        });

        // 3. WhatsApp Cloud API Webhook High-Throughput Rate Limiter
        RateLimiter::for('whatsapp-webhook', function (Request $request) {
            $routeChannel = $request->route('channel');
            $channelId = is_object($routeChannel) && isset($routeChannel->id) ? (string) $routeChannel->id : (string) $routeChannel;
            $limit = (int) config('whatsapp.rate_limit_webhook', 1200);

            return Limit::perMinute($limit)->by("whatsapp-webhook:{$channelId}")->response(function () {
                return response()->json(['message' => 'Too Many Requests.'], 429);
            });
        });

        // 4. Facebook Messenger Webhook Rate Limiter
        RateLimiter::for('messenger-webhook', function (Request $request) {
            $routeChannel = $request->route('channel');
            $channelId = is_object($routeChannel) && isset($routeChannel->id) ? (string) $routeChannel->id : (string) $routeChannel;
            $limit = (int) config('messenger.rate_limit_webhook', 1200);

            return Limit::perMinute($limit)->by("messenger-webhook:{$channelId}")->response(function () {
                return response()->json(['message' => 'Too Many Requests.'], 429);
            });
        });

        // 5. Instagram Direct Webhook Rate Limiter
        RateLimiter::for('instagram-webhook', function (Request $request) {
            $routeChannel = $request->route('channel');
            $channelId = is_object($routeChannel) && isset($routeChannel->id) ? (string) $routeChannel->id : (string) $routeChannel;
            $limit = (int) config('instagram.rate_limit_webhook', 1200);

            return Limit::perMinute($limit)->by("instagram-webhook:{$channelId}")->response(function () {
                return response()->json(['message' => 'Too Many Requests.'], 429);
            });
        });

        // 6. Telegram Bot Webhook Rate Limiter
        RateLimiter::for('telegram-webhook', function (Request $request) {
            $routeChannel = $request->route('channel');
            $channelId = is_object($routeChannel) && isset($routeChannel->id) ? (string) $routeChannel->id : (string) $routeChannel;
            $limit = (int) config('telegram.rate_limit_webhook', 1200);

            return Limit::perMinute($limit)->by("telegram-webhook:{$channelId}")->response(function () {
                return response()->json(['message' => 'Too Many Requests.'], 429);
            });
        });
    }
}
