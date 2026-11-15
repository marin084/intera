<?php

namespace App\Models;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\MessageDirection;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Conversation extends Model
{
    use HasFactory, HasUuids;

    protected $appends = ['whatsapp_window', 'meta_window', 'closing_assigned_user_id'];

    protected $fillable = [
        'channel_id',
        'contact_id',
        'status',
        'assigned_user_id',
        'active_flow_id',
        'active_flow_version_id',
        'is_simulation',
        'started_at',
        'last_message_at',
        'closed_at',
        'metadata',
        'bot_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_simulation' => 'boolean',
            'bot_enabled' => 'boolean',
            'status' => ConversationStatus::class,
            'started_at' => 'datetime',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'conversation_tag')
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    public function addTag(string $tag, ?int $assignedBy = null): array
    {
        $tag = trim($tag);
        if ($tag === '') {
            return $this->tags->pluck('name')->all();
        }

        $tagModel = Tag::firstOrCreate(
            ['name' => $tag],
            ['slug' => Str::slug($tag), 'color' => '#3B82F6']
        );

        $this->tags()->syncWithoutDetaching([$tagModel->id => ['assigned_by' => $assignedBy]]);

        $metadata = $this->metadata ?? [];
        $tags = is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [];

        if (! in_array($tag, $tags, true)) {
            $tags[] = $tag;
        }

        $metadata['tags'] = array_values($tags);
        $this->metadata = $metadata;
        $this->save();

        return $this->tags()->pluck('name')->all();
    }

    public function removeTag(string $tag): array
    {
        $tag = trim($tag);
        $tagModel = Str::isUuid($tag)
            ? Tag::where('id', $tag)->orWhere('name', $tag)->first()
            : Tag::where('name', $tag)->first();

        if ($tagModel) {
            $this->tags()->detach($tagModel->id);
            $tagName = $tagModel->name;
        } else {
            $tagName = $tag;
        }

        $metadata = $this->metadata ?? [];
        $tags = is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [];
        $tags = array_values(array_filter($tags, fn ($t) => $t !== $tagName && $t !== $tag));

        $metadata['tags'] = $tags;
        $this->metadata = $metadata;
        $this->save();

        return $this->tags()->pluck('name')->all();
    }

    public function getLastInboundAtAttribute(): ?Carbon
    {
        if (isset($this->attributes['last_inbound_sent_at'])) {
            return $this->attributes['last_inbound_sent_at'] ? Carbon::parse($this->attributes['last_inbound_sent_at']) : null;
        }

        if ($this->relationLoaded('messages')) {
            $lastInbound = $this->messages
                ->where('direction', MessageDirection::INBOUND)
                ->sortByDesc(fn (Message $message) => $message->source_sent_at ?? $message->sent_at)
                ->first();

            $lastInboundAt = $lastInbound?->source_sent_at ?? $lastInbound?->sent_at;

            return $lastInboundAt ? Carbon::parse($lastInboundAt) : null;
        }

        $sentAt = Message::where('conversation_id', $this->id)
            ->where('direction', MessageDirection::INBOUND)
            ->orderByRaw('COALESCE(source_sent_at, sent_at) DESC')
            ->selectRaw('COALESCE(source_sent_at, sent_at) AS effective_sent_at')
            ->value('effective_sent_at');

        return $sentAt ? Carbon::parse($sentAt) : null;
    }

    public function getMetaWindowExpiresAtAttribute(): ?Carbon
    {
        $lastInboundAt = $this->last_inbound_at;

        return $lastInboundAt ? $lastInboundAt->copy()->addHours(24) : null;
    }

    public function getWhatsappWindowExpiresAtAttribute(): ?Carbon
    {
        return $this->meta_window_expires_at;
    }

