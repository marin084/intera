<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::creating(function ($model): void {
            if (empty($model->workspace_id)) {
                $model->workspace_id = app()->has('current_workspace_id')
                    ? app('current_workspace_id')
                    : Workspace::initialWorkspaceId();
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
