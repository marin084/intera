<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationQueueEntry extends Model
{
    protected $fillable = [
        'conversation_id', 'team_id', 'entered_at', 'assigned_at',
        'assigned_user_id', 'ended_at', 'exit_reason',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'assigned_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
