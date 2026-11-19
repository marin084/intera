<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder): void {
            if (! app()->bound('current_workspace_id')) {
                return;
            }

            $workspaceId = app('current_workspace_id');
            if ($workspaceId) {
                $builder->where(function (Builder $query) use ($workspaceId): void {
                    $query->where($builder->getModel()->qualifyColumn('workspace_id'), $workspaceId)
                        // Platform audit records are intentionally visible only
                        // outside a tenant context, never mixed into tenant data.
                        ->whereNotNull($builder->getModel()->qualifyColumn('workspace_id'));
                });
            }
        });
    }

    protected $fillable = [
        'workspace_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
