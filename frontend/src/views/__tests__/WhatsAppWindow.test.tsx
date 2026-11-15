import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within, act } from '@testing-library/react';
import { ConversationsView } from '../ConversationsView';
import { api } from '../../services/api';

const echoListeners: Record<string, ((event: any) => void)[]> = {};

vi.mock('../../context/useAuth', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai' },
    hasPermission: () => true,
  }),
}));

vi.mock('../../services/echo', () => ({
  createEcho: () => ({
    private: (channel: string) => {
      const channelObj = {
        listen: (event: string, cb: (event: any) => void) => {
          const key = `${channel}:${event}`;
          echoListeners[key] = echoListeners[key] || [];
          echoListeners[key].push(cb);
          return channelObj;
        },
        stopListening: vi.fn(),
      };
      return channelObj;
    },
    leave: vi.fn(),
    disconnect: vi.fn(),
  }),
}));

vi.mock('../../services/api', () => ({
  api: {
    getConversations: vi.fn(),
    getUsers: vi.fn(),
    getChannels: vi.fn(),
    getConversation: vi.fn(),
    getConversationExecution: vi.fn(),
    markConversationAsRead: vi.fn(),
    getCannedResponses: vi.fn().mockResolvedValue({ data: [] }),
    sendMessage: vi.fn(),
    toggleConversationBot: vi.fn(),
    restartConversationFlow: vi.fn(),
    getWhatsAppTemplates: vi.fn().mockResolvedValue({ data: [] }),
    sendWhatsAppTemplate: vi.fn().mockResolvedValue({ data: {} }),
  },
}));

