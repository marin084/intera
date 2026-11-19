<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder): void {
            if (! app()->bound('current_workspace_id')) {
                return;
            }

            $workspaceId = app('current_workspace_id');
            if (! $workspaceId) {
                return;
            }

            $builder->where(
                $builder->getModel()->qualifyColumn('workspace_id'),
                $workspaceId
            );
        });

        static::creating(function ($model): void {
            if (app()->bound('current_workspace_id') && app('current_workspace_id')) {
                // Never trust a workspace_id supplied by a client while a tenant
                // context is active. The request context is the source of truth.
                $model->workspace_id = app('current_workspace_id');
            } elseif (empty($model->workspace_id)) {
                $model->workspace_id = Workspace::initialWorkspaceId();
            }
        });
    }

    /**
     * Explicit workspace scope for jobs, commands and other non-HTTP callers.
     */
    public function scopeForWorkspace(Builder $query, string $workspaceId): Builder
    {
        return $query->withoutGlobalScope('workspace')->where(
            $query->getModel()->qualifyColumn('workspace_id'),
            $workspaceId
        );
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
