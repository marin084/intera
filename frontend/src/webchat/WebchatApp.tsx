import React, { useState, useEffect, useRef, useCallback } from 'react';
import { Send, X, Bot, User, AlertCircle, RefreshCw, Paperclip, FileText, Download, ImageOff, Loader2, Mic, Trash2 } from 'lucide-react';
import { createEcho } from '../services/echo';
import { resolveMediaUrl } from '../utils/media';
import { LinkifiedText } from '../components/common/LinkifiedText';
import { AudioMessagePlayer } from '../components/common/AudioMessagePlayer';
import { SessionDivider } from '../components/common/SessionDivider';
import { useVoiceRecorder } from '../hooks/useVoiceRecorder';
import type {
  WebchatSettings,
  WebchatMessage,
  WebchatConversation,
  WebchatSessionData,
  InitMessagePayload,
} from './types';
import { sortAndDeduplicateWebchatMessages } from './utils';

interface WebchatAppProps {
  initialChannelKey?: string;
}

function generateUuid(): string {
  if (typeof crypto !== 'undefined') {
    if (typeof crypto.randomUUID === 'function') {
      try {
        return crypto.randomUUID();
      } catch {
        // Fallback for non-secure contexts
      }
    }
    if (typeof crypto.getRandomValues === 'function') {
      try {
        const buffer = new Uint8Array(16);
        crypto.getRandomValues(buffer);
        buffer[6] = (buffer[6] & 0x0f) | 0x40;
        buffer[8] = (buffer[8] & 0x3f) | 0x80;
        const hex = Array.from(buffer, (b) => b.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
      } catch {
        // Fallback to pseudo-random
      }
    }
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

interface InteractiveOption {
  id: string;
  title: string;
}

const WebchatImageAttachment: React.FC<{
  url?: string | null;
  fileName?: string | null;
  isVisitor: boolean;
  onLoad?: () => void;
}> = ({ url, fileName, isVisitor, onLoad }) => {
  const [isLoading, setIsLoading] = useState(true);
  const [hasError, setHasError] = useState(false);

  const resolvedUrl = resolveMediaUrl(url);

  if (!resolvedUrl || hasError) {
    return (
      <div
        style={{
          minHeight: '85px',
          minWidth: '170px',
          maxWidth: '100%',
          backgroundColor: isVisitor ? 'rgba(255, 255, 255, 0.15)' : '#f4f7f5',
          border: isVisitor ? '1px dashed rgba(255,255,255,0.4)' : '1px dashed #d9e3de',
          borderRadius: '8px',
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          padding: '10px',
          gap: '4px',
          color: isVisitor ? '#ffffff' : '#61726d',
          textAlign: 'center',
        }}
        data-testid="webchat-image-error"
      >
        <ImageOff size={20} color={isVisitor ? '#ffffff' : '#80918c'} />
        <span style={{ fontSize: '11px', fontWeight: 500 }}>No se pudo cargar la imagen</span>
      </div>
    );
  }

  return (
    <div
      style={{
        position: 'relative',
        minHeight: isLoading ? '110px' : 'auto',
        minWidth: isLoading ? '170px' : 'auto',
        backgroundColor: isLoading ? (isVisitor ? 'rgba(255, 255, 255, 0.15)' : '#eaf1ee') : 'transparent',
        borderRadius: '8px',
        overflow: 'hidden',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
      }}
    >
      {isLoading && (
        <div
          style={{
            position: 'absolute',
            inset: 0,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '6px',
            color: isVisitor ? '#ffffff' : '#61726d',
            fontSize: '11px',
          }}
        >
          <Loader2 size={16} className="animate-spin" />
          <span>Cargando imagen...</span>
        </div>
      )}
      <a
        href={resolvedUrl}
        target="_blank"
        rel="noopener noreferrer"
        style={{ display: 'block', width: '100%', borderRadius: '8px', overflow: 'hidden' }}
      >
        <img
          src={resolvedUrl}
          alt={fileName || 'Imagen adjunta'}
          onLoad={() => {
            setIsLoading(false);
            onLoad?.();
          }}
          onError={() => {
            setIsLoading(false);
            setHasError(true);
          }}
          style={{
            maxWidth: '100%',
            maxHeight: '240px',
            objectFit: 'cover',
            borderRadius: '8px',
            display: isLoading ? 'none' : 'block',
            cursor: 'pointer',
            width: '100%',
          }}
        />
      </a>
    </div>
  );
};

function extractInteractiveOptions(msg: WebchatMessage): InteractiveOption[] {
  const rawList =
    msg.payload?.options ||
    msg.payload?.buttons ||
    msg.metadata?.options ||
    msg.metadata?.choices;

  if (!Array.isArray(rawList) || rawList.length === 0) {
    return [];
  }

  return rawList
    .map((item, idx) => {
      if (typeof item === 'string') {
        const text = item.trim();
        return text ? { id: String(idx + 1), title: text } : null;
      }
      if (typeof item === 'number') {
        return { id: String(item), title: String(item) };
      }
      if (item && typeof item === 'object') {
        const isHidden = (item as any).is_hidden === true || (item as any).visible === false;
        if (isHidden) {
          return null;
        }
        const title = (item.title || item.text || item.label || item.value || String(item.id || idx + 1)).trim();
        const id = String(item.id ?? idx + 1);
        return title ? { id, title } : null;
      }
      return null;
    })
    .filter((opt): opt is InteractiveOption => opt !== null);
}

export const WebchatApp: React.FC<WebchatAppProps> = ({ initialChannelKey }) => {
  const [publicKey, setPublicKey] = useState<string | null>(() => {
    if (initialChannelKey) return initialChannelKey;
    if (typeof window !== 'undefined') {
      const p = new URLSearchParams(window.location.search);
      return p.get('channel') || null;
    }
    return null;
  });
  const [settings, setSettings] = useState<WebchatSettings>({
    display_name: 'Soporte',
    accent_color: '#5d8f82',
    launcher_position: 'bottom-right',
  });
  const [embedToken, setEmbedToken] = useState<string | null>(null);
  const [hostOrigin, setHostOrigin] = useState<string>('');

  const [session, setSession] = useState<WebchatSessionData | null>(null);
  const [conversation, setConversation] = useState<WebchatConversation | null>(null);
  const [messages, setMessages] = useState<WebchatMessage[]>([]);
  const [inputText, setInputText] = useState('');
  const [isSending, setIsSending] = useState(false);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [filePreviewUrl, setFilePreviewUrl] = useState<string | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const formatFileSize = (bytes: number): string => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  };

  const handleClearFile = useCallback(() => {
    setSelectedFile(null);
    if (filePreviewUrl) {
      URL.revokeObjectURL(filePreviewUrl);
      setFilePreviewUrl(null);
    }
    setFileError(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  }, [filePreviewUrl]);

  const voiceRecorder = useVoiceRecorder();

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    setFileError(null);
    const file = e.target.files?.[0];
    if (!file) return;

    const lowerName = file.name.toLowerCase();
    const isImage = file.type.startsWith('image/');
    const isAudio = file.type.startsWith('audio/') || file.type === 'application/ogg' || file.type === 'video/webm' || lowerName.endsWith('.ogg') || lowerName.endsWith('.opus') || lowerName.endsWith('.mp3') || lowerName.endsWith('.m4a') || lowerName.endsWith('.aac') || lowerName.endsWith('.wav') || lowerName.endsWith('.webm') || lowerName.endsWith('.amr');
    const maxBytes = isAudio ? 16 * 1024 * 1024 : (isImage ? 5 * 1024 * 1024 : 10 * 1024 * 1024);

    // Security check: reject svg, html, php, exe
    if (file.type.includes('svg') || file.type.includes('html') || lowerName.endsWith('.svg') || lowerName.endsWith('.html') || lowerName.endsWith('.php') || lowerName.endsWith('.exe')) {
      setFileError('Tipo de archivo no permitido por razones de seguridad.');
      if (fileInputRef.current) fileInputRef.current.value = '';
      return;
    }

    const allowedMimes = [
      'image/jpeg', 'image/png', 'image/webp', 'image/gif',
      'audio/ogg', 'application/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/wav', 'audio/x-wav', 'audio/webm', 'video/webm', 'audio/amr',
      'application/pdf', 'text/plain', 'text/csv',
      'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];

    if (!allowedMimes.includes(file.type)) {
      setFileError('Tipo no soportado. Formatos: JPG, PNG, WEBP, GIF (máx 5MB), MP3, OGG, WAV, M4A, AAC, WEBM (máx 16MB), PDF, TXT, CSV, DOC, DOCX, XLS, XLSX (máx 10MB).');
      if (fileInputRef.current) fileInputRef.current.value = '';
      return;
    }

    if (file.size > maxBytes) {
      setFileError(`El archivo supera el tamaño máximo permitido (${isAudio ? '16 MB' : isImage ? '5 MB' : '10 MB'}).`);
      if (fileInputRef.current) fileInputRef.current.value = '';
      return;
    }

    setSelectedFile(file);
    if (isImage) {
      setFilePreviewUrl(URL.createObjectURL(file));
    } else {
      setFilePreviewUrl(null);
    }
  };
  const hasInitialChannel = Boolean(
    initialChannelKey ||
      (typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('channel'))
  );

  const [connectionStatus, setConnectionStatus] = useState<
    'init' | 'loading' | 'connected' | 'reconnecting' | 'disconnected' | 'error' | 'unavailable'
  >(hasInitialChannel ? 'init' : 'error');
  const [realtimeConnected, setRealtimeConnected] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(
    hasInitialChannel
      ? null
      : 'Error de Configuración: Falta el identificador del canal (data-channel o ?channel=).'
  );
  const [hasMoreMessages, setHasMoreMessages] = useState<boolean>(false);
  const [oldestMessageId, setOldestMessageId] = useState<string | null>(null);
  const [loadingMore, setLoadingMore] = useState<boolean>(false);

  const messagesEndRef = useRef<HTMLDivElement>(null);
  const messagesContainerRef = useRef<HTMLDivElement>(null);
  const messagesContentRef = useRef<HTMLDivElement>(null);
  const userScrolledUpRef = useRef<boolean>(false);
  const prevConversationIdRef = useRef<string | null>(null);
  const echoRef = useRef<any>(null);
  const activeConversationIdRef = useRef<string | null>(null);
  useEffect(() => {
    activeConversationIdRef.current = conversation?.id || null;
  }, [conversation?.id]);

  // 1. Listen for postMessage handshake from host loader
  useEffect(() => {
    const handleMessage = (event: MessageEvent) => {
      const data = event.data;
      if (!data || typeof data !== 'object') return;

      if (data.type === 'conexa:init') {
        const payload = data as InitMessagePayload;
        setPublicKey(payload.publicKey);
        setEmbedToken(payload.embedToken);
        setHostOrigin(payload.hostOrigin || event.origin);
        setConnectionStatus('init');
        setErrorMessage(null);
        if (payload.settings) {
          setSettings(payload.settings);
        }
      }
    };

    window.addEventListener('message', handleMessage);

    // Notify parent loader that iframe is ready to receive initialization
    if (window.parent && window.parent !== window) {
      window.parent.postMessage({ type: 'conexa:ready' }, '*');
    }

    return () => {
      window.removeEventListener('message', handleMessage);
    };
  }, []);

  // 2. Start or resume active conversation
  const startOrResumeConversation = useCallback(async (visitorToken: string) => {
    try {
      const res = await fetch('/api/v1/public/webchat/conversations/start', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${visitorToken}`,
        },
      });

      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'Error al iniciar la conversación.');
      }

      const data = await res.json();
      setConversation(data.data.conversation);
      setMessages((prev) => sortAndDeduplicateWebchatMessages([...prev, ...(data.data.messages || [])]));
      setHasMoreMessages(Boolean(data.data.has_more));
      setOldestMessageId(data.data.oldest_message_id || null);
      setConnectionStatus('connected');
    } catch (err: any) {
      setConnectionStatus('error');
      setErrorMessage(err.message || 'Error al iniciar la conversación.');
    }
  }, []);

  const sessionInitiatedRef = useRef<boolean>(false);

  // 3. Fetch or resume session once publicKey and embedToken are available (or query param fallback)
  useEffect(() => {
    if (!publicKey) {
      return;
    }

    if (sessionInitiatedRef.current) return;
    sessionInitiatedRef.current = true;

    // If embedToken is not yet supplied via postMessage, fetch embed handshake directly
    const establishSession = async () => {
      try {
        setConnectionStatus('loading');
        let currentEmbedToken = embedToken;

        if (!currentEmbedToken) {
          const res = await fetch(`/api/v1/public/webchat/${encodeURIComponent(publicKey)}/embed`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ origin: hostOrigin || window.location.origin }),
          });

          if (!res.ok) {
            const errData = await res.json().catch(() => ({}));
            throw new Error(errData.message || 'Chat no disponible.');
          }

          const embedData = await res.json();
          currentEmbedToken = embedData.data.embed_token;
          setEmbedToken(currentEmbedToken);
          if (embedData.data.settings) {
            setSettings(embedData.data.settings);
          }
        }

        // Check localStorage for stored visitor token
        const storageKey = `conexa_visitor_token_${publicKey}`;
        let storedToken: string | null = null;
        try {
          storedToken = localStorage.getItem(storageKey);
        } catch {
          // ignore storage error
        }

        const sessionRes = await fetch(`/api/v1/public/webchat/${encodeURIComponent(publicKey)}/sessions`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({
            embed_token: currentEmbedToken,
            visitor_token: storedToken,
            origin: hostOrigin || window.location.origin,
          }),
        });

        if (!sessionRes.ok) {
          const errData = await sessionRes.json().catch(() => ({}));
          throw new Error(errData.message || 'No se pudo iniciar la sesión.');
        }

        const sessionData: WebchatSessionData = (await sessionRes.json()).data;
        setSession(sessionData);

        try {
          localStorage.setItem(storageKey, sessionData.visitor_token);
        } catch {
          // storage unavailable
        }

        if (sessionData.active_conversation && (sessionData.active_conversation as any).status === 'closed') {
          setConversation(sessionData.active_conversation);
          const currentRes = await fetch('/api/v1/public/webchat/conversations/current', {
            headers: {
              Accept: 'application/json',
              Authorization: `Bearer ${sessionData.visitor_token}`,
            },
          });
          if (currentRes.ok) {
            const currentData = await currentRes.json();
            if (currentData.data?.messages) {
              setMessages(sortAndDeduplicateWebchatMessages(currentData.data.messages));
              setHasMoreMessages(Boolean(currentData.data.has_more));
              setOldestMessageId(currentData.data.oldest_message_id || null);
            }
          }
          setConnectionStatus('connected');
        } else {
          // Start or resume conversation
          await startOrResumeConversation(sessionData.visitor_token);
        }
      } catch (err: any) {
        setConnectionStatus('error');
        setErrorMessage(err.message || 'Error al conectar con el servidor.');
      }
    };

    establishSession();
  }, [publicKey, embedToken, hostOrigin, startOrResumeConversation]);

  // 4. Setup Laravel Echo & Reverb for realtime messages
  const resyncMessages = useCallback(async () => {
    if (!session?.visitor_token) return;

    try {
      const [msgRes, convRes] = await Promise.all([
        fetch('/api/v1/public/webchat/messages', {
          headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${session.visitor_token}`,
          },
        }),
        fetch('/api/v1/public/webchat/conversations/current', {
          headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${session.visitor_token}`,
          },
        }),
      ]);

      if (msgRes.ok) {
        const data = await msgRes.json();
        const incoming = data.data || [];
        setMessages((prev) => sortAndDeduplicateWebchatMessages([...prev, ...incoming]));
        setHasMoreMessages(Boolean(data.has_more));
        if (data.oldest_message_id) {
          setOldestMessageId(data.oldest_message_id);
        }
      }

      if (convRes.ok) {
        const data = await convRes.json();
        if (data.data?.conversation) {
          setConversation(data.data.conversation);
        }
      }
    } catch {
      // Re-sync fallback silently on temporary errors
    }
  }, [session]);

  const handleLoadOlderWebchatMessages = async () => {
    if (!session?.visitor_token || !oldestMessageId || loadingMore) return;
    setLoadingMore(true);
    const container = messagesContainerRef.current;
    const prevScrollHeight = container?.scrollHeight || 0;
    const prevScrollTop = container?.scrollTop || 0;

    try {
      const res = await fetch(`/api/v1/public/webchat/messages?before=${oldestMessageId}&limit=50`, {
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${session.visitor_token}`,
        },
      });

      if (res.ok) {
        const data = await res.json();
        const older = data.data || [];
        setMessages((prev) => sortAndDeduplicateWebchatMessages([...older, ...prev]));
        setHasMoreMessages(Boolean(data.has_more));
        setOldestMessageId(data.oldest_message_id || null);

        requestAnimationFrame(() => {
          if (container) {
            container.scrollTop = container.scrollHeight - prevScrollHeight + prevScrollTop;
          }
        });
      }
    } catch {
      // fallback
    } finally {
      setLoadingMore(false);
    }
  };

  useEffect(() => {
    if (!session || !conversation?.id) return;

    const convId = conversation.id;
    let cancelled = false;
    let activeEcho: any = null;

    // Disconnect any existing Echo instance
    if (echoRef.current) {
      try {
        echoRef.current.disconnect();
      } catch {
        // ignore
      }
      echoRef.current = null;
    }

    const setupRealtime = async () => {
      const echo = await createEcho({
        authEndpoint: '/api/v1/public/webchat/broadcast/auth',
        bearerToken: session.visitor_token,
      });
      if (cancelled) {
        echo.disconnect();
        return;
      }
      activeEcho = echo;
      echoRef.current = echo;
      setRealtimeConnected(false);
      setConnectionStatus('reconnecting');

      // Connection lifecycle monitoring
      const connector = (echo.connector as any)?.pusher?.connection;
      if (connector) {
        connector.bind('connected', () => {
          setRealtimeConnected(true);
          setConnectionStatus('connected');
          resyncMessages();
        });
        connector.bind('connecting', () => {
          setRealtimeConnected(false);
          setConnectionStatus('reconnecting');
        });
        const markDisconnected = () => {
          setRealtimeConnected(false);
          setConnectionStatus('disconnected');
        };
        connector.bind('unavailable', markDisconnected);
        connector.bind('disconnected', markDisconnected);
        connector.bind('failed', markDisconnected);
      }

      const channelName = `webchat.conversation.${convId}`;
      const channel = echo.private(channelName);

    channel.listen('.MessageCreated', (newMsg: WebchatMessage) => {
      setMessages((prev) => {
        const incomingCid = newMsg.client_message_id || (newMsg.metadata as any)?.client_message_id;

        let replaced = false;
        const updated = prev.map((m) => {
          if (m.id === newMsg.id || (incomingCid && m.id === incomingCid)) {
            replaced = true;
            return { ...m, ...newMsg };
          }
          return m;
        });

        if (replaced) {
          return sortAndDeduplicateWebchatMessages(updated);
        }

        return sortAndDeduplicateWebchatMessages([...prev, newMsg]);
      });
    });

    channel.listen('.ConversationWaitingAgent', () => {
      setConversation((prev) => (prev ? { ...prev, status: 'waiting_agent' } : null));
    });

    channel.listen('.ConversationAssigned', () => {
      setConversation((prev) => (prev ? { ...prev, status: 'assigned' } : null));
    });

    channel.listen('.ConversationClosed', () => {
      setConversation((prev) => (prev ? { ...prev, status: 'closed' } : null));
    });

    channel.listen('.ConversationStatusChanged', (e: { status: WebchatConversation['status'] }) => {
      setConversation((prev) => (prev ? { ...prev, status: e.status } : null));
    });

    };

    void setupRealtime().catch(() => {
      setRealtimeConnected(false);
      setConnectionStatus('disconnected');
    });

    return () => {
      cancelled = true;
      try {
        activeEcho?.leave(`webchat.conversation.${convId}`);
        activeEcho?.disconnect();
      } catch {
        // ignore
      }
      if (echoRef.current === activeEcho) {
        echoRef.current = null;
      }
    };
  }, [session, conversation?.id, resyncMessages]);

  // If Reverb is unavailable, keep the visitor's transcript synchronized via
  // the normal API and recover immediately when the tab becomes visible again.
  useEffect(() => {
    if (!session?.visitor_token || !conversation?.id || realtimeConnected) return;

    let syncing = false;
    const sync = async () => {
      if (syncing || document.visibilityState === 'hidden') return;
      syncing = true;
      try {
        await resyncMessages();
      } finally {
        syncing = false;
      }
    };

    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') void sync();
    };
    const interval = window.setInterval(() => void sync(), 5000);
    window.addEventListener('focus', sync);
    document.addEventListener('visibilitychange', onVisibilityChange);

    return () => {
      window.clearInterval(interval);
      window.removeEventListener('focus', sync);
      document.removeEventListener('visibilitychange', onVisibilityChange);
    };
  }, [session?.visitor_token, conversation?.id, realtimeConnected, resyncMessages]);

  // 5. Smart auto-scroll handling
  const handleScroll = useCallback(() => {
    const el = messagesContainerRef.current;
    if (!el) return;
    const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
    // User is considered scrolled up if > 150px away from the bottom
    userScrolledUpRef.current = distanceFromBottom > 150;
  }, []);

  const scrollToBottom = useCallback((behavior: ScrollBehavior = 'smooth', force = false) => {
    if (!force && userScrolledUpRef.current) {
      return;
    }
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        const container = messagesContainerRef.current;
        if (container) {
          if (typeof container.scrollTo === 'function') {
            container.scrollTo({
              top: container.scrollHeight,
              behavior,
            });
          } else {
            container.scrollTop = container.scrollHeight;
          }
        }
        if (messagesEndRef.current?.scrollIntoView) {
          messagesEndRef.current.scrollIntoView({ behavior, block: 'end' });
        }
      });
    });
  }, []);

  // Auto-scroll when conversation changes (immediate 'auto' scroll)
  useEffect(() => {
    if (conversation?.id) {
      const isNew = prevConversationIdRef.current !== conversation.id;
      prevConversationIdRef.current = conversation.id;
      if (isNew) {
        userScrolledUpRef.current = false;
        scrollToBottom('auto', true);
      }
    } else {
      prevConversationIdRef.current = null;
    }
  }, [conversation?.id, scrollToBottom]);

  // Auto-scroll when messages update
  useEffect(() => {
    if (!userScrolledUpRef.current) {
      scrollToBottom('smooth');
    }
  }, [messages, scrollToBottom]);

  // ResizeObserver: auto-scroll when message container or contents resize (e.g. interactive bot options, images rendered)
  useEffect(() => {
    const target = messagesContentRef.current || messagesContainerRef.current;
    if (!target || typeof ResizeObserver === 'undefined') return;

    const observer = new ResizeObserver(() => {
      if (!userScrolledUpRef.current) {
        scrollToBottom('smooth');
      }
    });

    observer.observe(target);
    return () => {
      observer.disconnect();
    };
  }, [scrollToBottom]);

  // 6. Send message from visitor
  const handleSendMessage = useCallback(async (e?: React.FormEvent, overrideText?: string) => {
    if (e) e.preventDefault();
    const text = (overrideText !== undefined ? overrideText : inputText).trim();
    const fileToSend = overrideText !== undefined ? null : selectedFile;

    if ((!text && !fileToSend) || isSending || !session || !conversation || conversation.status === 'closed') {
      return;
    }

    setIsSending(true);
    setErrorMessage(null);

    const previousInputText = inputText;
    const previousFile = selectedFile;
    const previousPreviewUrl = filePreviewUrl;

    if (overrideText === undefined) {
      setInputText('');
      handleClearFile();
    }
    userScrolledUpRef.current = false;

    let clientMessageId: string;
    try {
      clientMessageId = generateUuid();
    } catch {
      clientMessageId = `wcm_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;
    }

    const nowIso = new Date().toISOString();
    const isImage = fileToSend?.type.startsWith('image/');
    const isAudio = Boolean(fileToSend && (fileToSend.type.startsWith('audio/') || fileToSend.type === 'application/ogg' || fileToSend.type === 'video/webm' || fileToSend.name.toLowerCase().endsWith('.ogg') || fileToSend.name.toLowerCase().endsWith('.opus') || fileToSend.name.toLowerCase().endsWith('.mp3') || fileToSend.name.toLowerCase().endsWith('.m4a') || fileToSend.name.toLowerCase().endsWith('.aac') || fileToSend.name.toLowerCase().endsWith('.wav') || fileToSend.name.toLowerCase().endsWith('.webm')));
    // Optimistic message placeholder
    const optimisticMessage: WebchatMessage = {
      id: clientMessageId,
      client_message_id: clientMessageId,
      sender_type: 'contact',
      direction: 'inbound',
      message_type: fileToSend ? (isImage ? 'image' : isAudio ? 'audio' : 'file') : 'text',
      content: text,
      payload: fileToSend
        ? {
            attachment: {
              url: previousPreviewUrl || '',
              file_name: fileToSend.name,
              mime_type: fileToSend.type,
              size_bytes: fileToSend.size,
              is_voice_note: (fileToSend as any).isVoiceNote || false,
            },
          }
        : undefined,
      sent_at: nowIso,
      created_at: nowIso,
    };

    setMessages((prev) => sortAndDeduplicateWebchatMessages([...prev, optimisticMessage]));
    userScrolledUpRef.current = false;
    scrollToBottom('smooth', true);

    try {
      let res: Response;
      if (fileToSend) {
        const formData = new FormData();
        if (text) formData.append('content', text);
        formData.append('client_message_id', clientMessageId);
        formData.append('attachment', fileToSend);

        res = await fetch('/api/v1/public/webchat/messages', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${session.visitor_token}`,
          },
          body: formData,
        });
      } else {
        res = await fetch('/api/v1/public/webchat/messages', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            Authorization: `Bearer ${session.visitor_token}`,
          },
          body: JSON.stringify({
            content: text,
            client_message_id: clientMessageId,
          }),
        });
      }

      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'Error al enviar el mensaje.');
      }

      const data = await res.json();
      const confirmedMessage: WebchatMessage = data.data;

      // Replace optimistic message and immediately integrate any bot responses returned by backend
      setMessages((prev) => {
        let replaced = false;
        const updated = prev.map((m) => {
          if (m.id === clientMessageId || m.id === confirmedMessage.id) {
            replaced = true;
            return confirmedMessage;
          }
          return m;
        });

        const list = replaced ? updated : [...updated, confirmedMessage];

        if (Array.isArray(data.messages)) {
          data.messages.forEach((bm: WebchatMessage) => {
            if (bm && bm.id && !list.some((existing) => existing.id === bm.id)) {
              list.push(bm);
            }
          });
        }

        return sortAndDeduplicateWebchatMessages(list);
      });

      if (data.conversation) {
        setConversation(data.conversation);
      }
    } catch (err: any) {
      // Rollback optimistic message so the chat isn't stuck with a false sent message
      setMessages((prev) => prev.filter((m) => m.id !== clientMessageId));
      if (overrideText === undefined) {
        setInputText(previousInputText);
        if (previousFile) {
          setSelectedFile(previousFile);
          setFilePreviewUrl(previousPreviewUrl);
        }
      }
      setErrorMessage(err.message || 'No se pudo enviar el mensaje. Inténtalo nuevamente.');
    } finally {
      setIsSending(false);
    }
  }, [inputText, isSending, session, conversation, selectedFile, filePreviewUrl, handleClearFile, scrollToBottom]);

  const handleSendVoiceNote = async () => {
    const file = await voiceRecorder.stopRecording();
    if (!file || !session || !conversation || conversation.status === 'closed') return;

    setIsSending(true);
    setErrorMessage(null);

    let clientMessageId: string;
    try {
      clientMessageId = generateUuid();
    } catch {
      clientMessageId = `wcm_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;
    }

    const nowIso = new Date().toISOString();
    const tempUrl = URL.createObjectURL(file);

    const optimisticMessage: WebchatMessage = {
      id: clientMessageId,
      client_message_id: clientMessageId,
      sender_type: 'contact',
      direction: 'inbound',
      message_type: 'audio',
      content: '',
      payload: {
        attachment: {
          url: tempUrl,
          file_name: file.name,
          mime_type: file.type || 'audio/ogg',
          size_bytes: file.size,
          is_voice_note: true,
        },
      },
      sent_at: nowIso,
      created_at: nowIso,
    };

    setMessages((prev) => sortAndDeduplicateWebchatMessages([...prev, optimisticMessage]));
    userScrolledUpRef.current = false;
    scrollToBottom('smooth', true);

    try {
      const formData = new FormData();
      formData.append('client_message_id', clientMessageId);
      formData.append('attachment', file);

      const res = await fetch('/api/v1/public/webchat/messages', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${session.visitor_token}`,
        },
        body: formData,
      });

      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.message || 'Error al enviar nota de voz');
      }

      if (data.data) {
        setMessages((prev) =>
          sortAndDeduplicateWebchatMessages(
            prev.map((m) => (m.id === clientMessageId ? data.data : m))
          )
        );
      }
      if (data.conversation) {
        setConversation(data.conversation);
      }
    } catch (err: any) {
      setErrorMessage(err.message || 'Error al enviar audio');
      setMessages((prev) => prev.filter((m) => m.id !== clientMessageId));
    } finally {
      setIsSending(false);
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSendMessage();
    }
  };

  const handleClose = () => {
    if (window.parent && window.parent !== window) {
      window.parent.postMessage({ type: 'conexa:close' }, '*');
    }
  };

  const handleStartNewConversation = async () => {
    let token = session?.visitor_token;
    if (!token && publicKey) {
      try {
        token = localStorage.getItem(`conexa_visitor_token_${publicKey}`) || undefined;
      } catch {
        // ignore storage error
      }
    }
    if (!token) return;
    setConnectionStatus('loading');
    await startOrResumeConversation(token);
  };

  const accentColor = settings.accent_color || '#5d8f82';

  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        height: '100dvh',
        minHeight: '100vh',
        width: '100%',
        boxSizing: 'border-box',
        backgroundColor: '#FFFFFF',
        fontFamily:
          '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
        color: '#263c38',
        overflow: 'hidden',
        position: 'relative',
      }}
    >
      {/* Header */}
      <header
        style={{
          backgroundColor: accentColor,
          color: '#FFFFFF',
          paddingTop: 'max(12px, calc(env(safe-area-inset-top, 0px) + 8px))',
          paddingBottom: '12px',
          paddingLeft: 'max(16px, env(safe-area-inset-left, 0px))',
          paddingRight: 'max(16px, env(safe-area-inset-right, 0px))',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          boxShadow: '0 2px 8px rgba(0, 0, 0, 0.12)',
          zIndex: 10,
          flexShrink: 0,
          boxSizing: 'border-box',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <div
            style={{
              width: '36px',
              height: '36px',
              borderRadius: '18px',
              backgroundColor: 'rgba(255, 255, 255, 0.2)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <Bot size={20} color="#FFFFFF" />
          </div>
          <div>
            <h1 style={{ margin: 0, fontSize: '16px', fontWeight: 600 }}>
              {settings.display_name}
            </h1>
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: '6px',
                fontSize: '12px',
                opacity: 0.9,
                marginTop: '2px',
              }}
            >
              <span
                style={{
                  width: '8px',
                  height: '8px',
                  borderRadius: '4px',
                  backgroundColor:
                    conversation?.status === 'closed'
                      ? '#80918c'
                      : conversation?.status === 'waiting_agent'
                      ? '#FBBF24'
                      : conversation?.status === 'assigned'
                      ? '#60A5FA'
                      : connectionStatus === 'connected'
                      ? '#34D399'
                      : '#F87171',
                }}
              />
              <span>
                {conversation?.status === 'closed'
                  ? 'Conversación finalizada'
                  : conversation?.status === 'waiting_agent'
                  ? 'Esperando agente...'
                  : conversation?.status === 'assigned'
                  ? 'Atendido por agente'
                  : connectionStatus === 'connected'
                  ? 'En línea'
                  : connectionStatus === 'reconnecting'
                  ? 'Reconectando...'
                  : 'Desconectado'}
              </span>
            </div>
          </div>
        </div>

        <button
          onClick={handleClose}
          aria-label="Minimizar chat"
          style={{
            background: 'transparent',
            border: 'none',
            color: '#FFFFFF',
            cursor: 'pointer',
            padding: '6px',
            borderRadius: '6px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            transition: 'background-color 0.2s',
          }}
          onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = 'rgba(255, 255, 255, 0.15)')}
          onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = 'transparent')}
        >
          <X size={20} />
        </button>
      </header>

      {/* Messages Thread */}
      <div
        ref={messagesContainerRef}
        onScroll={handleScroll}
        style={{
          flex: 1,
          minHeight: 0,
          overflowY: 'auto',
          padding: '16px',
          display: 'flex',
          flexDirection: 'column',
          gap: '12px',
          backgroundColor: '#f4f7f5',
        }}
      >
        <div
          ref={messagesContentRef}
          style={{
            display: 'flex',
            flexDirection: 'column',
            gap: '12px',
            minHeight: '100%',
          }}
        >
        {hasMoreMessages && (
          <div style={{ textAlign: 'center', margin: '4px 0 8px 0' }}>
            <button
              data-testid="webchat-load-older-btn"
              onClick={handleLoadOlderWebchatMessages}
              disabled={loadingMore}
              style={{
                fontSize: '12px',
                padding: '5px 12px',
                background: '#eaf1ee',
                border: '1px solid #d9e3de',
                borderRadius: '6px',
                cursor: 'pointer',
                color: '#61726d',
                fontWeight: 500,
              }}
            >
              {loadingMore ? 'Cargando mensajes anteriores...' : 'Cargar mensajes anteriores'}
            </button>
          </div>
        )}

        {connectionStatus === 'loading' && messages.length === 0 && (
          <div
            style={{
              display: 'flex',
              flexDirection: 'column',
              alignItems: 'center',
              justifyContent: 'center',
              height: '100%',
              gap: '12px',
              color: '#6B7280',
            }}
          >
            <RefreshCw size={24} style={{ animation: 'spin 1s linear infinite' }} />
            <span style={{ fontSize: '14px' }}>Cargando conversación...</span>
          </div>
        )}

        {errorMessage && (
          <div
            style={{
              padding: '10px 14px',
              backgroundColor: '#FEE2E2',
              border: '1px solid #FCA5A5',
              borderRadius: '8px',
              color: '#991B1B',
              fontSize: '13px',
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
            }}
          >
            <AlertCircle size={16} />
            <span>{errorMessage}</span>
          </div>
        )}

        {messages.map((msg) => {
          const isDivider =
            msg.sender_type === 'system' ||
            msg.message_type === 'system' ||
            (msg.metadata as any)?.event === 'session_divider' ||
            (msg.content && msg.content.includes('Sesión finalizada'));
          const sessionBoundary = (msg.metadata as any)?.session_divider_before;

          if (isDivider) {
            const rawTs = (msg.metadata as any)?.timestamp || msg.sent_at || msg.created_at;
            return <SessionDivider key={msg.id} timestamp={rawTs} />;
          }

          const isVisitor = msg.sender_type === 'contact' || msg.direction === 'inbound';
          const isAgent = msg.sender_type === 'agent';
          const isBot = msg.sender_type === 'bot';
          const interactiveOptions = !isVisitor ? extractInteractiveOptions(msg) : [];

          return (
            <React.Fragment key={msg.id}>
              {sessionBoundary && <SessionDivider timestamp={sessionBoundary.timestamp} />}
              <div
                style={{
                  display: 'flex',
                  flexDirection: 'column',
                  alignItems: isVisitor ? 'flex-end' : 'flex-start',
                  width: '100%',
                }}
              >
              {/* Sender label */}
              {!isVisitor && (
                <div
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '4px',
                    fontSize: '11px',
                    color: '#6B7280',
                    marginBottom: '4px',
                    marginLeft: '4px',
                  }}
                >
                  {isAgent ? (
                    <>
                      <User size={12} color="#5d8f82" />
                      <span style={{ fontWeight: 600, color: '#456c61' }}>Agente</span>
                    </>
                  ) : isBot ? (
                    <>
                      <Bot size={12} color="#61726d" />
                      <span>Bot</span>
                    </>
                  ) : null}
                </div>
              )}

              {/* Message Bubble */}
              {(() => {
                const rawAttachmentUrl = msg.payload?.attachment?.url || msg.metadata?.attachment?.url;
                const attachmentUrl = resolveMediaUrl(rawAttachmentUrl);
                const attachmentFileName = msg.payload?.attachment?.file_name || msg.metadata?.attachment?.file_name;
                const attachmentSizeBytes = msg.payload?.attachment?.size_bytes || msg.metadata?.attachment?.size_bytes;
                const attachmentMime = msg.payload?.attachment?.mime_type || msg.metadata?.attachment?.mime_type;
                const isVoiceNote = Boolean(msg.payload?.attachment?.is_voice_note || msg.metadata?.attachment?.is_voice_note);
                const isAudio = msg.message_type === 'audio' || attachmentMime?.startsWith('audio/') || isVoiceNote;
                const isImage = !isAudio && (msg.message_type === 'image' || attachmentMime?.startsWith('image/'));
                const isFile = !isAudio && !isImage && (msg.message_type === 'file' || Boolean(attachmentUrl));
                const isDefaultAudioContent = isAudio && (msg.content === '🎵 Nota de voz' || msg.content === '🎵 Audio');

                return (
                  <div
                    style={{
                      maxWidth: '82%',
                      padding: '10px 14px',
                      borderRadius: isVisitor ? '16px 16px 4px 16px' : '16px 16px 16px 4px',
                      backgroundColor: isVisitor ? accentColor : '#FFFFFF',
                      color: isVisitor ? '#FFFFFF' : '#263c38',
                      boxShadow: isVisitor
                        ? '0 2px 4px rgba(0, 0, 0, 0.1)'
                        : '0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.04)',
                      fontSize: '14px',
                      lineHeight: '1.45',
                      wordBreak: 'break-word',
                      border: isVisitor ? 'none' : isAgent ? '1px solid #a9c8bb' : '1px solid #d9e3de',
                      animation: 'webchatFadeIn 0.2s ease-out',
                    }}
                  >
                    {/* Audio rendering */}
                    {isAudio && attachmentUrl && (
                      <div style={{ marginBottom: msg.content && !isDefaultAudioContent ? '8px' : '0' }}>
                        <AudioMessagePlayer
                          url={attachmentUrl}
                          fileName={attachmentFileName}
                          sizeBytes={attachmentSizeBytes}
                          isVoiceNote={isVoiceNote}
                          isOutbound={isVisitor}
                        />
                      </div>
                    )}

                    {/* Image rendering */}
                    {isImage && attachmentUrl && (
                      <div style={{ marginBottom: msg.content ? '8px' : '0' }}>
                        <WebchatImageAttachment
                          url={attachmentUrl}
                          fileName={attachmentFileName}
                          isVisitor={isVisitor}
                          onLoad={() => {
                            if (!userScrolledUpRef.current) {
                              scrollToBottom('smooth');
                            }
                          }}
                        />
                      </div>
                    )}

                    {/* Document rendering */}
                    {isFile && attachmentUrl && (
                      <div
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          gap: '8px',
                          padding: '8px 10px',
                          background: isVisitor ? 'rgba(255, 255, 255, 0.2)' : '#eaf1ee',
                          borderRadius: '8px',
                          marginBottom: msg.content ? '8px' : '0',
                        }}
                      >
                        <FileText size={20} style={{ flexShrink: 0, color: isVisitor ? '#FFFFFF' : accentColor }} />
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div
                            style={{
                              fontSize: '13px',
                              fontWeight: 600,
                              overflow: 'hidden',
                              textOverflow: 'ellipsis',
                              whiteSpace: 'nowrap',
                              color: isVisitor ? '#FFFFFF' : '#263c38',
                            }}
                          >
                            {attachmentFileName || 'Documento'}
                          </div>
                          {attachmentSizeBytes && (
                            <div style={{ fontSize: '11px', opacity: 0.8, color: isVisitor ? '#d9e3de' : '#6B7280' }}>
                              {formatFileSize(attachmentSizeBytes)}
                            </div>
                          )}
                        </div>
                        <a
                          href={attachmentUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          download={attachmentFileName}
                          style={{
                            color: isVisitor ? '#FFFFFF' : accentColor,
                            display: 'flex',
                            alignItems: 'center',
                            padding: '4px',
                          }}
                          title="Descargar archivo"
                        >
                          <Download size={16} />
                        </a>
                      </div>
                    )}

                    {msg.content && !isDefaultAudioContent && (
                      <LinkifiedText
                        text={msg.content}
                        linkClassName={
                          isVisitor
                            ? 'text-white underline hover:opacity-85 break-words inline-block'
                            : 'text-blue-600 dark:text-blue-400 underline hover:text-blue-800 break-words inline-block'
                        }
                      />
                    )}
                  </div>
                );
              })()}

              {/* Choices / Interactive Options if question, menu, or validation retry */}
              {interactiveOptions.length > 0 && (
                <div
                  data-testid="webchat-interactive-options"
                  style={{
                    display: 'flex',
                    flexWrap: 'wrap',
                    gap: '8px',
                    marginTop: '8px',
                    maxWidth: '82%',
                  }}
                >
                  {interactiveOptions.map((opt, idx) => (
                    <button
                      key={opt.id || idx}
                      type="button"
                      data-testid={`webchat-option-${opt.id || idx}`}
                      disabled={isSending || conversation?.status === 'closed'}
                      onClick={() => handleSendMessage(undefined, opt.title)}
                      style={{
                        padding: '8px 14px',
                        backgroundColor: '#FFFFFF',
                        border: `1.5px solid ${accentColor}`,
                        color: accentColor,
                        borderRadius: '16px',
                        fontSize: '13px',
                        fontWeight: 600,
                        cursor: isSending || conversation?.status === 'closed' ? 'not-allowed' : 'pointer',
                        transition: 'all 0.15s ease-in-out',
                        boxShadow: '0 1px 2px rgba(0, 0, 0, 0.05)',
                      }}
                      onMouseEnter={(e) => {
                        e.currentTarget.style.backgroundColor = accentColor;
                        e.currentTarget.style.color = '#FFFFFF';
                      }}
                      onMouseLeave={(e) => {
                        e.currentTarget.style.backgroundColor = '#FFFFFF';
                        e.currentTarget.style.color = accentColor;
                      }}
                    >
                      {opt.title}
                    </button>
                  ))}
                </div>
              )}

              {/* Timestamp */}
              <span
                style={{
                  fontSize: '10px',
                  color: '#80918c',
                  marginTop: '3px',
                  marginRight: isVisitor ? '4px' : '0',
                  marginLeft: isVisitor ? '0' : '4px',
                }}
              >
                {new Date(msg.sent_at).toLocaleTimeString([], {
                  hour: '2-digit',
                  minute: '2-digit',
                })}
              </span>
              </div>
            </React.Fragment>
          );
        })}

        {/* State Notice Banners */}
        {conversation?.status === 'waiting_agent' && (
          <div
            style={{
              alignSelf: 'center',
              padding: '8px 16px',
              backgroundColor: '#FEF3C7',
              border: '1px solid #FDE68A',
              borderRadius: '20px',
              color: '#92400E',
              fontSize: '12px',
              fontWeight: 500,
              display: 'flex',
              alignItems: 'center',
              gap: '6px',
              margin: '8px 0',
            }}
          >
            <span
              style={{
                width: '6px',
                height: '6px',
                borderRadius: '3px',
                backgroundColor: '#D97706',
                animation: 'pulse 2s infinite',
              }}
            />
            Te estamos conectando con un agente...
          </div>
        )}

        {conversation?.status === 'assigned' && (
          <div
            style={{
              alignSelf: 'center',
              padding: '8px 16px',
              backgroundColor: '#eaf1ee',
              border: '1px solid #a9c8bb',
              borderRadius: '20px',
              color: '#456c61',
              fontSize: '12px',
              fontWeight: 500,
              display: 'flex',
              alignItems: 'center',
              gap: '6px',
              margin: '8px 0',
            }}
          >
            <User size={14} />
            Un agente se ha unido a la conversación.
          </div>
        )}

        {conversation?.status === 'closed' && (
          <div
            style={{
              alignSelf: 'center',
              display: 'flex',
              flexDirection: 'column',
              alignItems: 'center',
              gap: '10px',
              margin: '16px 0',
              padding: '14px 20px',
              backgroundColor: '#eaf1ee',
              borderRadius: '12px',
              border: '1px solid #d9e3de',
              textAlign: 'center',
            }}
          >
            <span style={{ fontSize: '13px', color: '#61726d', fontWeight: 500 }}>
              Esta conversación ha finalizado.
            </span>
            <button
              onClick={handleStartNewConversation}
              type="button"
              style={{
                backgroundColor: accentColor,
                color: '#FFFFFF',
                border: 'none',
                padding: '8px 16px',
                borderRadius: '8px',
                fontSize: '13px',
                fontWeight: 600,
                cursor: 'pointer',
                boxShadow: '0 2px 4px rgba(0, 0, 0, 0.1)',
                transition: 'opacity 0.2s',
              }}
              onMouseEnter={(e) => (e.currentTarget.style.opacity = '0.9')}
              onMouseLeave={(e) => (e.currentTarget.style.opacity = '1')}
            >
              Iniciar nueva conversación
            </button>
          </div>
        )}

          <div ref={messagesEndRef} className="h-0 w-full shrink-0" aria-hidden="true" />
        </div>
      </div>

      {/* Composer */}
      <footer
        style={{
          paddingTop: '10px',
          paddingBottom: 'max(12px, calc(env(safe-area-inset-bottom, 0px) + 8px))',
          paddingLeft: 'max(16px, env(safe-area-inset-left, 0px))',
          paddingRight: 'max(16px, env(safe-area-inset-right, 0px))',
          borderTop: '1px solid #d9e3de',
          backgroundColor: '#FFFFFF',
          flexShrink: 0,
          boxSizing: 'border-box',
        }}
      >
        {/* Selected Attachment Preview */}
        {selectedFile && (
          <div
            data-testid="webchat-attachment-preview"
            style={{
              padding: '8px 12px',
              backgroundColor: '#eaf1ee',
              borderRadius: '8px',
              display: 'flex',
              alignItems: 'center',
              gap: '10px',
              marginBottom: '8px',
            }}
          >
            {filePreviewUrl ? (
              <img
                src={filePreviewUrl}
                alt="Vista previa"
                style={{ width: '38px', height: '38px', objectFit: 'cover', borderRadius: '4px', border: '1px solid #d9e3de' }}
              />
            ) : (
              <div style={{ padding: '6px', backgroundColor: '#d9e3de', borderRadius: '4px', color: '#61726d' }}>
                <FileText size={18} />
              </div>
            )}
            <div style={{ flex: 1, minWidth: 0, fontSize: '12px' }}>
              <div style={{ fontWeight: 600, color: '#263c38', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                {selectedFile.name}
              </div>
              <div style={{ fontSize: '11px', color: '#6B7280' }}>
                {formatFileSize(selectedFile.size)}
              </div>
            </div>
            <button
              type="button"
              onClick={handleClearFile}
              style={{
                border: 'none',
                background: 'transparent',
                cursor: 'pointer',
                color: '#6B7280',
                padding: '4px',
              }}
              title="Quitar archivo"
            >
              <X size={16} />
            </button>
          </div>
        )}

        {/* File Validation Error Banner */}
        {(fileError || voiceRecorder.errorMessage) && (
          <div
            style={{
              padding: '6px 10px',
              backgroundColor: '#FEE2E2',
              color: '#991B1B',
              fontSize: '12px',
              borderRadius: '6px',
              display: 'flex',
              alignItems: 'center',
              gap: '6px',
              marginBottom: '8px',
            }}
          >
            <AlertCircle size={14} />
            <span>{fileError || voiceRecorder.errorMessage}</span>
          </div>
        )}

        {voiceRecorder.isRecording ? (
          <div
            data-testid="webchat-voice-recording-bar"
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
              padding: '8px 12px',
              backgroundColor: '#FEF2F2',
              borderRadius: '10px',
              border: '1px solid #FECACA',
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flex: 1 }}>
              <span
                style={{
                  width: '10px',
                  height: '10px',
                  borderRadius: '50%',
                  backgroundColor: '#EF4444',
                  display: 'inline-block',
                }}
              />
              <span style={{ fontSize: '13px', fontWeight: 600, color: '#DC2626' }}>
                Grabando audio... {voiceRecorder.formattedDuration}
              </span>
            </div>

            <button
              type="button"
              data-testid="webchat-cancel-voice-btn"
              onClick={voiceRecorder.cancelRecording}
              aria-label="Cancelar grabación"
              title="Cancelar grabación"
              style={{
                backgroundColor: 'transparent',
                color: '#EF4444',
                border: 'none',
                cursor: 'pointer',
                padding: '6px',
                display: 'flex',
                alignItems: 'center',
              }}
            >
              <Trash2 size={18} />
            </button>

            <button
              type="button"
              data-testid="webchat-send-voice-btn"
              onClick={handleSendVoiceNote}
              disabled={isSending}
              aria-label="Enviar nota de voz"
              title="Enviar nota de voz"
              style={{
                backgroundColor: '#5d8f82',
                color: '#FFFFFF',
                border: 'none',
                borderRadius: '8px',
                padding: '6px 12px',
                cursor: isSending ? 'not-allowed' : 'pointer',
                opacity: isSending ? 0.6 : 1,
                display: 'flex',
                alignItems: 'center',
                gap: '4px',
                fontSize: '13px',
                fontWeight: 600,
              }}
            >
              <Send size={14} /> Enviar
            </button>
          </div>
        ) : (
          <form
            onSubmit={handleSendMessage}
            style={{
              display: 'flex',
              alignItems: 'flex-end',
              gap: '8px',
              position: 'relative',
            }}
          >
            {/* Hidden File Input */}
            <input
              ref={fileInputRef}
              type="file"
              data-testid="webchat-attachment-input"
              style={{ display: 'none' }}
              onChange={handleFileSelect}
              accept="image/jpeg,image/png,image/webp,image/gif,audio/ogg,audio/opus,audio/mpeg,audio/mp3,audio/wav,audio/x-m4a,audio/aac,audio/webm,application/pdf,text/plain,text/csv,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            />

            {/* Attach Button */}
            <button
              type="button"
              data-testid="webchat-attach-btn"
              disabled={isSending || conversation?.status === 'closed'}
              onClick={() => fileInputRef.current?.click()}
              aria-label="Adjuntar archivo o imagen"
              title="Adjuntar imagen (JPG, PNG, WEBP, GIF máx 5MB), audio (máx 16MB) o documento (PDF, CSV, TXT, DOCX, XLSX máx 10MB)"
              style={{
                backgroundColor: '#eaf1ee',
                color: '#61726d',
                border: '1px solid #d9e3de',
                borderRadius: '10px',
                width: '40px',
                height: '40px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                cursor: isSending || conversation?.status === 'closed' ? 'not-allowed' : 'pointer',
                opacity: isSending || conversation?.status === 'closed' ? 0.5 : 1,
                flexShrink: 0,
              }}
            >
              <Paperclip size={18} />
            </button>

            <textarea
              value={inputText}
              onChange={(e) => setInputText(e.target.value.slice(0, 4000))}
              onKeyDown={handleKeyDown}
              placeholder={
                conversation?.status === 'closed'
                  ? 'Conversación finalizada'
                  : 'Escribe tu mensaje...'
              }
              disabled={isSending || conversation?.status === 'closed'}
              rows={1}
              style={{
                flex: 1,
                resize: 'none',
                padding: '10px 14px',
                borderRadius: '10px',
                border: '1px solid #d9e3de',
                fontSize: '16px',
                lineHeight: '1.4',
                fontFamily: 'inherit',
                outline: 'none',
                maxHeight: '120px',
                boxSizing: 'border-box',
                backgroundColor: conversation?.status === 'closed' ? '#f4f7f5' : '#FFFFFF',
              }}
              onFocus={(e) => (e.target.style.borderColor = accentColor)}
              onBlur={(e) => (e.target.style.borderColor = '#d9e3de')}
            />

            {/* Voice Note Record Mic Button */}
            <button
              type="button"
              data-testid="webchat-mic-btn"
              disabled={isSending || conversation?.status === 'closed'}
              onClick={voiceRecorder.startRecording}
              aria-label="Grabar nota de voz"
              title="Grabar nota de voz"
              style={{
                backgroundColor: '#eaf1ee',
                color: '#61726d',
                border: '1px solid #d9e3de',
                borderRadius: '10px',
                width: '40px',
                height: '40px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                cursor: isSending || conversation?.status === 'closed' ? 'not-allowed' : 'pointer',
                opacity: isSending || conversation?.status === 'closed' ? 0.5 : 1,
                flexShrink: 0,
              }}
            >
              <Mic size={18} />
            </button>

            <button
              type="submit"
              disabled={(!inputText.trim() && !selectedFile) || isSending || conversation?.status === 'closed'}
              aria-label="Enviar mensaje"
              style={{
                backgroundColor: accentColor,
                color: '#FFFFFF',
                border: 'none',
                borderRadius: '10px',
                width: '40px',
                height: '40px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                cursor:
                  (!inputText.trim() && !selectedFile) || isSending || conversation?.status === 'closed'
                    ? 'not-allowed'
                    : 'pointer',
                opacity: (!inputText.trim() && !selectedFile) || isSending || conversation?.status === 'closed' ? 0.5 : 1,
                transition: 'opacity 0.2s, transform 0.1s',
                flexShrink: 0,
              }}
            >
              <Send size={18} />
            </button>
          </form>
        )}

        {inputText.length > 0 && (
          <div style={{ textAlign: 'right', fontSize: '11px', color: '#80918c', marginTop: '4px' }}>
            {inputText.length} / 4000
          </div>
        )}
      </footer>
    </div>
  );
};