describe('WhatsApp 24-Hour Service Window in ConversationsView', () => {
  const activeWhatsAppConversation = {
    id: 'conv-wa-active',
    status: 'assigned',
    assigned_user_id: 1,
    bot_enabled: false,
    channel_id: 'ch-wa',
    contact_id: 'contact-wa-1',
    started_at: '2026-11-06T10:00:00Z',
    last_message_at: '2026-11-06T14:00:00Z',
    closed_at: null,
    contact: {
      id: 'contact-wa-1',
      display_name: 'Valeria Morales',
      first_name: 'Valeria',
      last_name: 'Morales',
      email: 'valeria@example.com',
      phone: '+5215577665544',
    },
    channel: {
      id: 'ch-wa',
      name: 'WhatsApp Soporte',
      type: 'whatsapp',
    },
    whatsapp_window: {
      is_applicable: true,
      is_open: true,
      last_inbound_at: '2026-11-06T12:00:00Z',
      expires_at: '2026-11-07T12:00:00Z',
      remaining_seconds: 72000,
    },
    unread_count: 0,
    messages: [
      {
        id: 'msg-wa-1',
        conversation_id: 'conv-wa-active',
        content: 'Hola, buenas tardes',
        sender_type: 'contact',
        direction: 'inbound',
        message_type: 'text',
        sent_at: '2026-11-06T12:00:00Z',
      },
    ],
  };

  const expiredWhatsAppConversation = {
    ...activeWhatsAppConversation,
    id: 'conv-wa-expired',
    whatsapp_window: {
      is_applicable: true,
      is_open: false,
      last_inbound_at: '2026-11-04T10:00:00Z',
      expires_at: '2026-11-05T10:00:00Z',
      remaining_seconds: 0,
    },
  };

  const webchatConversation = {
    id: 'conv-webchat-1',
    status: 'assigned',
    assigned_user_id: 1,
    bot_enabled: false,
    channel_id: 'ch-web',
    contact_id: 'contact-web-1',
    started_at: '2026-11-01T10:00:00Z',
    last_message_at: '2026-11-01T14:00:00Z',
    closed_at: null,
    contact: {
      id: 'contact-web-1',
      display_name: 'David Gomez',
      email: 'david@example.com',
    },
    channel: {
      id: 'ch-web',
      name: 'Webchat Portal',
      type: 'webchat',
    },
    whatsapp_window: {
      is_applicable: false,
      is_open: true,
      last_inbound_at: null,
      expires_at: null,
      remaining_seconds: null,
    },
    unread_count: 0,
    messages: [],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    Object.keys(echoListeners).forEach((key) => delete echoListeners[key]);
    (api.getUsers as any).mockResolvedValue({ data: [] });
    (api.getChannels as any).mockResolvedValue({
      data: [
        { id: 'ch-wa', name: 'WhatsApp Soporte' },
        { id: 'ch-web', name: 'Webchat Portal' },
      ],
    });
    (api.getConversationExecution as any).mockRejectedValue(new Error('no execution'));
    (api.markConversationAsRead as any).mockResolvedValue({});
    (api.getCannedResponses as any).mockResolvedValue({ data: [] });
  });

  it('renders active WhatsApp window badge and enables composer when window is open', async () => {
    (api.getConversations as any).mockResolvedValue({
      data: [activeWhatsAppConversation],
      current_page: 1,
      last_page: 1,
      total: 1,
    });
    (api.getConversation as any).mockResolvedValue({ data: activeWhatsAppConversation });

    render(<ConversationsView />);

    await waitFor(() => {
      expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByTestId('conversation-item'));

    await waitFor(() => {
      expect(screen.getByTestId('whatsapp-window-active-badge')).toBeInTheDocument();
    });

    expect(screen.getByTestId('whatsapp-window-active-badge')).toHaveTextContent(/Ventana WhatsApp activa/i);
    expect(screen.queryByTestId('whatsapp-24h-expired-banner')).not.toBeInTheDocument();

    const input = screen.getByTestId('agent-reply-input');
    expect(input).not.toBeDisabled();
    expect(input.getAttribute('placeholder')).toContain('Escribe una respuesta como agente');
  });

  it('renders expired banner and blocks composer when WhatsApp 24h window is expired', async () => {
    (api.getConversations as any).mockResolvedValue({
      data: [expiredWhatsAppConversation],
      current_page: 1,
      last_page: 1,
      total: 1,
    });
    (api.getConversation as any).mockResolvedValue({ data: expiredWhatsAppConversation });

    render(<ConversationsView />);

    await waitFor(() => {
      expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByTestId('conversation-item'));

    await waitFor(() => {
      expect(screen.getByTestId('whatsapp-window-expired-badge')).toBeInTheDocument();
      expect(screen.getByTestId('whatsapp-24h-expired-banner')).toBeInTheDocument();
    });

    expect(screen.getByText(/La conversación se cerró luego de 24 horas del último mensaje del usuario/i)).toBeInTheDocument();
    expect(screen.getByTestId('reopen-with-template-btn')).toBeInTheDocument();

    // Verify input and submit buttons are disabled
    const input = screen.getByTestId('agent-reply-input');
    expect(input).toBeDisabled();
    expect(input.getAttribute('placeholder')).toContain('Ventana de WhatsApp de 24h vencida');

    const submitBtn = screen.getByTestId('agent-reply-submit');
    expect(submitBtn).toBeDisabled();
  });

  it('clicking reopen button opens template modal with preview and policy details', async () => {
    (api.getConversations as any).mockResolvedValue({
      data: [expiredWhatsAppConversation],
      current_page: 1,
      last_page: 1,
      total: 1,
    });
    (api.getConversation as any).mockResolvedValue({ data: expiredWhatsAppConversation });
    (api.getWhatsAppTemplates as any).mockResolvedValue({
      data: [
        {
          name: 'seguimiento_consulta_soporte',
          category: 'UTILITY',
          language: 'es',
          status: 'APPROVED',
          components: [
            {
              type: 'BODY',
              text: 'Hola {{1}}, nos comunicamos para dar seguimiento a tu consulta pendiente.',
            },
          ],
        },
      ],
    });

    render(<ConversationsView />);

    await waitFor(() => {
      expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByTestId('conversation-item'));

    await waitFor(() => {
      expect(screen.getByTestId('reopen-with-template-btn')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByTestId('reopen-with-template-btn'));

    await waitFor(() => {
      expect(screen.getByTestId('whatsapp-template-modal')).toBeInTheDocument();
    });

    const modal = screen.getByTestId('whatsapp-template-modal');
    expect(within(modal).getByText('Reabrir conversación con Plantilla')).toBeInTheDocument();
    expect(within(modal).getByText(/Política de Meta/i)).toBeInTheDocument();
    expect(within(modal).getAllByText(/seguimiento_consulta_soporte/i).length).toBeGreaterThan(0);
    expect(within(modal).getByText(/Valeria Morales/i)).toBeInTheDocument();

    // Close modal
    fireEvent.click(within(modal).getByTestId('close-template-modal-btn'));
    await waitFor(() => {
      expect(screen.queryByTestId('whatsapp-template-modal')).not.toBeInTheDocument();
    });
  });

  it('webchat channel is never blocked by 24-hour service window', async () => {
    (api.getConversations as any).mockResolvedValue({
      data: [webchatConversation],
      current_page: 1,
      last_page: 1,
      total: 1,
    });
    (api.getConversation as any).mockResolvedValue({ data: webchatConversation });

    render(<ConversationsView />);

    await waitFor(() => {
      expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByTestId('conversation-item'));

    await waitFor(() => {
      expect(screen.getByTestId('agent-reply-input')).toBeInTheDocument();
    });

    expect(screen.queryByTestId('whatsapp-24h-expired-banner')).not.toBeInTheDocument();
    expect(screen.queryByTestId('whatsapp-window-expired-badge')).not.toBeInTheDocument();

    const input = screen.getByTestId('agent-reply-input');
    expect(input).not.toBeDisabled();
  });

  it('allows authorized agent to reply from closed WhatsApp conversation when Meta window is active', async () => {
    const closedActiveConversation = {
      ...activeWhatsAppConversation,
      id: 'conv-wa-closed-active',
      status: 'closed',
      closed_at: '2026-11-06T13:00:00Z',
    };

    (api.getConversations as any).mockResolvedValue({
      data: [closedActiveConversation],
      current_page: 1,
      last_page: 1,
      total: 1,
    });
    (api.getConversation as any).mockResolvedValue({ data: closedActiveConversation });

    render(<ConversationsView />);

    await waitFor(() => {
      expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByTestId('conversation-item'));

    await waitFor(() => {
      expect(screen.getByTestId('whatsapp-window-active-badge')).toBeInTheDocument();
    });

    // Composer should be available and enabled for authorized agent, closed banner should NOT be rendered
    expect(screen.queryByTestId('conversation-closed-banner')).not.toBeInTheDocument();
    const input = screen.getByTestId('agent-reply-input');
    expect(input).not.toBeDisabled();
    expect(input.getAttribute('placeholder')).toContain('Escribe una respuesta como agente');
  });

  it('blocks composer with closed banner on closed WhatsApp conversation when window is expired', async () => {
    const closedExpiredConversation = {
      ...expiredWhatsAppConversation,
      id: 'conv-wa-closed-expired',
      status: 'closed',
      closed_at: '2026-11-05T12:00:00Z',
    };

    (api.getConversations as any).mockResolvedValue({
      data: [closedExpiredConversation],
      current_page: 1,
      last_page: 1,
      total: 1,
    });
    (api.getConversation as any).mockResolvedValue({ data: closedExpiredConversation });

    render(<ConversationsView />);

    await waitFor(() => {
      expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByTestId('conversation-item'));

    await waitFor(() => {
      expect(screen.getByTestId('conversation-closed-banner')).toBeInTheDocument();
    });

    // Reply input should not be shown
    expect(screen.queryByTestId('agent-reply-input')).not.toBeInTheDocument();
  });

  it('updates whatsapp_window remaining time in real time when .ConversationUpdated event arrives without switching conversation', async () => {
    (api.getConversations as any).mockResolvedValue({
      data: [activeWhatsAppConversation],
      current_page: 1,
      last_page: 1,
      total: 1,
    });
    (api.getConversation as any).mockResolvedValue({ data: activeWhatsAppConversation });

    render(<ConversationsView />);

    await waitFor(() => {
      expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByTestId('conversation-item'));

    await waitFor(() => {
      expect(screen.getByTestId('whatsapp-window-active-badge')).toBeInTheDocument();
    });

    // 72000 seconds = 20h 0m
    expect(screen.getByTestId('whatsapp-window-active-badge')).toHaveTextContent(/20h 0m/i);

    // Simulate incoming Meta message triggering .ConversationUpdated via echo on inbox channel with new remaining_seconds = 86400 (24h 0m)
    act(() => {
      const callbacks = echoListeners['inbox:.ConversationUpdated'] || [];
      callbacks.forEach((cb) =>
        cb({
          id: 'conv-wa-active',
          status: 'assigned',
          assigned_user_id: 1,
          whatsapp_window: {
            is_applicable: true,
            is_open: true,
            last_inbound_at: '2026-11-06T15:00:00Z',
            expires_at: '2026-11-07T15:00:00Z',
            remaining_seconds: 86400,
          },
        })
      );
    });

    // Verify counter immediately refreshed to 24h 0m without changing conversation
    await waitFor(() => {
      expect(screen.getByTestId('whatsapp-window-active-badge')).toHaveTextContent(/24h 0m/i);
    });
  });
});
