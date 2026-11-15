/**
 * Validates whether a media/attachment URL is safe to render or navigate to.
 * Strictly permits only:
 * - http: and https: protocols
 * - blob: URLs (for local file previews)
 * - relative paths (starting with / but not //)
 * - safe data:image raster URIs (png, jpeg, webp, gif)
 * Explicitly rejects:
 * - javascript:
 * - data:text/html and arbitrary data: URIs
 * - vbscript:, file:, etc.
 */
export function isSafeMediaUrl(url?: string | null): boolean {
  if (!url || typeof url !== 'string') {
    return false;
  }

  const trimmed = url.trim();
  if (!trimmed) {
    return false;
  }

  // Reject protocol-relative URLs (e.g. //attacker.com)
  if (trimmed.startsWith('//')) {
    return false;
  }

  // Safe relative paths (e.g. /storage/...)
  if (trimmed.startsWith('/')) {
    return true;
  }

  // Safe blob URLs for local previews
  if (trimmed.startsWith('blob:')) {
    return true;
  }

  const lower = trimmed.toLowerCase();

  // Explicitly block dangerous protocols
  if (
    lower.startsWith('javascript:') ||
    lower.startsWith('vbscript:') ||
    lower.startsWith('file:')
  ) {
    return false;
  }

  // Data URIs: strictly block text/html or generic scripts; allow safe image raster previews
  if (lower.startsWith('data:')) {
    if (
      lower.startsWith('data:image/png') ||
      lower.startsWith('data:image/jpeg') ||
      lower.startsWith('data:image/webp') ||
      lower.startsWith('data:image/gif')
    ) {
      return true;
    }
    return false;
  }

  // Parse absolute URL and verify scheme
  try {
    const parsed = new URL(trimmed);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:';
  } catch {
    return false;
  }
}

/**
 * Normalizes media and attachment URLs.
 * Ensures relative `/storage/...` paths or backend-bound URLs (`http://localhost:8000/storage/...`,
 * `http://backend:8000/storage/...`) resolve cleanly through the current host / Nginx proxy.
 * Rejects any unsafe scheme (javascript:, data:text/html, etc.).
 */
export function resolveMediaUrl(url?: string | null): string {
  if (!isSafeMediaUrl(url)) {
    return '';
  }

  const trimmed = url!.trim();

  // Handle data URIs or object URLs directly
  if (trimmed.startsWith('data:') || trimmed.startsWith('blob:')) {
    return trimmed;
  }

  // If it contains /storage/, normalize it to relative /storage/... so the frontend reverse proxy handles it
  const storageIndex = trimmed.indexOf('/storage/');
  if (storageIndex !== -1) {
    return trimmed.substring(storageIndex);
  }

  // If already relative
  if (trimmed.startsWith('/')) {
    return trimmed;
  }

  // External URLs (e.g. CDNs https://...)
  return trimmed;
}
