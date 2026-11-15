<?php

namespace App\Models;

use App\Enums\FlowExecutionStepStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowExecutionStep extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'flow_execution_id',
        'flow_node_id',
        'node_type',
        'status',
        'entered_at',
        'completed_at',
        'input',
        'output',
        'error_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => FlowExecutionStepStatus::class,
            'entered_at' => 'datetime',
            'completed_at' => 'datetime',
            'input' => 'array',
            'output' => 'array',
            'metadata' => 'array',
        ];
    }

    public function flowExecution(): BelongsTo
    {
        return $this->belongsTo(FlowExecution::class);
    }

    public function flowNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class);
    }
}
