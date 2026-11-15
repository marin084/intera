<?php

namespace App\Services\Runtime;

use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Models\Channel;
use App\Models\Flow;
use App\Models\FlowVersion;
use Illuminate\Support\Facades\Log;

class FlowResolver
{
    /**
     * Resolves the active published FlowVersion for a given Channel.
     */
    public function resolveEntryFlowVersion(Channel $channel): ?FlowVersion
    {
        if (! $channel->is_active || ! $channel->entry_flow_id) {
            return null;
        }

        /** @var Flow|null $flow */
        $flow = Flow::with('activeVersion')->find($channel->entry_flow_id);
        if (! $flow || $flow->status !== FlowStatus::PUBLISHED || ! $flow->active_version_id) {
            return null;
        }

        // Verify channel applicability if defined
        if (! empty($flow->channel_applicability) && is_array($flow->channel_applicability)) {
            $channelType = is_object($channel->type) ? $channel->type->value : $channel->type;
            if (! in_array($channelType, $flow->channel_applicability, true)) {
                Log::warning("[FlowResolver] El flujo '{$flow->name}' [{$flow->id}] no es aplicable al canal [{$channel->id}] de tipo '{$channelType}'. Canales aplicables: " . implode(', ', $flow->channel_applicability));

                return null;
            }
        }

        /** @var FlowVersion|null $version */
        $version = $flow->activeVersion;
        if (! $version || $version->status !== FlowVersionStatus::PUBLISHED) {
            return null;
        }

        return $version;
    }
}
