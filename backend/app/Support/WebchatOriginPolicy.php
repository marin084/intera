<?php

namespace App\Support;

class WebchatOriginPolicy
{
    /**
     * Check whether an origin belongs to this Intera instance.
     * Platform origins are trusted for built-in previews, independently of
     * each channel's public website allowlist.
     */
    public static function isPlatformOrigin(string $origin): bool
    {
        $configuredOrigins = array_filter(array_merge(
            [config('app.url')],
            config('cors.allowed_origins', [])
        ));

        $normalizedOrigin = self::normalize($origin);

        foreach ($configuredOrigins as $configuredOrigin) {
            if (self::normalize((string) $configuredOrigin) === $normalizedOrigin) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! isset($parts['scheme'], $parts['host'])) {
            return rtrim(trim($url), '/');
        }

        $origin = strtolower($parts['scheme'].'://'.$parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
