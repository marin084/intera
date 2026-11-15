import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, act } from '@testing-library/react';
import { ConversationsView } from '../ConversationsView';
import { api } from '../../services/api';

const echoListeners: Record<string, ((event: any) => void)[]> = {};

// Mock agent without 'conversations.view_all' (regular agent)
vi.mock('../../context/useAuth', () => ({
  useAuth: () => ({
    user: { id: 42, name: 'Agent Smith', email: 'agent@conexa.ai' },
    hasPermission: (perm: string) => perm === 'conversations.reply',
    hasRole: () => false,
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

describe('ConversationsView Agent Closed Filter and Closing Owner', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (api.getChannels as any).mockResolvedValue({ data: [] });
    (api.getUsers as any).mockResolvedValue({ data: [] });
  });

  const myClosedConversation = {
    id: 'conv-my-closed',
    status: 'closed',
    assigned_user_id: null,
    closing_assigned_user_id: 42, // Current agent
    bot_enabled: false,
    channel_id: 'ch-wa',
    contact_id: 'contact-1',
    started_at: '2026-11-06T10:00:00Z',
    last_message_at: '2026-11-06T14:00:00Z',
    closed_at: '2026-11-06T14:30:00Z',
    contact: {
      id: 'contact-1',
      display_name: 'Cliente Mio',
      email: 'mio@example.com',
      phone: '+5215500000001',
    },
    channel: {
      id: 'ch-wa',
      name: 'WhatsApp Ventas',
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
    messages: [],
  };

  const otherAgentClosedConversation = {
    id: 'conv-other-closed',
    status: 'closed',
    assigned_user_id: null,
    closing_assigned_user_id: 99, // Another agent
    bot_enabled: false,
    channel_id: 'ch-wa',
    contact_id: 'contact-2',
    started_at: '2026-11-06T10:00:00Z',
    last_message_at: '2026-11-06T14:00:00Z',
    closed_at: '2026-11-06T14:30:00Z',
    contact: {
      id: 'contact-2',
      display_name: 'Cliente Ajeno',
      email: 'ajeno@example.com',
      phone: '+5215500000002',
    },
    channel: {
      id: 'ch-wa',
      name: 'WhatsApp Ventas',
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
    messages: [],
  };

  const myAssignedConversation = {
    id: 'conv-my-assigned',
    status: 'assigned',
    assigned_user_id: 42,
    closing_assigned_user_id: null,
    bot_enabled: false,
    channel_id: 'ch-wa',
    contact_id: 'contact-3',
    started_at: '2026-11-06T10:00:00Z',
    last_message_at: '2026-11-06T14:00:00Z',
    closed_at: null,
    contact: {
      id: 'contact-3',
      display_name: 'Cliente Activo Mio',
      email: 'activo@example.com',
      phone: '+5215500000003',
    },
    channel: {
      id: 'ch-wa',
      name: 'WhatsApp Ventas',
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
    messages: [],
  };

  it('shows closed conversation where agent is closing_assigned_user_id despite assigned_user_id being null', async () => {
    (api.getConversations as any).mockResolvedValue({
      data: [myClosedConversation, otherAgentClosedConversation, myAssignedConversation],
      current_page: 1,
      last_page: 1,
      total: 3,
    });
    (api.getConversation as any).mockResolvedValue({ data: myClosedConversation });

    render(<ConversationsView />);

    await waitFor(() => {
      // My active conversation and my closed conversation should appear
      expect(screen.getByText('Cliente Mio')).toBeInTheDocument();
      expect(screen.getByText('Cliente Activo Mio')).toBeInTheDocument();
    });

    // Other agent's closed conversation MUST NOT appear
    expect(screen.queryByText('Cliente Ajeno')).not.toBeInTheDocument();
  });

  it('filters out closed conversations belonging to another closing agent on .ConversationUpdated', async () => {
    (api.getConversations as any).mockResolvedValue({
      data: [myAssignedConversation],
      current_page: 1,
      last_page: 1,
      total: 1,
    });
    (api.getConversation as any).mockResolvedValue({ data: myAssignedConversation });

    render(<ConversationsView />);

    await waitFor(() => {
      expect(screen.getByText('Cliente Activo Mio')).toBeInTheDocument();
    });

    // Simulate ConversationUpdated event closing another agent's conversation
    act(() => {
      const callbacks = echoListeners['inbox:.ConversationUpdated'] || [];
      callbacks.forEach((cb) =>
        cb({
          id: 'conv-other-closed',
          status: 'closed',
          assigned_user_id: null,
          closing_assigned_user_id: 99,
          contact: { display_name: 'Cliente Ajeno Evento' },
        })
      );
    });

    // Foreign closed conversation should not be added to the list
    expect(screen.queryByText('Cliente Ajeno Evento')).not.toBeInTheDocument();

    // Simulate ConversationUpdated for current agent's closed conversation
    act(() => {
      const callbacks = echoListeners['inbox:.ConversationUpdated'] || [];
      callbacks.forEach((cb) =>
        cb({
          id: 'conv-my-new-closed',
          status: 'closed',
          assigned_user_id: null,
          closing_assigned_user_id: 42,
          contact: { display_name: 'Cliente Mio Recien Cerrado' },
          whatsapp_window: { is_applicable: true, is_open: true },
        })
      );
    });

    // Agent's own closed conversation should be accepted and rendered
    await waitFor(() => {
      expect(screen.getByText('Cliente Mio Recien Cerrado')).toBeInTheDocument();
    });
  });
});
