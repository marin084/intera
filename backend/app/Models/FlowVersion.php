<?php

namespace App\Models;

use App\Enums\FlowVersionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowVersion extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'flow_id',
        'version_number',
        'status',
        'description',
        'metadata',
        'published_at',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'status' => FlowVersionStatus::class,
            'metadata' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(FlowNode::class);
    }

    public function edges(): HasMany
    {
        return $this->hasMany(FlowEdge::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'active_flow_version_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(FlowExecution::class, 'flow_version_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(FlowPublication::class, 'flow_version_id');
    }
}
