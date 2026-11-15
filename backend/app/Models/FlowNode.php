<?php

namespace App\Models;

use App\Enums\FlowNodeType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowNode extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'flow_version_id',
        'node_key',
        'type',
        'name',
        'configuration',
        'position_x',
        'position_y',
    ];

    protected function casts(): array
    {
        return [
            'type' => FlowNodeType::class,
            'configuration' => 'array',
            'position_x' => 'float',
            'position_y' => 'float',
        ];
    }

    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
    }
}
