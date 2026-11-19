<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'active_workspace_id',
        'is_platform_admin',
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
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->withPivot(['role_id', 'is_active'])
            ->withTimestamps();
    }

    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceUser::class);
    }

    public function activeWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'active_workspace_id');
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

    /**
     * Return the active membership for an explicit or request-bound workspace.
     * Permission checks use this method so legacy role_user assignments cannot
     * leak into another tenant once a workspace context is present.
     */
    public function workspaceMembership(?string $workspaceId = null): ?WorkspaceUser
    {
        $workspaceId ??= app()->bound('current_workspace_id') ? app('current_workspace_id') : null;
        if (! $workspaceId) {
            return null;
        }

        return $this->workspaceMemberships()
            ->with(['workspace', 'role.permissions'])
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->whereHas('workspace', fn ($query) => $query->where('status', 'active'))
            ->first();
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

    public function hasRole(string|array $roles, ?string $workspaceId = null): bool
    {
        $roleList = is_array($roles) ? $roles : [$roles];

        $contextWorkspaceId = $workspaceId
            ?? (app()->bound('current_workspace_id') ? app('current_workspace_id') : null);
        if ($contextWorkspaceId) {
            $membership = $this->workspaceMembership($contextWorkspaceId);

            return $membership?->role !== null
                && in_array($membership->role->name, $roleList, true);
        }

        return $this->roles->pluck('name')->intersect($roleList)->isNotEmpty();
    }

    public function hasPermission(string $permission, ?string $workspaceId = null): bool
    {
        $contextWorkspaceId = $workspaceId
            ?? (app()->bound('current_workspace_id') ? app('current_workspace_id') : null);
        if ($contextWorkspaceId) {
            $membership = $this->workspaceMembership($contextWorkspaceId);
            if (! $membership?->role) {
                return false;
            }

            return $membership->role->name === 'super_admin'
                || $membership->role->permissions->contains('name', $permission);
        }

        // Super admin has all permissions outside a tenant context (commands,
        // migrations and the login response).
        if ($this->hasRole('super_admin')) {
            return true;
        }

        return $this->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('name')
            ->contains($permission);
    }

    public function hasAnyPermission(array $permissions, ?string $workspaceId = null): bool
    {
        $contextWorkspaceId = $workspaceId
            ?? (app()->bound('current_workspace_id') ? app('current_workspace_id') : null);
        if ($contextWorkspaceId) {
            $membership = $this->workspaceMembership($contextWorkspaceId);
            if (! $membership?->role) {
                return false;
            }

            return $membership->role->name === 'super_admin'
                || $membership->role->permissions->pluck('name')->intersect($permissions)->isNotEmpty();
        }

        if ($this->hasRole('super_admin')) {
            return true;
        }

        $userPermissions = $this->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('name')
            ->unique();

        return $userPermissions->intersect($permissions)->isNotEmpty();
    }

    public function allPermissions(?string $workspaceId = null): Collection
    {
        $contextWorkspaceId = $workspaceId
            ?? (app()->bound('current_workspace_id') ? app('current_workspace_id') : null);
        if ($contextWorkspaceId) {
            $membership = $this->workspaceMembership($contextWorkspaceId);
            if (! $membership?->role) {
                return collect();
            }

            return $membership->role->name === 'super_admin'
                ? Permission::query()->get()
                : $membership->role->permissions->unique('id')->values();
        }

        if ($this->hasRole('super_admin')) {
            return Permission::all();
        }

        return $this->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->unique('id')
            ->values();
    }
}
