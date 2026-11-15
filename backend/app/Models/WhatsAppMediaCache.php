<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMediaCache extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'whatsapp_media_cache';

    protected $fillable = [
        'channel_id',
        'media_id',
        'file_fingerprint',
        'mime_type',
        'file_name',
        'file_size',
        'uploaded_at',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'uploaded_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Check if the cached media ID is currently valid and unexpired.
     */
    public function isValid(): bool
    {
        return ! empty($this->media_id)
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * Scope to find a valid unexpired cache entry for a specific channel and fingerprint.
     */
    public function scopeValidFor(Builder $query, string $channelId, string $fingerprint): Builder
    {
        return $query->where('channel_id', $channelId)
            ->where('file_fingerprint', $fingerprint)
            ->where('expires_at', '>', now());
    }
}
