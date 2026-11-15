<?php

namespace App\Services\Runtime\Channels;

use App\Enums\ChannelType;
use App\Events\MessageReactionUpdated;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies Messenger and Instagram Direct reaction webhook events to their target messages.
 * Reactions are metadata updates only: they must never enter the bot flow as new messages.
 */
class MetaMessageReactionProcessor
{
    /** @param array<string, mixed> $messaging */
    public function process(Channel $channel, array $messaging): void
    {
        $reaction = $messaging['reaction'] ?? $messaging['message_reaction'] ?? null;
        if (! is_array($reaction)) {
            return;
        }

        $action = strtolower(trim((string) ($reaction['action'] ?? '')));
        $targetMessageId = trim((string) ($reaction['mid'] ?? $reaction['message_id'] ?? ''));
        $senderId = trim((string) ($messaging['sender']['id'] ?? ''));
        $emoji = trim((string) ($reaction['emoji'] ?? ''));

        if (! in_array($channel->type, [ChannelType::FACEBOOK_MESSENGER, ChannelType::INSTAGRAM], true)
            || $targetMessageId === ''
            || ! in_array($action, ['react', 'unreact'], true)
            || ($action === 'react' && $emoji === '')) {
            Log::notice('[MetaMessageReactionProcessor] Evento de reacción incompleto o no soportado.', [
                'channel_id' => $channel->id,
                'platform' => $channel->type->value,
            ]);

            return;
        }

        $result = DB::transaction(function () use ($channel, $senderId, $targetMessageId, $action, $emoji): array {
            $target = Message::query()
                ->where(function (Builder $query) use ($targetMessageId) {
                    $query->where('external_message_id', $targetMessageId)
                        ->orWhere('metadata->meta_message_id', $targetMessageId);
                })
                ->whereHas('conversation', function (Builder $query) use ($channel, $senderId) {
                    $query->where('channel_id', $channel->id);

                    // Some Instagram Messaging webhook variants omit sender/recipient IDs
                    // on reaction events. The target message ID still scopes the update.
                    if ($senderId !== '') {
                        $query->whereHas('contact', fn (Builder $contactQuery) => $contactQuery
                            ->where('external_identifier', $senderId));
                    }
                })
                ->lockForUpdate()
                ->first();

            if (! $target) {
                return ['message' => null, 'not_found' => true];
            }

            $metadata = is_array($target->metadata) ? $target->metadata : [];
            $reactions = is_array($metadata['reactions'] ?? null) ? $metadata['reactions'] : [];
            $previousEmoji = $reactions['contact'] ?? null;

            if ($action === 'react') {
                if ($previousEmoji === $emoji) {
                    return ['message' => null, 'not_found' => false];
                }

                $reactions['contact'] = $emoji;
            } else {
                if (! array_key_exists('contact', $reactions)) {
                    return ['message' => null, 'not_found' => false];
                }

                unset($reactions['contact']);
            }

            if ($reactions === []) {
                unset($metadata['reactions']);
            } else {
                $metadata['reactions'] = $reactions;
            }

            $target->forceFill(['metadata' => $metadata])->save();

            return ['message' => $target->fresh(), 'not_found' => false];
        });

        if ($result['message']) {
            broadcast(new MessageReactionUpdated($result['message']));
        } elseif ($result['not_found']) {
            Log::info('[MetaMessageReactionProcessor] No se encontró el mensaje objetivo para la reacción.', [
                'channel_id' => $channel->id,
                'target_message_id_hash' => substr(hash('sha256', $targetMessageId), 0, 16),
            ]);
        }
    }
}
