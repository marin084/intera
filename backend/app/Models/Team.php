<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $appends = [
        'queue_wait_target_default_seconds',
        'first_response_target_default_seconds',
        'queue_wait_target_effective_seconds',
        'first_response_target_effective_seconds',
    ];

    protected $fillable = [
        'workspace_id',
        'name',
        'description',
        'is_default',
        'is_active',
        'queue_wait_target_seconds',
        'first_response_target_seconds',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'queue_wait_target_seconds' => 'integer',
            'first_response_target_seconds' => 'integer',
        ];
    }

    public function getQueueWaitTargetDefaultSecondsAttribute(): int
    {
        return (int) config('teams.service_targets.queue_wait_seconds', 120);
    }

    public function getFirstResponseTargetDefaultSecondsAttribute(): int
    {
        return (int) config('teams.service_targets.first_response_seconds', 300);
    }

    public function getQueueWaitTargetEffectiveSecondsAttribute(): int
    {
        return $this->queue_wait_target_seconds ?? $this->queue_wait_target_default_seconds;
    }

    public function getFirstResponseTargetEffectiveSecondsAttribute(): int
    {
        return $this->first_response_target_seconds ?? $this->first_response_target_default_seconds;
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->withTimestamps();
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'team_channels')->withTimestamps();
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
