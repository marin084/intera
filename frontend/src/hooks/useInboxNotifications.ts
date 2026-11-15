import { useState, useRef, useEffect, useCallback } from 'react';

export const SOUND_STORAGE_KEY = 'conexa_inbox_sound_enabled';
export const DESKTOP_STORAGE_KEY = 'conexa_inbox_desktop_enabled';

export interface DesktopNotificationOptions {
  title: string;
  body: string;
  conversationId?: string;
  onClick?: () => void;
  icon?: string;
}

export interface UseInboxNotificationsOptions {
  unreadOrWaitingCount?: number;
  onSelectConversation?: (conversationId: string) => void;
}

export interface UseInboxNotificationsResult {
  soundEnabled: boolean;
  desktopEnabled: boolean;
  permissionStatus: NotificationPermission | 'unsupported';
  toggleSound: () => void;
  requestDesktopPermission: () => Promise<boolean>;
  playMessageSound: () => void;
  playAssignmentSound: () => void;
  notifyDesktop: (options: DesktopNotificationOptions) => void;
}

/**
 * Helper to obtain or instantiate AudioContext safely across browsers and environments.
 */
function getAudioContext(): AudioContext | null {
  try {
    const AudioCtx = window.AudioContext || (window as any).webkitAudioContext;
    if (!AudioCtx) return null;
    return new AudioCtx();
  } catch {
    return null;
  }
}

/**
 * Synthesizes a short, subtle message alert tone (D5 587.33Hz -> A5 880Hz, ~150ms).
 */
export function playMessageTone(audioCtx?: AudioContext | null): void {
  try {
    const ctx = audioCtx || getAudioContext();
    if (!ctx) return;
    if (ctx.state === 'suspended') {
      ctx.resume().catch(() => {});
    }

    const osc = ctx.createOscillator();
    const gain = ctx.createGain();

    osc.type = 'sine';
    const t = ctx.currentTime;
    osc.frequency.setValueAtTime(587.33, t);
    osc.frequency.exponentialRampToValueAtTime(880, t + 0.12);

    gain.gain.setValueAtTime(0.12, t);
    gain.gain.exponentialRampToValueAtTime(0.001, t + 0.15);

    osc.connect(gain);
    gain.connect(ctx.destination);

    osc.start(t);
    osc.stop(t + 0.15);
  } catch {
    // Ignore autoplay restriction or unsupported AudioContext
  }
}

/**
 * Synthesizes a melodic triple tone for assignments or waiting queue (C5 -> E5 -> G5, ~280ms).
 */
export function playAssignmentTone(audioCtx?: AudioContext | null): void {
  try {
    const ctx = audioCtx || getAudioContext();
    if (!ctx) return;
    if (ctx.state === 'suspended') {
      ctx.resume().catch(() => {});
    }

    const t = ctx.currentTime;
    const notes = [
      { freq: 523.25, time: t, dur: 0.09 },
      { freq: 659.25, time: t + 0.09, dur: 0.09 },
      { freq: 783.99, time: t + 0.18, dur: 0.10 },
    ];

    notes.forEach(({ freq, time, dur }) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();

      osc.type = 'sine';
      osc.frequency.setValueAtTime(freq, time);

      gain.gain.setValueAtTime(0.14, time);
      gain.gain.exponentialRampToValueAtTime(0.001, time + dur);

      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.start(time);
      osc.stop(time + dur);
    });
  } catch {
    // Ignore autoplay restriction or unsupported AudioContext
  }
}

