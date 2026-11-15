import { useSyncExternalStore } from 'react';

/**
 * Custom hook to detect media query matches using React's useSyncExternalStore.
 * Safe for SSR and avoids cascading re-renders in effects.
 */
export function useMediaQuery(query: string): boolean {
  const subscribe = (callback: () => void) => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
      return () => {};
    }
    const media = window.matchMedia(query);
    if (media.addEventListener) {
      media.addEventListener('change', callback);
      return () => media.removeEventListener('change', callback);
    } else if ('addListener' in media) {
      // Legacy Safari / older browsers support
      (media as { addListener: (listener: () => void) => void }).addListener(callback);
      return () => {
        (media as { removeListener: (listener: () => void) => void }).removeListener(callback);
      };
    }
    return () => {};
  };

  const getSnapshot = () => {
    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
      return window.matchMedia(query).matches;
    }
    return false;
  };

  const getServerSnapshot = () => false;

  return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}

/**
 * Returns true if the screen width is <= 768px (standard mobile / small tablet breakpoint).
 */
export function useIsMobile(): boolean {
  return useMediaQuery('(max-width: 768px)');
}
