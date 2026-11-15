import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useVoiceRecorder } from '../useVoiceRecorder';

describe('useVoiceRecorder', () => {
  let originalMediaDevices: any;
  let originalMediaRecorder: any;

  beforeEach(() => {
    originalMediaDevices = navigator.mediaDevices;
    originalMediaRecorder = (window as any).MediaRecorder;
  });

  afterEach(() => {
    Object.defineProperty(navigator, 'mediaDevices', {
      value: originalMediaDevices,
      writable: true,
      configurable: true,
    });
    (window as any).MediaRecorder = originalMediaRecorder;
    vi.restoreAllMocks();
  });

  it('detects unsupported environment gracefully when mediaDevices or MediaRecorder is missing', async () => {
    Object.defineProperty(navigator, 'mediaDevices', {
      value: undefined,
      writable: true,
      configurable: true,
    });
    (window as any).MediaRecorder = undefined;

    const { result } = renderHook(() => useVoiceRecorder());
    expect(result.current.isSupported).toBe(false);

    let started = false;
    await act(async () => {
      started = await result.current.startRecording();
    });

    expect(started).toBe(false);
    expect(result.current.errorMessage).toContain('no soporta');
  });

  it('handles microphone permission denied gracefully', async () => {
    Object.defineProperty(navigator, 'mediaDevices', {
      value: {
        getUserMedia: vi.fn().mockRejectedValue(new Error('Permission denied')),
      },
      writable: true,
      configurable: true,
    });

    (window as any).MediaRecorder = class MockMediaRecorder {
      static isTypeSupported = vi.fn().mockReturnValue(true);
    };

    const { result } = renderHook(() => useVoiceRecorder());
    expect(result.current.isSupported).toBe(true);

    let started = true;
    await act(async () => {
      started = await result.current.startRecording();
    });

    expect(started).toBe(false);
    expect(result.current.isRecording).toBe(false);
    expect(result.current.errorMessage).toContain('No se pudo acceder al micrófono');
  });

  it('starts, records, and stops recording to generate audio file', async () => {
    const mockTracks = [{ stop: vi.fn() }];
    const mockStream = {
      getTracks: vi.fn().mockReturnValue(mockTracks),
    };

    Object.defineProperty(navigator, 'mediaDevices', {
      value: {
        getUserMedia: vi.fn().mockResolvedValue(mockStream),
      },
      writable: true,
      configurable: true,
    });

    class MockMediaRecorder {
      state = 'inactive';
      mimeType = 'audio/ogg';
      ondataavailable: ((e: any) => void) | null = null;
      onstop: (() => void) | null = null;
      static isTypeSupported = vi.fn().mockReturnValue(true);

      start = vi.fn(() => {
        this.state = 'recording';
      });

      stop = vi.fn(() => {
        this.state = 'inactive';
        if (this.ondataavailable) {
          this.ondataavailable({ data: new Blob(['audio-data'], { type: 'audio/ogg' }) });
        }
        if (this.onstop) {
          this.onstop();
        }
      });
    }

    (window as any).MediaRecorder = MockMediaRecorder;

    const { result } = renderHook(() => useVoiceRecorder());

    let started = false;
    await act(async () => {
      started = await result.current.startRecording();
    });

    expect(started).toBe(true);
    expect(result.current.isRecording).toBe(true);

    let recordedFile: any = null;
    await act(async () => {
      recordedFile = await result.current.stopRecording();
    });

    expect(result.current.isRecording).toBe(false);
    expect(recordedFile).not.toBeNull();
    expect(recordedFile?.name).toContain('voice-note');
    expect(mockTracks[0].stop).toHaveBeenCalled();
  });

  it('cancels recording and stops all media tracks without producing a file', async () => {
    const mockTracks = [{ stop: vi.fn() }];
    const mockStream = {
      getTracks: vi.fn().mockReturnValue(mockTracks),
    };

    Object.defineProperty(navigator, 'mediaDevices', {
      value: {
        getUserMedia: vi.fn().mockResolvedValue(mockStream),
      },
      writable: true,
      configurable: true,
    });

    class MockMediaRecorder {
      state = 'inactive';
      mimeType = 'audio/ogg';
      ondataavailable: ((e: any) => void) | null = null;
      onstop: (() => void) | null = null;
      static isTypeSupported = vi.fn().mockReturnValue(true);

      start = vi.fn(() => {
        this.state = 'recording';
      });

      stop = vi.fn(() => {
        this.state = 'inactive';
      });
    }

    (window as any).MediaRecorder = MockMediaRecorder;

    const { result } = renderHook(() => useVoiceRecorder());

    await act(async () => {
      await result.current.startRecording();
    });

    expect(result.current.isRecording).toBe(true);

    act(() => {
      result.current.cancelRecording();
    });

    expect(result.current.isRecording).toBe(false);
    expect(mockTracks[0].stop).toHaveBeenCalled();
  });
});
