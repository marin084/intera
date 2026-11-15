<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CannedResponse extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'title',
        'shortcut',
        'content',
        'category',
        'is_active',
        'created_by',
    ];

    protected $appends = [
        'formatted_shortcut',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getFormattedShortcutAttribute(): ?string
    {
        return $this->shortcut ? '/' . ltrim($this->shortcut, '/') : null;
    }

    public function setShortcutAttribute(?string $value): void
    {
        $this->attributes['shortcut'] = $value !== null ? ltrim(trim($value), '/') : null;
    }
}
