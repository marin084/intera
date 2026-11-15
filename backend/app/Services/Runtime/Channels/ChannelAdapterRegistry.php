<?php

namespace App\Services\Runtime\Channels;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Services\Runtime\Channels\Adapters\FacebookMessengerChannelAdapter;
use App\Services\Runtime\Channels\Adapters\InstagramChannelAdapter;
use App\Services\Runtime\Channels\Adapters\TelegramChannelAdapter;
use App\Services\Runtime\Channels\Adapters\WebchatChannelAdapter;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use App\Services\Runtime\Channels\Contracts\ChannelAdapter;
use InvalidArgumentException;

class ChannelAdapterRegistry
{
    /** @var array<string, ChannelAdapter> */
    protected array $adapters = [];

    public function __construct(
        ?WebchatChannelAdapter $webchat = null,
        ?WhatsAppChannelAdapter $whatsapp = null,
        ?FacebookMessengerChannelAdapter $messenger = null,
        ?InstagramChannelAdapter $instagram = null,
        ?TelegramChannelAdapter $telegram = null
    ) {
        $this->register($webchat ?? app(WebchatChannelAdapter::class));
        $this->register($whatsapp ?? app(WhatsAppChannelAdapter::class));
        $this->register($messenger ?? app(FacebookMessengerChannelAdapter::class));
        $this->register($instagram ?? app(InstagramChannelAdapter::class));
        $this->register($telegram ?? app(TelegramChannelAdapter::class));
    }

    public function register(ChannelAdapter $adapter): void
    {
        $this->adapters[$adapter->getChannelType()] = $adapter;
    }

    public function get(string $channelType): ChannelAdapter
    {
        if (! isset($this->adapters[$channelType])) {
            throw new InvalidArgumentException("No se encontró ningún adaptador registrado para el canal: '{$channelType}'.");
        }

        return $this->adapters[$channelType];
    }

    public function forChannel(Channel $channel): ChannelAdapter
    {
        $type = $channel->type instanceof ChannelType ? $channel->type->value : (string) $channel->type;

        return $this->get($type);
    }
}
