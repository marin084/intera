<?php

namespace App\Models;

use App\Enums\FlowExecutionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowExecution extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'conversation_id',
        'flow_id',
        'flow_version_id',
        'current_node_id',
        'status',
        'context',
        'started_at',
        'waiting_since',
        'completed_at',
        'failed_at',
        'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => FlowExecutionStatus::class,
            'context' => 'array',
            'started_at' => 'datetime',
            'waiting_since' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
    }

    public function currentNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'current_node_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(FlowExecutionStep::class)->orderBy('entered_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('sent_at');
    }
}
