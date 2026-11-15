<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MonitorWhatsAppOutboxCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_one_privacy_safe_alert_for_a_stuck_whatsapp_outbox_message(): void
    {
        Cache::flush();
        Log::spy();

        $message = $this->createMessage(MessageStatus::PENDING, [
            'delivery_status' => 'pending',
        ]);
        $message->created_at = now()->subMinutes(5);
        $message->saveQuietly();

        $this->artisan('whatsapp:monitor-outbox')
            ->expectsOutput('Mensajes pendientes atascados detectados: 1; alertas nuevas: 1.')
            ->assertExitCode(0);
        $this->artisan('whatsapp:monitor-outbox')
            ->expectsOutput('Mensajes pendientes atascados detectados: 1; alertas nuevas: 0.')
            ->assertExitCode(0);

        Log::shouldHaveReceived('critical')->once()->withArgs(function (string $event, array $context) use ($message): bool {
            return $event === 'whatsapp.outbox.message_stuck'
                && $context['message_id'] === $message->id
                && $context['conversation_id'] === $message->conversation_id
                && $context['threshold_seconds'] >= 60
                && ! array_key_exists('content', $context)
                && ! array_key_exists('phone', $context);
        });
    }

    public function test_does_not_alert_for_fresh_or_failed_messages(): void
    {
        Log::spy();

        $this->createMessage(MessageStatus::PENDING, ['delivery_status' => 'pending']);

        $failed = $this->createMessage(MessageStatus::FAILED, [
            'delivery_status' => 'failed',
            'delivery_error_code' => 'META_API_ERROR',
        ]);
        $failed->created_at = now()->subMinutes(5);
        $failed->saveQuietly();

        $this->artisan('whatsapp:monitor-outbox')
            ->expectsOutput('Mensajes pendientes atascados detectados: 0; alertas nuevas: 0.')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('critical');
    }

    private function createMessage(MessageStatus $status, array $metadata): Message
    {
        $channel = Channel::create([
            'name' => 'WhatsApp Outbox Monitor',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'configuration' => [],
        ]);
        $contact = Contact::create([
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'phone' => '+50670000000',
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        return Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'status' => $status,
            'content' => 'sensitive sample content',
            'metadata' => $metadata,
            'sent_at' => now(),
        ]);
    }
}
