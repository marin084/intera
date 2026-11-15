import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { AudioMessagePlayer } from '../AudioMessagePlayer';

describe('AudioMessagePlayer Component', () => {
  const audioUrl = 'http://localhost/storage/attachments/whatsapp/test-audio.ogg';

  beforeEach(() => {
    window.HTMLMediaElement.prototype.play = vi.fn().mockImplementation(function (this: HTMLAudioElement) {
      return Promise.resolve();
    });
    window.HTMLMediaElement.prototype.pause = vi.fn();
  });

  it('renders audio player with initial controls, 1x speed, and download button', () => {
    render(
      <AudioMessagePlayer
        url={audioUrl}
        fileName="mi-grabacion.ogg"
        sizeBytes={1024 * 50}
        isOutbound={false}
      />
    );

    expect(screen.getByTestId('audio-message-player')).toBeInTheDocument();
    expect(screen.getByText('mi-grabacion.ogg')).toBeInTheDocument();
    expect(screen.getByText('50.0 KB')).toBeInTheDocument();
    expect(screen.getByTestId('audio-play-pause-btn')).toBeInTheDocument();
    expect(screen.getByTestId('audio-progress-bar')).toBeInTheDocument();
    expect(screen.getByTestId('audio-speed-btn')).toHaveTextContent('1x');
    expect(screen.getByTestId('audio-current-time')).toHaveTextContent('0:00');
    expect(screen.getByTestId('audio-duration-time')).toHaveTextContent('0:00');

    const downloadLink = screen.getByTestId('audio-download-btn');
    expect(downloadLink).toHaveAttribute('href', audioUrl);
    expect(downloadLink).toHaveAttribute('download', 'mi-grabacion.ogg');
  });

  it('displays voice note label when isVoiceNote is true and fileName is not provided', () => {
    render(
      <AudioMessagePlayer
        url={audioUrl}
        isVoiceNote={true}
        isOutbound={true}
      />
    );

    expect(screen.getByText('Nota de voz')).toBeInTheDocument();
  });

  it('toggles play and pause on click', async () => {
    render(<AudioMessagePlayer url={audioUrl} />);

    const playBtn = screen.getByTestId('audio-play-pause-btn');

    await act(async () => {
      fireEvent.click(playBtn);
    });

    expect(window.HTMLMediaElement.prototype.play).toHaveBeenCalled();
    expect(playBtn).toHaveAttribute('title', 'Pausar');

    await act(async () => {
      fireEvent.click(playBtn);
    });

    expect(window.HTMLMediaElement.prototype.pause).toHaveBeenCalled();
    expect(playBtn).toHaveAttribute('title', 'Reproducir');
  });

  it('cycles playback speed between 1x, 1.5x, and 2x', () => {
    render(<AudioMessagePlayer url={audioUrl} />);

    const speedBtn = screen.getByTestId('audio-speed-btn');
    expect(speedBtn).toHaveTextContent('1x');

    // Click 1 -> 1.5x
    fireEvent.click(speedBtn);
    expect(speedBtn).toHaveTextContent('1.5x');

    // Click 2 -> 2x
    fireEvent.click(speedBtn);
    expect(speedBtn).toHaveTextContent('2x');

    // Click 3 -> 1x
    fireEvent.click(speedBtn);
    expect(speedBtn).toHaveTextContent('1x');
  });

  it('handles progress bar seek and updates currentTime', () => {
    render(<AudioMessagePlayer url={audioUrl} />);

    const progressBar = screen.getByTestId('audio-progress-bar');
    fireEvent.change(progressBar, { target: { value: '25.5' } });

    expect(screen.getByTestId('audio-current-time')).toHaveTextContent('0:25');
  });
});