export function useInboxNotifications(
  options: UseInboxNotificationsOptions = {}
): UseInboxNotificationsResult {
  const { unreadOrWaitingCount = 0, onSelectConversation } = options;

  // Sound preference state
  const [soundEnabled, setSoundEnabled] = useState<boolean>(() => {
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        const saved = window.localStorage.getItem(SOUND_STORAGE_KEY);
        return saved !== null ? saved === 'true' : true;
      }
    } catch {}
    return true;
  });

  // Desktop notification permission & preference state
  const [permissionStatus, setPermissionStatus] = useState<NotificationPermission | 'unsupported'>(() => {
    if (typeof window !== 'undefined' && typeof window.Notification !== 'undefined') {
      return window.Notification.permission;
    }
    return 'unsupported';
  });

  const [desktopEnabled, setDesktopEnabled] = useState<boolean>(() => {
    try {
      if (typeof window !== 'undefined' && typeof window.Notification !== 'undefined') {
        const saved = window.localStorage?.getItem(DESKTOP_STORAGE_KEY);
        return window.Notification.permission === 'granted' && saved !== 'false';
      }
    } catch {}
    return false;
  });

  // AudioContext ref and Anti-Spam throttle ref (1500ms cooldown)
  const audioCtxRef = useRef<AudioContext | null>(null);
  const lastSoundTimeRef = useRef<number>(0);

  const checkSoundThrottle = useCallback((): boolean => {
    const now = Date.now();
    if (now - lastSoundTimeRef.current < 1500) {
      return false;
    }
    lastSoundTimeRef.current = now;
    return true;
  }, []);

  const requestDesktopPermission = useCallback(async (): Promise<boolean> => {
    try {
      if (typeof window === 'undefined' || typeof window.Notification === 'undefined') {
        setPermissionStatus('unsupported');
        return false;
      }
      const perm = await window.Notification.requestPermission();
      setPermissionStatus(perm);
      const isGranted = perm === 'granted';
      setDesktopEnabled(isGranted);
      try {
        window.localStorage?.setItem(DESKTOP_STORAGE_KEY, isGranted ? 'true' : 'false');
      } catch {}
      return isGranted;
    } catch {
      return false;
    }
  }, []);

  const toggleSound = useCallback(() => {
    setSoundEnabled((prev) => {
      const next = !prev;
      try {
        window.localStorage?.setItem(SOUND_STORAGE_KEY, String(next));
      } catch {}

      // If user enables sounds and desktop permission hasn't been asked yet, request it safely
      if (
        next &&
        typeof window !== 'undefined' &&
        typeof window.Notification !== 'undefined' &&
        window.Notification.permission === 'default'
      ) {
        requestDesktopPermission().catch(() => {});
      }

      return next;
    });
  }, [requestDesktopPermission]);

  const playMessageSound = useCallback(() => {
    if (!soundEnabled) return;
    if (!checkSoundThrottle()) return;
    if (!audioCtxRef.current) {
      audioCtxRef.current = getAudioContext();
    }
    playMessageTone(audioCtxRef.current);
  }, [soundEnabled, checkSoundThrottle]);

  const playAssignmentSound = useCallback(() => {
    if (!soundEnabled) return;
    if (!checkSoundThrottle()) return;
    if (!audioCtxRef.current) {
      audioCtxRef.current = getAudioContext();
    }
    playAssignmentTone(audioCtxRef.current);
  }, [soundEnabled, checkSoundThrottle]);

  const notifyDesktop = useCallback(
    (notifOptions: DesktopNotificationOptions) => {
      try {
        if (typeof window === 'undefined' || typeof window.Notification === 'undefined') {
          return;
        }
        if (window.Notification.permission !== 'granted') {
          return;
        }

        const { title, body, conversationId, onClick, icon = '/brand/intera-mark.svg' } = notifOptions;

        const NotificationClass = window.Notification;
        const notification = new NotificationClass(title, {
          body,
          tag: conversationId || `conexa_${Date.now()}`,
          icon,
        });

        notification.onclick = () => {
          try {
            window.focus();
          } catch {}
          if (onClick) {
            onClick();
          } else if (conversationId && onSelectConversation) {
            onSelectConversation(conversationId);
          }
          try {
            notification.close();
          } catch {}
        };
      } catch {
        // Ignore notification creation errors safely
      }
    },
    [onSelectConversation]
  );

  // Dynamic tab title update
  useEffect(() => {
    if (typeof document === 'undefined') return;

    if (unreadOrWaitingCount > 0) {
      document.title = `(${unreadOrWaitingCount}) Intera — Bandeja de Entrada`;
    } else {
      document.title = 'Intera';
    }

    return () => {
      if (typeof document !== 'undefined') {
        document.title = 'Intera';
      }
    };
  }, [unreadOrWaitingCount]);

  return {
    soundEnabled,
    desktopEnabled,
    permissionStatus,
    toggleSound,
    requestDesktopPermission,
    playMessageSound,
    playAssignmentSound,
    notifyDesktop,
  };
}
