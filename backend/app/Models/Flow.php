<?php

namespace App\Models;

use App\Enums\FlowStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flow extends Model
{
    use BelongsToWorkspace, HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'name',
        'description',
        'status',
        'channel_applicability',
        'active_version_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => FlowStatus::class,
            'channel_applicability' => 'array',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class)->orderBy('version_number');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'active_version_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(FlowPublication::class)->orderByDesc('created_at');
    }

    public function savedFunnels(): HasMany
    {
        return $this->hasMany(SavedFunnel::class)->orderByDesc('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
