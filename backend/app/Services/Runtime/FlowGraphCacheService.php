<?php

namespace App\Services\Runtime;

use App\Models\FlowVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class FlowGraphCacheService
{
    public const CACHE_PREFIX = 'flow_graph_version:';

    /**
     * Resolves a FlowVersion with its nodes and edges eagerly loaded,
     * leveraging in-memory / store cache for published (immutable) versions.
     */
    public function getVersionWithGraph(string|FlowVersion $versionOrId): ?FlowVersion
    {
        $versionId = $versionOrId instanceof FlowVersion ? $versionOrId->id : $versionOrId;

        $enabled = (bool) config('flows.cache.enabled', true);
        if (! $enabled) {
            return $this->loadFreshVersion($versionId);
        }

        $cacheKey = self::CACHE_PREFIX.$versionId;
        $ttl = (int) config('flows.cache.ttl_seconds', 3600);

        try {
            // Only published versions are cached indefinitely (within TTL) because drafts can change
            return Cache::remember($cacheKey, $ttl, function () use ($versionId) {
                return $this->loadFreshVersion($versionId);
            });
        } catch (Throwable $e) {
            Log::debug("[FlowGraphCacheService] Cache indisponible ({$e->getMessage()}), cargando versión {$versionId} directamente de BD.");

            return $this->loadFreshVersion($versionId);
        }
    }

    /**
     * Invalidates cached graph for a given version.
     */
    public function invalidate(string|FlowVersion $versionOrId): void
    {
        $versionId = $versionOrId instanceof FlowVersion ? $versionOrId->id : $versionOrId;

        try {
            Cache::forget(self::CACHE_PREFIX.$versionId);
        } catch (Throwable $e) {
            Log::debug("[FlowGraphCacheService] Error al invalidar caché para versión {$versionId}: {$e->getMessage()}");
        }
    }

    protected function loadFreshVersion(string $versionId): ?FlowVersion
    {
        return FlowVersion::with(['nodes', 'edges', 'flow'])->find($versionId);
    }
}
