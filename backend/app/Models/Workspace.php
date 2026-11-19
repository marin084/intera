<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'initial_owner_id',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
        ];
    }

    public static function initialWorkspaceId(): ?string
    {
        try {
            $slug = env('APP_INITIAL_WORKSPACE_SLUG', 'principal');

            return static::query()->where('slug', $slug)->value('id')
                ?? static::query()->orderBy('created_at')->value('id');
        } catch (\Throwable) {
            return null;
        }
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot(['role_id', 'is_active'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceUser::class);
    }

    public function initialOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initial_owner_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function flows(): HasMany
    {
        return $this->hasMany(Flow::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function cannedResponses(): HasMany
    {
        return $this->hasMany(CannedResponse::class);
    }

    public function savedFunnels(): HasMany
    {
        return $this->hasMany(SavedFunnel::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function presenceSessions(): HasMany
    {
        return $this->hasMany(AgentPresenceSession::class);
    }
}
