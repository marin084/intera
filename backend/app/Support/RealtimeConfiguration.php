<?php

namespace App\Support;

class RealtimeConfiguration
{
    public static function hasUnsafeAppKey(): bool
    {
        $reverbKey = config('reverb.apps.apps.0.key');
        $appKey = config('app.key');

        return ! is_string($reverbKey)
            || trim($reverbKey) === ''
            || (is_string($appKey) && $appKey !== '' && str_contains($reverbKey, $appKey));
    }
}
