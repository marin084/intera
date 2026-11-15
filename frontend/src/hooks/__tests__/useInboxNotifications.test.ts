import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import {
  useInboxNotifications,
  SOUND_STORAGE_KEY,
  DESKTOP_STORAGE_KEY,
  playMessageTone,
  playAssignmentTone,
} from '../useInboxNotifications';

describe('useInboxNotifications', () => {
  let originalNotification: any;
  let originalAudioContext: any;
  const storage: Record<string, string> = {};

  const mockLocalStorage = {
    getItem: (key: string) => storage[key] ?? null,
    setItem: (key: string, val: string) => {
      storage[key] = String(val);
    },
    removeItem: (key: string) => {
      delete storage[key];
    },
    clear: () => {
      Object.keys(storage).forEach((k) => delete storage[k]);
    },
  };

  Object.defineProperty(window, 'localStorage', {
    value: mockLocalStorage,
    writable: true,
    configurable: true,
  });

  beforeEach(() => {
    mockLocalStorage.clear();

    originalNotification = (window as any).Notification;
    originalAudioContext = (window as any).AudioContext;

    class DefaultMockNotification {
      static permission: NotificationPermission = 'default';
      static requestPermission = vi.fn().mockResolvedValue('default');
    }
    (window as any).Notification = DefaultMockNotification;

    document.title = 'Conexa';
  });

  afterEach(() => {
    (window as any).Notification = originalNotification;
    (window as any).AudioContext = originalAudioContext;
    document.title = 'Conexa';
    vi.restoreAllMocks();
  });

  it('initializes with sound enabled by default and reads persisted preference from localStorage', () => {
    const { result } = renderHook(() => useInboxNotifications());
    expect(result.current.soundEnabled).toBe(true);

    // If localStorage has false
    storage[SOUND_STORAGE_KEY] = 'false';
    const { result: resultMuted } = renderHook(() => useInboxNotifications());
    expect(resultMuted.current.soundEnabled).toBe(false);
  });

  it('toggles sound preference and persists to localStorage', async () => {
    const { result } = renderHook(() => useInboxNotifications());
    expect(result.current.soundEnabled).toBe(true);

    await act(async () => {
      result.current.toggleSound();
    });

    expect(result.current.soundEnabled).toBe(false);
    expect(storage[SOUND_STORAGE_KEY]).toBe('false');

    await act(async () => {
      result.current.toggleSound();
    });

    expect(result.current.soundEnabled).toBe(true);
    expect(storage[SOUND_STORAGE_KEY]).toBe('true');
  });

  it('updates document.title dynamically when unreadOrWaitingCount > 0 and restores when 0 or unmounted', () => {
    const { rerender, unmount } = renderHook(
      ({ count }) => useInboxNotifications({ unreadOrWaitingCount: count }),
      { initialProps: { count: 0 } }
    );

    expect(document.title).toBe('Conexa');

    rerender({ count: 3 });
    expect(document.title).toBe('(3) Conexa — Bandeja de Entrada');

    rerender({ count: 12 });
    expect(document.title).toBe('(12) Conexa — Bandeja de Entrada');

    rerender({ count: 0 });
    expect(document.title).toBe('Conexa');

    rerender({ count: 5 });
    expect(document.title).toBe('(5) Conexa — Bandeja de Entrada');

    unmount();
    expect(document.title).toBe('Conexa');
  });

  it('requests desktop notification permission safely and updates state', async () => {
    const requestPermissionMock = vi.fn().mockResolvedValue('granted');
    class MockNotification {
      static permission: NotificationPermission = 'default';
      static requestPermission = requestPermissionMock;
    }
    (window as any).Notification = MockNotification;

    const { result } = renderHook(() => useInboxNotifications());

    let granted = false;
    await act(async () => {
      granted = await result.current.requestDesktopPermission();
    });

    expect(granted).toBe(true);
    expect(result.current.desktopEnabled).toBe(true);
    expect(storage[DESKTOP_STORAGE_KEY]).toBe('true');
  });

  it('dispatches desktop notification when permission is granted and triggers onClick on click', () => {
    let capturedOptions: any = null;
    let onclickHandler: any = null;
    const closeMock = vi.fn();
    const focusMock = vi.fn();
    (window as any).focus = focusMock;

    class MockNotification {
      static permission: NotificationPermission = 'granted';
      static requestPermission = vi.fn().mockResolvedValue('granted');

      constructor(title: string, options: any) {
        capturedOptions = { title, ...options };
        Object.defineProperty(this, 'onclick', {
          set: (fn) => {
            onclickHandler = fn;
          },
          get: () => onclickHandler,
        });
      }

      close = closeMock;
    }

    (window as any).Notification = MockNotification;

    const onSelectConversation = vi.fn();
    const { result } = renderHook(() =>
      useInboxNotifications({ onSelectConversation })
    );

    act(() => {
      result.current.notifyDesktop({
        title: 'Mensaje de Carlos',
        body: 'Hola agente',
        conversationId: 'conv_123',
      });
    });

    expect(capturedOptions).not.toBeNull();
    expect(capturedOptions.title).toBe('Mensaje de Carlos');
    expect(capturedOptions.body).toBe('Hola agente');
    expect(capturedOptions.tag).toBe('conv_123');

    // Simulate click on notification
    act(() => {
      if (onclickHandler) onclickHandler();
    });

    expect(focusMock).toHaveBeenCalled();
    expect(onSelectConversation).toHaveBeenCalledWith('conv_123');
    expect(closeMock).toHaveBeenCalled();
  });

  it('plays message and assignment tones with Web Audio API and throttles repeated calls', () => {
    const mockOscillator = {
      type: '',
      frequency: {
        setValueAtTime: vi.fn(),
        exponentialRampToValueAtTime: vi.fn(),
      },
      connect: vi.fn(),
      start: vi.fn(),
      stop: vi.fn(),
    };

    const mockGain = {
      gain: {
        setValueAtTime: vi.fn(),
        exponentialRampToValueAtTime: vi.fn(),
      },
      connect: vi.fn(),
    };

    const mockContext = {
      currentTime: 10,
      state: 'running',
      createOscillator: vi.fn(() => mockOscillator),
      createGain: vi.fn(() => mockGain),
      destination: {},
      resume: vi.fn().mockResolvedValue(undefined),
    };

    class MockAudioCtx {
      constructor() {
        return mockContext as any;
      }
    }

    (window as any).AudioContext = MockAudioCtx;

    const { result } = renderHook(() => useInboxNotifications());

    // First call plays message sound
    act(() => {
      result.current.playMessageSound();
    });

    expect(mockContext.createOscillator).toHaveBeenCalled();
    expect(mockOscillator.start).toHaveBeenCalled();

    // Call immediately again: throttled by 1500ms cooldown
    const initialStartCalls = mockOscillator.start.mock.calls.length;
    act(() => {
      result.current.playMessageSound();
    });
    expect(mockOscillator.start.mock.calls.length).toBe(initialStartCalls);

    // Call standalone helpers directly without throttle
    playMessageTone(mockContext as any);
    playAssignmentTone(mockContext as any);
    expect(mockContext.createOscillator).toHaveBeenCalled();
  });

  it('degrades gracefully when Web Audio API or Notification is unavailable', () => {
    delete (window as any).AudioContext;
    delete (window as any).Notification;

    const { result } = renderHook(() => useInboxNotifications());

    expect(() => {
      result.current.playMessageSound();
      result.current.playAssignmentSound();
      result.current.notifyDesktop({
        title: 'Prueba',
        body: 'Sin crash',
      });
    }).not.toThrow();

    expect(result.current.permissionStatus).toBe('unsupported');
  });
});
