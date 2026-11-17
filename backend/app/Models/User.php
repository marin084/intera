<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'last_login_at',
        'last_seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_user_id');
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_user');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')->withTimestamps();
    }

    public function belongsToTeam(string $teamId): bool
    {
        if ($this->relationLoaded('teams')) {
            return $this->teams->contains(fn (Team $team) => (string) $team->id === $teamId);
        }

        return $this->teams()->where('teams.id', $teamId)->exists();
    }

    public function canAccessChannel(string $channelId): bool
    {
        if ($this->hasRole('super_admin')) {
            return true;
        }

        if ($this->relationLoaded('channels')) {
            return $this->channels->contains(fn (Channel $channel) => (string) $channel->id === $channelId);
        }

        return $this->channels()->where('channels.id', $channelId)->exists();
    }

    public function hasRole(string|array $roles): bool
    {
        $roleList = is_array($roles) ? $roles : [$roles];

        return $this->roles->pluck('name')->intersect($roleList)->isNotEmpty();
    }

    public function hasPermission(string $permission): bool
    {
        // Super admin has all permissions
        if ($this->hasRole('super_admin')) {
            return true;
        }

        return $this->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('name')
            ->contains($permission);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        if ($this->hasRole('super_admin')) {
            return true;
        }

        $userPermissions = $this->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('name')
            ->unique();

        return $userPermissions->intersect($permissions)->isNotEmpty();
    }

    public function allPermissions(): Collection
    {
        if ($this->hasRole('super_admin')) {
            return Permission::all();
        }

        return $this->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->unique('id')
            ->values();
    }
}
