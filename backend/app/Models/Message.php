<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Message extends Model
{
    use HasFactory, HasUuids;

    protected $hidden = ['source_sent_at'];

    /**
     * Generate a sortable, time-ordered UUIDv7 for messages aligned with sent_at.
     */
    public function newUniqueId(): string
    {
        $time = $this->sent_at ?: now();
        return (string) Str::uuid7($time);
    }

    protected static function booted(): void
    {
        static::creating(function (Message $message) {
            if (! $message->sent_at) {
                $message->sent_at = now();
            }

            // Ensure messages generated in rapid succession in the same conversation are strictly monotonic
            if ($message->conversation_id) {
                $latest = static::where('conversation_id', $message->conversation_id)
                    ->orderBy('sent_at', 'desc')
                    ->orderBy('id', 'desc')
                    ->first();

                if ($latest && $latest->sent_at && $message->sent_at->lte($latest->sent_at)) {
                    $message->sent_at = $latest->sent_at->copy()->addSeconds(1);
                }
            }
        });
    }

    protected $fillable = [
        'conversation_id',
        'flow_execution_id',
        'flow_node_id',
        'sender_type',
        'sender_id',
        'direction',
        'message_type',
        'visibility',
        'status',
        'content',
        'payload',
        'external_message_id',
        'metadata',
        'sent_at',
        'source_sent_at',
    ];

    protected $attributes = [
        'visibility' => 'public',
        'status' => 'sent',
    ];

    protected function casts(): array
    {
        return [
            'sender_type' => MessageSenderType::class,
            'direction' => MessageDirection::class,
            'message_type' => MessageType::class,
            'status' => MessageStatus::class,
            'payload' => 'array',
            'metadata' => 'array',
            'sent_at' => 'datetime',
            'source_sent_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === MessageStatus::PENDING;
    }

    public function isSent(): bool
    {
        return $this->status === MessageStatus::SENT;
    }

    public function isDelivered(): bool
    {
        return $this->status === MessageStatus::DELIVERED || $this->status === MessageStatus::READ;
    }

    public function isRead(): bool
    {
        return $this->status === MessageStatus::READ;
    }

    public function isFailed(): bool
    {
        return $this->status === MessageStatus::FAILED;
    }

    public function scopePublic($query)
    {
        return $query->whereIn('visibility', ['public', 'external']);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function flowExecution(): BelongsTo
    {
        return $this->belongsTo(FlowExecution::class);
    }

    public function flowNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class);
    }
}
