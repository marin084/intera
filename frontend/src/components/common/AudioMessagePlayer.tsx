import React, { useState, useRef, useEffect } from 'react';
import { Play, Pause, Download, Volume2, Mic } from 'lucide-react';

export interface AudioMessagePlayerProps {
  url: string;
  fileName?: string;
  sizeBytes?: number;
  isOutbound?: boolean;
  isVoiceNote?: boolean;
  className?: string;
}

export const AudioMessagePlayer: React.FC<AudioMessagePlayerProps> = ({
  url,
  fileName,
  sizeBytes,
  isOutbound = false,
  isVoiceNote = false,
  className = '',
}) => {
  const audioRef = useRef<HTMLAudioElement | null>(null);
  const [isPlaying, setIsPlaying] = useState<boolean>(false);
  const [currentTime, setCurrentTime] = useState<number>(0);
  const [duration, setDuration] = useState<number>(0);
  const [playbackSpeed, setPlaybackSpeed] = useState<number>(1);
  const [hasError, setHasError] = useState<boolean>(false);

  useEffect(() => {
    const audio = audioRef.current;
    if (!audio) return;

    const handleTimeUpdate = () => {
      setCurrentTime(audio.currentTime);
    };

    const handleLoadedMetadata = () => {
      if (audio.duration && Number.isFinite(audio.duration)) {
        setDuration(audio.duration);
      }
    };

    const handleDurationChange = () => {
      if (audio.duration && Number.isFinite(audio.duration)) {
        setDuration(audio.duration);
      }
    };

    const handleEnded = () => {
      setIsPlaying(false);
      setCurrentTime(0);
    };

    const handleError = () => {
      setHasError(true);
      setIsPlaying(false);
    };

    audio.addEventListener('timeupdate', handleTimeUpdate);
    audio.addEventListener('loadedmetadata', handleLoadedMetadata);
    audio.addEventListener('durationchange', handleDurationChange);
    audio.addEventListener('ended', handleEnded);
    audio.addEventListener('error', handleError);

    return () => {
      audio.pause();
      audio.removeEventListener('timeupdate', handleTimeUpdate);
      audio.removeEventListener('loadedmetadata', handleLoadedMetadata);
      audio.removeEventListener('durationchange', handleDurationChange);
      audio.removeEventListener('ended', handleEnded);
      audio.removeEventListener('error', handleError);
    };
  }, [url]);

  const togglePlayPause = () => {
    const audio = audioRef.current;
    if (!audio) return;

    if (isPlaying) {
      audio.pause();
      setIsPlaying(false);
    } else {
      audio.playbackRate = playbackSpeed;
      audio.play().then(() => {
        setIsPlaying(true);
      }).catch((e) => {
        console.warn('[AudioMessagePlayer] Error reproduciendo audio:', e);
        setIsPlaying(false);
      });
    }
  };

  const handleSeek = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newTime = parseFloat(e.target.value);
    setCurrentTime(newTime);
    if (audioRef.current) {
      audioRef.current.currentTime = newTime;
    }
  };

  const cycleSpeed = () => {
    const speeds = [1, 1.5, 2];
    const currentIndex = speeds.indexOf(playbackSpeed);
    const nextSpeed = speeds[(currentIndex + 1) % speeds.length];
    setPlaybackSpeed(nextSpeed);

    if (audioRef.current) {
      audioRef.current.playbackRate = nextSpeed;
    }
  };

  const formatTime = (secs: number): string => {
    if (!Number.isFinite(secs) || isNaN(secs) || secs < 0) return '0:00';
    const m = Math.floor(secs / 60);
    const s = Math.floor(secs % 60);
    return `${m}:${s < 10 ? '0' : ''}${s}`;
  };

  const formatFileSize = (bytes?: number): string => {
    if (!bytes || bytes <= 0) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  };

  const displayName = fileName || (isVoiceNote ? 'Nota de voz' : 'Audio');
  const maxRange = duration > 0 ? duration : (currentTime > 0 ? currentTime : 100);

  return (
    <div
      className={`audio-message-player ${className}`}
      data-testid="audio-message-player"
      style={{
        display: 'flex',
        flexDirection: 'column',
        gap: '0.4rem',
        padding: '0.55rem 0.75rem',
        borderRadius: '12px',
        minWidth: '240px',
        maxWidth: '320px',
        background: isOutbound ? 'rgba(255, 255, 255, 0.16)' : '#eaf1ee',
        border: isOutbound ? '1px solid rgba(255, 255, 255, 0.25)' : '1px solid #d9e3de',
        color: isOutbound ? '#ffffff' : '#35564f',
        boxSizing: 'border-box',
      }}
    >
      <audio ref={audioRef} src={url} preload="metadata" />

      {/* Header Info */}
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          fontSize: '0.75rem',
          opacity: 0.9,
          gap: '0.5rem',
        }}
      >
        <span style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontWeight: 600 }}>
          {isVoiceNote ? <Mic size={13} style={{ flexShrink: 0 }} /> : <Volume2 size={13} style={{ flexShrink: 0 }} />}
          <span style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{displayName}</span>
        </span>
        {sizeBytes ? (
          <span style={{ fontSize: '0.7rem', opacity: 0.75, flexShrink: 0 }}>
            {formatFileSize(sizeBytes)}
          </span>
        ) : null}
      </div>

      {/* Player Controls */}
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
        {/* Play/Pause Button */}
        <button
          type="button"
          data-testid="audio-play-pause-btn"
          onClick={togglePlayPause}
          disabled={hasError}
          title={isPlaying ? 'Pausar' : 'Reproducir'}
          aria-label={isPlaying ? 'Pausar audio' : 'Reproducir audio'}
          style={{
            width: '34px',
            height: '34px',
            borderRadius: '50%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            border: 'none',
            background: isOutbound ? '#ffffff' : '#5d8f82',
            color: isOutbound ? '#5d8f82' : '#ffffff',
            cursor: hasError ? 'not-allowed' : 'pointer',
            opacity: hasError ? 0.5 : 1,
            flexShrink: 0,
            transition: 'transform 0.1s ease',
          }}
        >
          {isPlaying ? <Pause size={16} fill="currentColor" /> : <Play size={16} fill="currentColor" style={{ marginLeft: '2px' }} />}
        </button>

        {/* Progress Bar & Timestamps */}
        <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: '0.2rem' }}>
          <input
            type="range"
            data-testid="audio-progress-bar"
            min={0}
            max={maxRange}
            step="0.01"
            value={currentTime}
            onChange={handleSeek}
            disabled={hasError}
            aria-label="Posición de reproducción"
            style={{
              width: '100%',
              cursor: hasError ? 'not-allowed' : 'pointer',
              accentColor: isOutbound ? '#ffffff' : '#5d8f82',
              height: '4px',
            }}
          />
          <div
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              fontSize: '0.68rem',
              opacity: 0.85,
              fontVariantNumeric: 'tabular-nums',
            }}
          >
            <span data-testid="audio-current-time">{formatTime(currentTime)}</span>
            <span data-testid="audio-duration-time">{formatTime(duration)}</span>
          </div>
        </div>

        {/* Speed Multiplier Button (1x -> 1.5x -> 2x) */}
        <button
          type="button"
          data-testid="audio-speed-btn"
          onClick={cycleSpeed}
          title="Velocidad de reproducción"
          aria-label={`Velocidad de reproducción ${playbackSpeed}x`}
          style={{
            fontSize: '0.72rem',
            fontWeight: 700,
            padding: '2px 6px',
            borderRadius: '6px',
            border: isOutbound ? '1px solid rgba(255, 255, 255, 0.4)' : '1px solid #d9e3de',
            background: isOutbound ? 'rgba(255, 255, 255, 0.2)' : '#ffffff',
            color: isOutbound ? '#ffffff' : '#56736b',
            cursor: 'pointer',
            flexShrink: 0,
            minWidth: '32px',
            textAlign: 'center',
          }}
        >
          {playbackSpeed}x
        </button>

        {/* Download Backup Button */}
        <a
          href={url}
          target="_blank"
          rel="noopener noreferrer"
          download={fileName || 'audio.ogg'}
          data-testid="audio-download-btn"
          title="Descargar audio"
          aria-label="Descargar archivo de audio"
          style={{
            color: isOutbound ? '#ffffff' : '#61726d',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            padding: '4px',
            borderRadius: '4px',
            flexShrink: 0,
            opacity: 0.85,
          }}
        >
          <Download size={15} />
        </a>
      </div>
    </div>
  );
};