    public function getIsMetaWindowOpenAttribute(): bool
    {
        $channel = $this->relationLoaded('channel') ? $this->channel : Channel::find($this->channel_id);

        if (! $channel) {
            return true;
        }

        $channelType = is_object($channel->type) ? $channel->type->value : (string) $channel->type;
        $isMetaChannel = in_array($channelType, [
            ChannelType::WHATSAPP->value,
            ChannelType::FACEBOOK_MESSENGER->value,
            ChannelType::INSTAGRAM->value,
        ], true);

        if (! $isMetaChannel) {
            return true;
        }

        $expiresAt = $this->meta_window_expires_at;

        if (! $expiresAt) {
            return false;
        }

        return now()->lessThan($expiresAt);
    }

    public function getIsWhatsappWindowOpenAttribute(): bool
    {
        return $this->is_meta_window_open;
    }

    public function getIsMessengerWindowOpenAttribute(): bool
    {
        return $this->is_meta_window_open;
    }

    public function getIsInstagramWindowOpenAttribute(): bool
    {
        return $this->is_meta_window_open;
    }

    public function getWhatsappWindowAttribute(): array
    {
        $channel = $this->relationLoaded('channel') ? $this->channel : Channel::find($this->channel_id);
        $channelType = is_object($channel?->type) ? $channel->type->value : (string) $channel?->type;
        $isMeta = in_array($channelType, [
            ChannelType::WHATSAPP->value,
            ChannelType::FACEBOOK_MESSENGER->value,
            ChannelType::INSTAGRAM->value,
        ], true);

        if (! $isMeta) {
            return [
                'is_applicable' => false,
                'is_open' => true,
                'channel_type' => $channelType,
                'last_inbound_at' => null,
                'expires_at' => null,
                'remaining_seconds' => null,
            ];
        }

        $lastInboundAt = $this->last_inbound_at;
        $expiresAt = $this->meta_window_expires_at;
        $isOpen = $expiresAt ? now()->lessThan($expiresAt) : false;
        $remainingSeconds = ($isOpen && $expiresAt) ? max(0, (int) now()->diffInSeconds($expiresAt, false)) : 0;

        return [
            'is_applicable' => true,
            'is_open' => $isOpen,
            'channel_type' => $channelType,
            'last_inbound_at' => $lastInboundAt?->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'remaining_seconds' => $remainingSeconds,
        ];
    }

    public function getMetaWindowAttribute(): array
    {
        return $this->whatsapp_window;
    }

    public function scopeReal($query)
    {
        return $query->where('is_simulation', false);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function activeFlow(): BelongsTo
    {
        return $this->belongsTo(Flow::class, 'active_flow_id');
    }

    public function activeFlowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'active_flow_version_id');
    }

    public function flowExecutions(): HasMany
    {
        return $this->hasMany(FlowExecution::class)->orderByDesc('started_at');
    }

    /**
     * Get the ID of the agent who held the most recent assignment when the conversation closed.
     */
    public function getClosingAssignedUserIdAttribute(): ?int
    {
        $metadata = $this->metadata ?? [];
        if (is_array($metadata) && array_key_exists('closing_assignment_captured', $metadata)) {
            return ! empty($metadata['last_assigned_user_id']) ? (int) $metadata['last_assigned_user_id'] : null;
        }

        if (! empty($metadata['last_assigned_user_id'])) {
            return (int) $metadata['last_assigned_user_id'];
        }

        if ($this->relationLoaded('assignments')) {
            $latest = $this->assignments->sortByDesc('assigned_at')->sortByDesc('id')->first();

            return $latest?->assigned_user_id ? (int) $latest->assigned_user_id : null;
        }

        $latestAssigned = $this->assignments()->orderByDesc('assigned_at')->orderByDesc('id')->value('assigned_user_id');

        return $latestAssigned ? (int) $latestAssigned : null;
    }

    public function activeExecution(): HasMany
    {
        return $this->hasMany(FlowExecution::class)
            ->whereIn('status', [
                FlowExecutionStatus::RUNNING,
                FlowExecutionStatus::WAITING_INPUT,
                FlowExecutionStatus::WAITING_AGENT,
            ])
            ->orderByDesc('started_at');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('sent_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ConversationAssignment::class)->orderByDesc('assigned_at');
    }

    public function readStates(): HasMany
    {
        return $this->hasMany(ConversationReadState::class);
    }
}
