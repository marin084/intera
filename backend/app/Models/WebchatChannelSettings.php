<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WebchatChannelSettings extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'webchat_channel_settings';

    protected $fillable = [
        'channel_id',
        'public_key',
        'allowed_origins',
        'display_name',
        'accent_color',
        'launcher_position',
        'launcher_style',
        'availability_threshold',
        'availability_high_message',
        'availability_low_message',
        'availability_zero_message',
        'launcher_cta_label',
        'launcher_description',
        'launcher_icon_path',
    ];

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'availability_threshold' => 'integer',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public static function generatePublicKey(): string
    {
        return 'wc_pub_'.Str::random(32);
    }
}
