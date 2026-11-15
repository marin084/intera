<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WebchatSession extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'webchat_sessions';

    protected $fillable = [
        'channel_id',
        'contact_id',
        'token_hash',
        'origin',
        'active_conversation_id',
        'last_seen_at',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function activeConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'active_conversation_id');
    }

    public function isValid(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Generate a cryptographically secure token and its hash.
     *
     * @return array{raw_token: string, token_hash: string}
     */
    public static function createTokenPair(): array
    {
        $raw = 'wcs_'.Str::random(64);
        $hash = hash('sha256', $raw);

        return [
            'raw_token' => $raw,
            'token_hash' => $hash,
        ];
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
