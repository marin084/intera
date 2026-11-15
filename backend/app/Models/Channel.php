<?php

namespace App\Models;

use App\Casts\EncryptedChannelConfiguration;
use App\Enums\ChannelType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Channel extends Model
{
    use HasFactory, HasUuids;

    public const DEFAULT_INACTIVITY_WARNING_MESSAGE = 'Hola {{nombre}}, notamos que no has respondido en los últimos minutos. ¿Sigues por aquí? Si no recibes o envías respuesta pronto, daremos por finalizada la sesión.';

    public const DEFAULT_INACTIVITY_CLOSE_MESSAGE = 'Por inactividad hemos dado por finalizada esta conversación. ¡Cuando gustes, vuelve a escribirnos!';

    protected $fillable = [
        'name',
        'type',
        'is_active',
        'entry_flow_id',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'is_active' => 'boolean',
            'configuration' => EncryptedChannelConfiguration::class,
        ];
    }

    public function getInactivityConfig(): array
    {
        $config = $this->configuration ?? [];
        $isWebchat = ($this->type === ChannelType::WEBCHAT || (is_string($this->type) && $this->type === 'webchat'));

        return [
            'inactivity_enabled' => array_key_exists('inactivity_enabled', $config) ? (bool) $config['inactivity_enabled'] : true,
            'inactivity_warning_enabled' => array_key_exists('inactivity_warning_enabled', $config) ? (bool) $config['inactivity_warning_enabled'] : true,
            'inactivity_warning_minutes' => isset($config['inactivity_warning_minutes']) && is_numeric($config['inactivity_warning_minutes'])
                ? (int) $config['inactivity_warning_minutes']
                : ($isWebchat ? 10 : 15),
            'inactivity_warning_message' => ! empty($config['inactivity_warning_message'])
                ? (string) $config['inactivity_warning_message']
                : self::DEFAULT_INACTIVITY_WARNING_MESSAGE,
            'inactivity_close_minutes' => isset($config['inactivity_close_minutes']) && is_numeric($config['inactivity_close_minutes'])
                ? (int) $config['inactivity_close_minutes']
                : ($isWebchat ? 15 : 30),
            'inactivity_close_message' => ! empty($config['inactivity_close_message'])
                ? (string) $config['inactivity_close_message']
                : self::DEFAULT_INACTIVITY_CLOSE_MESSAGE,
        ];
    }

    public function entryFlow(): BelongsTo
    {
        return $this->belongsTo(Flow::class, 'entry_flow_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'channel_user');
    }

    public function webchatSettings(): HasOne
    {
        return $this->hasOne(WebchatChannelSettings::class);
    }

    public function whatsappMediaCaches(): HasMany
    {
        return $this->hasMany(WhatsAppMediaCache::class);
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        if (isset($array['configuration']) && is_array($array['configuration'])) {
            foreach (EncryptedChannelConfiguration::SECRET_KEYS as $secretKey) {
                if (! empty($array['configuration'][$secretKey])) {
                    $array['configuration'][$secretKey] = '***REDACTED***';
                    $array['configuration']['has_'.$secretKey] = true;
                }
            }
        }

        return $array;
    }

    public function webchatSessions(): HasMany
    {
        return $this->hasMany(WebchatSession::class);
    }
}
