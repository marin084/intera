import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { WebchatApp } from '../WebchatApp';
import { sortAndDeduplicateWebchatMessages } from '../utils';
import type { WebchatMessage } from '../types';

const echoMock = vi.hoisted(() => ({
  listeners: {} as Record<string, (event: any) => void>,
}));

// Mock Echo
vi.mock('../../services/echo', () => ({
  createEcho: () => ({
    connector: {
      pusher: {
        connection: {
          bind: vi.fn(),
        },
      },
    },
    private: (channelName: string) => {
      const channel = {
        listen: (eventName: string, callback: (event: any) => void) => {
          echoMock.listeners[`${channelName}:${eventName}`] = callback;
          return channel;
        },
      };
      return channel;
    },
    leave: vi.fn(),
    disconnect: vi.fn(),
  }),
}));

const storage: Record<string, string> = {};
const mockLocalStorage = {
  getItem: (key: string) => storage[key] || null,
  setItem: (key: string, val: string) => {
    storage[key] = val;
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
});

describe('WebchatApp Widget', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    Object.keys(echoMock.listeners).forEach((key) => delete echoMock.listeners[key]);
    mockLocalStorage.clear();
    // Clear URL search params
    window.history.replaceState({}, '', '/widget.html');
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('renders configuration error when no channel key is provided', async () => {
    window.history.replaceState({}, '', '/widget.html');
    render(<WebchatApp />);
    await waitFor(() => {
      expect(screen.getByText(/Error de Configuración/i)).toBeInTheDocument();
      expect(
        screen.getByText(/Falta el identificador del canal/i)
      ).toBeInTheDocument();
    });
  });

  it('initializes session, fetches conversation, and displays messages safely', async () => {
    // Provide channel via query param
    window.history.replaceState({}, '', '/widget.html?channel=test_public_key');

    // Mock fetch calls: embed, sessions, conversations/start
    const mockEmbedResponse = {
      data: {
        embed_token: 'signed_embed_token_123',
        settings: {
          display_name: 'Asistente Conexa',
          accent_color: '#2563eb',
          launcher_position: 'bottom-right',
        },
      },
    };

    const mockSessionResponse = {
      data: {
        id: 'sess-uuid-1',
        channel_id: 'chan-uuid-1',
        contact_id: 'cont-uuid-1',
        visitor_token: 'visitor_raw_token_xyz',
        origin: 'http://localhost:3000',
        active_conversation_id: 'conv-uuid-1',
        expires_at: '2026-10-05T00:00:00Z',
      },
    };

    const mockStartResponse = {
      data: {
        conversation: {
          id: 'conv-uuid-1',
          channel_id: 'chan-uuid-1',
          contact_id: 'cont-uuid-1',
          status: 'bot',
          started_at: '2026-10-04T12:00:00Z',
          last_message_at: '2026-10-04T12:00:01Z',
          closed_at: null,
        },
        messages: [
          {
            id: 'msg-1',
            conversation_id: 'conv-uuid-1',
            sender_type: 'bot',
            direction: 'outbound',
            message_type: 'text',
            visibility: 'public',
            content: '¡Hola! Bienvenido al soporte interactivo de Conexa.',
            sent_at: '2026-10-04T12:00:01Z',
          },
          {
            id: 'msg-2',
            conversation_id: 'conv-uuid-1',
            sender_type: 'bot',
            direction: 'outbound',
            message_type: 'text',
            visibility: 'public',
            content: '<strong>Este texto con etiquetas HTML no debe interpretarse</strong>',
            sent_at: '2026-10-04T12:00:02Z',
          },
        ],
      },
    };

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: any) => {
      const url = typeof input === 'string' ? input : input.url;

      if (url.includes('/embed')) {
        return new Response(JSON.stringify(mockEmbedResponse), { status: 200 });
      }
      if (url.includes('/sessions')) {
        return new Response(JSON.stringify(mockSessionResponse), { status: 200 });
      }
      if (url.includes('/conversations/start')) {
        return new Response(JSON.stringify(mockStartResponse), { status: 200 });
      }
      return new Response(JSON.stringify({ error: 'not found' }), { status: 404 });
    });

    render(<WebchatApp />);

    // Expect display name in header
    await waitFor(() => {
      expect(screen.getByText('Asistente Conexa')).toBeInTheDocument();
    });

    // Expect messages rendered
    expect(
      screen.getByText('¡Hola! Bienvenido al soporte interactivo de Conexa.')
    ).toBeInTheDocument();

    // Verify XSS prevention: HTML tags are rendered as plain text string, not executed
    const htmlMsg = screen.getByText('<strong>Este texto con etiquetas HTML no debe interpretarse</strong>');
    expect(htmlMsg).toBeInTheDocument();
    expect(htmlMsg.tagName.toLowerCase()).not.toBe('strong');
  });

  it('allows visitor to type and send a message', async () => {
    window.history.replaceState({}, '', '/widget.html?channel=test_public_key');

    const mockEmbedResponse = {
      data: {
        embed_token: 'token_123',
        settings: { display_name: 'Chat Bot', accent_color: '#2563eb' },
      },
    };

    const mockSessionResponse = {
      data: {
        id: 'sess-1',
        visitor_token: 'vis_token_123',
        active_conversation_id: 'conv-1',
      },
    };

    const mockStartResponse = {
      data: {
        conversation: { id: 'conv-1', status: 'bot' },
        messages: [],
      },
    };

    const sendMessageMock = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          message: 'Mensaje enviado.',
          data: {
            id: 'msg-in-1',
            conversation_id: 'conv-1',
            sender_type: 'contact',
            direction: 'inbound',
            content: 'Hola desde el test',
            sent_at: '2026-10-04T12:05:00Z',
          },
        }),
        { status: 201 }
      )
    );

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: any) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('/embed')) {
        return new Response(JSON.stringify(mockEmbedResponse), { status: 200 });
      }
      if (url.includes('/sessions')) {
        return new Response(JSON.stringify(mockSessionResponse), { status: 200 });
      }
      if (url.includes('/conversations/start')) {
        return new Response(JSON.stringify(mockStartResponse), { status: 200 });
      }
      if (url.includes('/messages')) {
        return sendMessageMock();
      }
      return new Response(JSON.stringify({}), { status: 200 });
    });

    render(<WebchatApp />);

    await waitFor(() => {
      expect(screen.getByPlaceholderText('Escribe tu mensaje...')).toBeInTheDocument();
    });

    const input = screen.getByPlaceholderText('Escribe tu mensaje...');
    fireEvent.change(input, { target: { value: 'Hola desde el test' } });

    expect(screen.getByText('18 / 4000')).toBeInTheDocument();

    const sendBtn = screen.getByRole('button', { name: /enviar mensaje/i });
    fireEvent.click(sendBtn);

    await waitFor(() => {
      expect(sendMessageMock).toHaveBeenCalled();
    });
  });

  it('shows an agent reply received through the conversation realtime channel', async () => {
    window.history.replaceState({}, '', '/widget.html?channel=test_public_key');

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: any) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('/embed')) {
        return new Response(JSON.stringify({
          data: { embed_token: 'embed-1', settings: { display_name: 'Chat Bot' } },
        }), { status: 200 });
      }
      if (url.includes('/sessions')) {
        return new Response(JSON.stringify({
          data: { id: 'session-1', visitor_token: 'visitor-1', active_conversation_id: 'conv-realtime-1' },
        }), { status: 201 });
      }
      if (url.includes('/conversations/start')) {
        return new Response(JSON.stringify({
          data: { conversation: { id: 'conv-realtime-1', status: 'assigned' }, messages: [] },
        }), { status: 201 });
      }
      return new Response(JSON.stringify({ data: [] }), { status: 200 });
    });

    render(<WebchatApp />);

    await waitFor(() => {
      expect(echoMock.listeners['webchat.conversation.conv-realtime-1:.MessageCreated']).toBeTypeOf('function');
    });

    act(() => {
      echoMock.listeners['webchat.conversation.conv-realtime-1:.MessageCreated']({
        id: 'agent-message-1',
        conversation_id: 'conv-realtime-1',
        sender_type: 'agent',
        direction: 'outbound',
        message_type: 'text',
        visibility: 'public',
        content: 'Respuesta del agente en tiempo real',
        sent_at: '2026-11-16T12:00:00Z',
      });
    });

    expect(await screen.findByText('Respuesta del agente en tiempo real')).toBeInTheDocument();
  });

  it('displays human handoff banner when status is waiting_agent', async () => {
    window.history.replaceState({}, '', '/widget.html?channel=test_public_key');

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: any) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('/embed')) {
        return new Response(
          JSON.stringify({
            data: {
              embed_token: 'tok_1',
              settings: { display_name: 'Chat Bot', accent_color: '#2563eb' },
            },
          }),
          { status: 200 }
        );
      }
      if (url.includes('/sessions')) {
        return new Response(
          JSON.stringify({
            data: { id: 's-1', visitor_token: 'vis-1', active_conversation_id: 'c-1' },
          }),
          { status: 200 }
        );
      }
      if (url.includes('/conversations/start')) {
        return new Response(
          JSON.stringify({
            data: {
              conversation: { id: 'c-1', status: 'waiting_agent' },
              messages: [],
            },
          }),
          { status: 200 }
        );
      }
      return new Response('{}', { status: 200 });
    });

    render(<WebchatApp />);

    await waitFor(() => {
      expect(
        screen.getByText(/Te estamos conectando con un agente.../i)
      ).toBeInTheDocument();
    });
  });

  it('handles crypto.randomUUID undefined in non-secure HTTP contexts safely and sends message', async () => {
    window.history.replaceState({}, '', '/widget.html?channel=test_public_key');

    // Simulate non-secure context where crypto.randomUUID does not exist
    const originalRandomUUID = (globalThis.crypto as any).randomUUID;
    delete (globalThis.crypto as any).randomUUID;

    const sendMessageMock = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            id: 'msg-in-fallback-1',
            conversation_id: 'c-1',
            sender_type: 'contact',
            direction: 'inbound',
            content: 'Probando en HTTP safari',
            sent_at: '2026-10-04T12:05:00Z',
          },
        }),
        { status: 201 }
      )
    );

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: any) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('/embed')) {
        return new Response(JSON.stringify({ data: { embed_token: 'tok_1', settings: { display_name: 'Chat Bot' } } }), { status: 200 });
      }
      if (url.includes('/sessions')) {
        return new Response(JSON.stringify({ data: { id: 's-1', visitor_token: 'vis-1', active_conversation_id: 'c-1' } }), { status: 200 });
      }
      if (url.includes('/conversations/start')) {
        return new Response(JSON.stringify({ data: { conversation: { id: 'c-1', status: 'bot' }, messages: [] } }), { status: 200 });
      }
      if (url.includes('/messages')) {
        return sendMessageMock();
      }
      return new Response('{}', { status: 200 });
    });

    try {
      render(<WebchatApp />);

      await waitFor(() => {
        expect(screen.getByPlaceholderText('Escribe tu mensaje...')).toBeInTheDocument();
      });

      const input = screen.getByPlaceholderText('Escribe tu mensaje...');
      fireEvent.change(input, { target: { value: 'Probando en HTTP safari' } });

      const sendBtn = screen.getByRole('button', { name: /enviar mensaje/i });
      fireEvent.click(sendBtn);

      await waitFor(() => {
        expect(sendMessageMock).toHaveBeenCalled();
        expect(input).not.toBeDisabled();
      });
    } finally {
      (globalThis.crypto as any).randomUUID = originalRandomUUID;
    }
  });

  it('restores input text and does not deadlock input if server fails to send message', async () => {
    window.history.replaceState({}, '', '/widget.html?channel=test_public_key');

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: any) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('/embed')) {
        return new Response(JSON.stringify({ data: { embed_token: 'tok_1', settings: { display_name: 'Chat Bot' } } }), { status: 200 });
      }
      if (url.includes('/sessions')) {
        return new Response(JSON.stringify({ data: { id: 's-1', visitor_token: 'vis-1', active_conversation_id: 'c-1' } }), { status: 200 });
      }
      if (url.includes('/conversations/start')) {
        return new Response(JSON.stringify({ data: { conversation: { id: 'c-1', status: 'bot' }, messages: [] } }), { status: 200 });
      }
      if (url.includes('/messages')) {
        return new Response(JSON.stringify({ message: 'Error en el servidor.' }), { status: 500 });
      }
      return new Response('{}', { status: 200 });
    });

    render(<WebchatApp />);

    await waitFor(() => {
      expect(screen.getByPlaceholderText('Escribe tu mensaje...')).toBeInTheDocument();
    });

    const input = screen.getByPlaceholderText('Escribe tu mensaje...') as HTMLTextAreaElement;
    fireEvent.change(input, { target: { value: 'Mensaje que va a fallar' } });

    const sendBtn = screen.getByRole('button', { name: /enviar mensaje/i });
    fireEvent.click(sendBtn);

    // After failure, error alert is shown, input is not disabled, and input value is restored
    await waitFor(() => {
      expect(screen.getByText('Error en el servidor.')).toBeInTheDocument();
      expect(input.value).toBe('Mensaje que va a fallar');
      expect(input).not.toBeDisabled();
      expect(sendBtn).not.toBeDisabled();
    });
  });

  it('immediately adds bot responses returned by sendMessage endpoint to local state', async () => {
    window.history.replaceState({}, '', '/widget.html?channel=test_public_key');

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: any) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('/embed')) {
        return new Response(JSON.stringify({ data: { embed_token: 'tok_1', settings: { display_name: 'Chat Bot' } } }), { status: 200 });
      }
      if (url.includes('/sessions')) {
        return new Response(JSON.stringify({ data: { id: 's-1', visitor_token: 'vis-1', active_conversation_id: 'c-1' } }), { status: 200 });
      }
      if (url.includes('/conversations/start')) {
        return new Response(JSON.stringify({
          data: {
            conversation: { id: 'c-1', status: 'bot' },
            messages: [
              {
                id: 'msg-bot-1',
                sender_type: 'bot',
                direction: 'outbound',
                content: '¿Cuál es tu nombre?',
                sent_at: '2026-10-04T12:00:00Z',
              },
            ],
          },
        }), { status: 200 });
      }
      if (url.includes('/messages')) {
        return new Response(JSON.stringify({
          data: {
            id: 'msg-user-1',
            sender_type: 'contact',
            direction: 'inbound',
            content: 'Carlos',
            sent_at: '2026-10-04T12:01:00Z',
          },
          messages: [
            {
              id: 'msg-bot-1',
              sender_type: 'bot',
              direction: 'outbound',
              content: '¿Cuál es tu nombre?',
              sent_at: '2026-10-04T12:00:00Z',
            },
            {
              id: 'msg-user-1',
              sender_type: 'contact',
              direction: 'inbound',
              content: 'Carlos',
              sent_at: '2026-10-04T12:01:00Z',
            },
            {
              id: 'msg-bot-2',
              sender_type: 'bot',
              direction: 'outbound',
              content: 'Mucho gusto, Carlos.',
              sent_at: '2026-10-04T12:01:01Z',
            },
          ],
        }), { status: 201 });
      }
      return new Response('{}', { status: 200 });
    });

    render(<WebchatApp />);

    await waitFor(() => {
      expect(screen.getByText('¿Cuál es tu nombre?')).toBeInTheDocument();
    });

    const input = screen.getByPlaceholderText('Escribe tu mensaje...');
    fireEvent.change(input, { target: { value: 'Carlos' } });

    const sendBtn = screen.getByRole('button', { name: /enviar mensaje/i });
    fireEvent.click(sendBtn);

    // Bot response is immediately rendered without needing websocket arrival
    await waitFor(() => {
      expect(screen.getByText('Mucho gusto, Carlos.')).toBeInTheDocument();
    });
  });

  it('sortAndDeduplicateWebchatMessages places inbound visitor message strictly before simultaneous bot messages', () => {
    const simultaneousTimestamp = '2026-11-04T02:00:00.000Z';

    const rawMessages: WebchatMessage[] = [
      {
        id: 'bot-msg-1',
        sender_type: 'bot',
        direction: 'outbound',
        message_type: 'text',
        content: 'Mucho gusto, Luis.',
        sent_at: simultaneousTimestamp,
        created_at: simultaneousTimestamp,
      },
      {
        id: 'bot-msg-2',
        sender_type: 'bot',
        direction: 'outbound',
        message_type: 'text',
        content: '¿Deseas hablar con un agente humano?',
        sent_at: simultaneousTimestamp,
        created_at: simultaneousTimestamp,
      },
      {
        id: 'visitor-msg-1',
        sender_type: 'contact',
        direction: 'inbound',
        message_type: 'text',
        content: 'Luis',
        sent_at: simultaneousTimestamp,
        created_at: simultaneousTimestamp,
      },
    ];

    const sorted = sortAndDeduplicateWebchatMessages(rawMessages);

    // Visitor message MUST come first, followed by the bot messages
    expect(sorted[0].content).toBe('Luis');
    expect(sorted[0].sender_type).toBe('contact');
    expect(sorted[1].content).toBe('Mucho gusto, Luis.');
    expect(sorted[2].content).toBe('¿Deseas hablar con un agente humano?');
  });

  it('renders visitor message visually above the bot response when submitted in widget', async () => {
    window.history.replaceState({}, '', '/widget.html?channel=test_public_key');

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: any) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('/embed')) {
        return new Response(JSON.stringify({ data: { embed_token: 'tok_1', settings: { display_name: 'Chat Bot' } } }), { status: 200 });
      }
      if (url.includes('/sessions')) {
        return new Response(JSON.stringify({ data: { id: 's-1', visitor_token: 'vis-1', active_conversation_id: 'c-1' } }), { status: 200 });
      }
      if (url.includes('/conversations/start')) {
        return new Response(JSON.stringify({
          data: {
            conversation: { id: 'c-1', status: 'bot' },
            messages: [
              {
                id: 'msg-bot-q',
                sender_type: 'bot',
                direction: 'outbound',
                content: '¿Cuál es tu nombre?',
                sent_at: '2026-11-04T02:00:00Z',
              },
            ],
          },
        }), { status: 200 });
      }
      if (url.includes('/messages')) {
        return new Response(JSON.stringify({
          data: {
            id: 'msg-user-luis',
            client_message_id: 'temp-luis',
            sender_type: 'contact',
            direction: 'inbound',
            content: 'Luis',
            sent_at: '2026-11-04T02:00:01Z',
          },
          messages: [
            {
              id: 'msg-bot-q',
              sender_type: 'bot',
              direction: 'outbound',
              content: '¿Cuál es tu nombre?',
              sent_at: '2026-11-04T02:00:00Z',
            },
            {
              id: 'msg-user-luis',
              client_message_id: 'temp-luis',
              sender_type: 'contact',
              direction: 'inbound',
              content: 'Luis',
              sent_at: '2026-11-04T02:00:01Z',
            },
            {
              id: 'msg-bot-greet',
              sender_type: 'bot',
              direction: 'outbound',
              content: 'Mucho gusto, Luis.',
              sent_at: '2026-11-04T02:00:01Z',
            },
          ],
        }), { status: 201 });
      }
      return new Response('{}', { status: 200 });
    });

    render(<WebchatApp />);

    await waitFor(() => {
      expect(screen.getByText('¿Cuál es tu nombre?')).toBeInTheDocument();
    });

    const input = screen.getByPlaceholderText('Escribe tu mensaje...');
    fireEvent.change(input, { target: { value: 'Luis' } });

    const sendBtn = screen.getByRole('button', { name: /enviar mensaje/i });
    fireEvent.click(sendBtn);

    await waitFor(() => {
      expect(screen.getByText('Mucho gusto, Luis.')).toBeInTheDocument();
    });

    // Check DOM order: 'Luis' must appear BEFORE 'Mucho gusto, Luis.'
    const visitorEl = screen.getByText('Luis');
    const botEl = screen.getByText('Mucho gusto, Luis.');

    // compareDocumentPosition: DOCUMENT_POSITION_FOLLOWING (4) means botEl is after visitorEl
    expect(visitorEl.compareDocumentPosition(botEl) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('renders session divider correctly and preserves thread history when starting new conversation', async () => {
    window.history.replaceState({}, '', '/widget.html?channel=test_public_key');

    let startCount = 0;

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: any) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('/embed')) {
        return new Response(JSON.stringify({ data: { embed_token: 'tok_1', settings: { display_name: 'Chat Bot' } } }), { status: 200 });
      }
      if (url.includes('/sessions')) {
        return new Response(JSON.stringify({
          data: {
            id: 's-1',
            visitor_token: 'vis-1',
            active_conversation: { id: 'c-1', status: 'closed' },
          },
        }), { status: 200 });
      }
      if (url.includes('/conversations/current')) {
        return new Response(JSON.stringify({
          data: {
            conversation: { id: 'c-1', status: 'closed' },
            messages: [
              {
                id: 'msg-session1',
                sender_type: 'bot',
                direction: 'outbound',
                content: 'Mensaje de la primera sesión',
                sent_at: '2026-11-04T01:00:00Z',
              },
            ],
          },
        }), { status: 200 });
      }
      if (url.includes('/conversations/start')) {
        startCount++;
        return new Response(JSON.stringify({
          data: {
            conversation: { id: 'c-1', status: 'bot' },
            messages: [
              {
                id: 'msg-session1',
                sender_type: 'bot',
                direction: 'outbound',
                content: 'Mensaje de la primera sesión',
                sent_at: '2026-11-04T01:00:00Z',
              },
              {
                id: 'msg-divider',
                sender_type: 'system',
                direction: 'outbound',
                message_type: 'system',
                visibility: 'external',
                content: '── Sesión finalizada ──',
                metadata: { event: 'session_divider', timestamp: '2026-11-04T01:30:00Z' },
                sent_at: '2026-11-04T01:30:00Z',
              },
              {
                id: 'msg-session2',
                sender_type: 'bot',
                direction: 'outbound',
                content: '¡Hola de nuevo! Iniciando segunda sesión.',
                sent_at: '2026-11-04T01:30:01Z',
              },
            ],
          },
        }), { status: 201 });
      }
      return new Response('{}', { status: 200 });
    });

    render(<WebchatApp />);

    // 1. Initial load shows closed banner with "Iniciar nueva conversación"
    await waitFor(() => {
      expect(screen.getByText('Esta conversación ha finalizado.')).toBeInTheDocument();
      expect(screen.getByText('Mensaje de la primera sesión')).toBeInTheDocument();
    });

    const restartBtn = screen.getByRole('button', { name: /iniciar nueva conversación/i });
    expect(restartBtn).toBeInTheDocument();

    // 2. Click "Iniciar nueva conversación"
    fireEvent.click(restartBtn);

    // 3. Verify previous message is preserved, session divider is displayed, and new message is shown
    await waitFor(() => {
      expect(screen.getByText('Mensaje de la primera sesión')).toBeInTheDocument();
      expect(screen.getByTestId('session-divider')).toBeInTheDocument();
      expect(screen.getByText(/Conversación anterior finalizada/i)).toBeInTheDocument();
      expect(screen.getByText('¡Hola de nuevo! Iniciando segunda sesión.')).toBeInTheDocument();
    });

    expect(startCount).toBe(1);

    // Verify ordering
    const session1El = screen.getByText('Mensaje de la primera sesión');
    const dividerEl = screen.getByTestId('session-divider');
    const session2El = screen.getByText('¡Hola de nuevo! Iniciando segunda sesión.');

    expect(session1El.compareDocumentPosition(dividerEl) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(dividerEl.compareDocumentPosition(session2El) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('renders interactive buttons from payload.options and sends choice on click', async () => {
    window.history.replaceState({}, '', '/widget.html?channel=test_public_key');

    const mockEmbedResponse = {
      data: {
        embed_token: 'signed_embed_token_123',
        settings: {
          display_name: 'Asistente Conexa',
          accent_color: '#2563eb',
          launcher_position: 'bottom-right',
        },
      },
    };

    const mockSessionResponse = {
      data: {
        id: 'sess-uuid-interactive',
        channel_id: 'chan-uuid-interactive',
        contact_id: 'cont-uuid-interactive',
        visitor_token: 'visitor_raw_token_interactive',
        origin: 'http://localhost:3000',
        active_conversation_id: 'conv-uuid-interactive',
        expires_at: '2026-10-05T00:00:00Z',
      },
    };

    const initialBotMessage: WebchatMessage = {
      id: 'msg-validation-retry',
      sender_type: 'bot',
      direction: 'outbound',
      message_type: 'interactive',
      content: 'Por favor, elige una de las opciones válidas: Sí, No.',
      payload: {
        options: [
          { id: '1', title: 'Sí' },
          { id: '2', title: 'No' },
        ],
        validation_error: true,
      },
      sent_at: '2026-10-01T12:00:00Z',
      created_at: '2026-10-01T12:00:00Z',
    };

    const mockStartResponse = {
      data: {
        conversation: {
          id: 'conv-uuid-interactive',
          channel_id: 'chan-uuid-interactive',
          contact_id: 'cont-uuid-interactive',
          status: 'bot',
          started_at: '2026-10-01T12:00:00Z',
          last_message_at: '2026-10-01T12:00:00Z',
        },
        messages: [initialBotMessage],
        has_more: false,
        oldest_message_id: 'msg-validation-retry',
      },
    };

    let sentPayload: any = null;

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (url: any, opts: any) => {
      const urlStr = String(url);
      if (urlStr.includes('/embed')) {
        return new Response(JSON.stringify(mockEmbedResponse), { status: 200 });
      }
      if (urlStr.includes('/sessions')) {
        return new Response(JSON.stringify(mockSessionResponse), { status: 200 });
      }
      if (urlStr.includes('/conversations/start')) {
        return new Response(JSON.stringify(mockStartResponse), { status: 200 });
      }
      if (urlStr.includes('/messages') && opts?.method === 'POST') {
        sentPayload = JSON.parse(opts.body);
        return new Response(JSON.stringify({
          data: {
            id: 'confirmed-msg-1',
            sender_type: 'contact',
            direction: 'inbound',
            message_type: 'text',
            content: sentPayload.content,
            sent_at: '2026-10-01T12:00:01Z',
            created_at: '2026-10-01T12:00:01Z',
          },
          messages: [],
        }), { status: 200 });
      }
      return new Response('{}', { status: 200 });
    });

    render(<WebchatApp />);

    // 1. Verify message text and interactive option buttons are rendered
    await waitFor(() => {
      expect(screen.getByText('Por favor, elige una de las opciones válidas: Sí, No.')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Sí' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'No' })).toBeInTheDocument();
    });

    // 2. Click the 'Sí' option button
    const siBtn = screen.getByRole('button', { name: 'Sí' });
    fireEvent.click(siBtn);

    // 3. Verify message was dispatched with content 'Sí'
    await waitFor(() => {
      expect(sentPayload).not.toBeNull();
      expect(sentPayload.content).toBe('Sí');
    });

    // 4. Verify scroll anchor element exists at the end of the messages thread
    const anchor = document.querySelector('.h-0.w-full.shrink-0[aria-hidden="true"]');
    expect(anchor).toBeInTheDocument();
  });

  it('renders audio message with player and mic recording button in webchat', async () => {
    window.history.replaceState({}, '', '/widget.html?channel=test_public_key');

    const mockEmbedResponse = {
      data: {
        embed_token: 'signed_embed_token_123',
        settings: {
          display_name: 'Asistente Audio',
          accent_color: '#2563eb',
          launcher_position: 'bottom-right',
        },
      },
    };

    const mockSessionResponse = {
      data: {
        id: 'session_audio_123',
        visitor_token: 'visitor_audio_token',
        active_conversation_id: 'conv_audio_123',
        expires_at: '2026-10-02T12:00:00Z',
      },
    };

    const mockStartResponse = {
      data: {
        conversation: {
          id: 'conv_audio_123',
          status: 'bot',
          started_at: '2026-10-01T12:00:00Z',
          last_message_at: '2026-10-01T12:00:00Z',
        },
        messages: [
          {
            id: 'msg_audio_1',
            client_message_id: 'msg_audio_client_1',
            sender_type: 'bot',
            direction: 'outbound',
            message_type: 'audio',
            content: '🎵 Nota de voz',
            payload: {
              attachment: {
                url: 'http://localhost/storage/attachments/voice-welcome.ogg',
                file_name: 'voice-welcome.ogg',
                mime_type: 'audio/ogg',
                size_bytes: 48000,
                is_voice_note: true,
              },
            },
            sent_at: '2026-10-01T12:00:00Z',
            created_at: '2026-10-01T12:00:00Z',
          },
        ],
      },
    };

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: any) => {
      const url = typeof input === 'string' ? input : input.url;
      if (url.includes('/embed')) {
        return new Response(JSON.stringify(mockEmbedResponse), { status: 200 });
      }
      if (url.includes('/sessions')) {
        return new Response(JSON.stringify(mockSessionResponse), { status: 200 });
      }
      if (url.includes('/conversations/start')) {
        return new Response(JSON.stringify(mockStartResponse), { status: 200 });
      }
      return new Response('{}', { status: 200 });
    });

    render(<WebchatApp />);

    await waitFor(() => {
      expect(screen.getByTestId('audio-message-player')).toBeInTheDocument();
      expect(screen.getByTestId('audio-play-pause-btn')).toBeInTheDocument();
      expect(screen.getByTestId('audio-speed-btn')).toBeInTheDocument();
      expect(screen.getByTestId('audio-progress-bar')).toBeInTheDocument();
      expect(screen.getByTestId('audio-download-btn')).toBeInTheDocument();
      expect(screen.getByTestId('webchat-mic-btn')).toBeInTheDocument();
    });
  });
});
