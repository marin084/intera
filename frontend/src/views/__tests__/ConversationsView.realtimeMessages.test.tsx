import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { ConversationsView } from '../ConversationsView';
import { api } from '../../services/api';

const echoMock = vi.hoisted(() => ({
  listeners: {} as Record<string, (event: any) => void>,
  privateChannels: [] as string[],
}));

vi.mock('../../context/useAuth', () => ({
  useAuth: () => ({
    user: { id: 42, name: 'Agente de prueba', email: 'agent@example.com' },
    hasPermission: (permission: string) =>
      ['conversations.view_all', 'conversations.reply'].includes(permission),
    hasRole: () => false,
  }),
}));

vi.mock('../../services/echo', () => ({
  createEcho: () => ({
    connector: { pusher: { connection: { bind: vi.fn() } } },
    private: (channelName: string) => {
      echoMock.privateChannels.push(channelName);
      const channel = {
        listen: (eventName: string, callback: (event: any) => void) => {
          echoMock.listeners[`${channelName}:${eventName}`] = callback;
          return channel;
        },
        stopListening: vi.fn(),
      };
      return channel;
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
    getConversationMessages: vi.fn(),
    markConversationAsRead: vi.fn(),
    getCannedResponses: vi.fn().mockResolvedValue({ data: [] }),
  },
}));

describe('ConversationsView realtime message delivery', () => {
  const conversation = {
    id: 'conv-realtime-1',
    status: 'assigned',
    assigned_user_id: 42,
    channel_id: 'channel-webchat-1',
    contact_id: 'contact-1',
    contact: { id: 'contact-1', display_name: 'Visitante en vivo' },
    channel: { id: 'channel-webchat-1', name: 'Webchat', type: 'webchat' },
    unread_count: 0,
    messages: [],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    Object.keys(echoMock.listeners).forEach((key) => delete echoMock.listeners[key]);
    echoMock.privateChannels.length = 0;
    (api.getConversations as any).mockResolvedValue({
      data: [conversation],
      current_page: 1,
      last_page: 1,
      total: 1,
      counts: { all: 1, waiting_agent: 0, assigned: 1, closed: 0 },
    });
    (api.getUsers as any).mockResolvedValue({ data: [] });
    (api.getChannels as any).mockResolvedValue({ data: [] });
    (api.getConversation as any).mockResolvedValue({
      data: { ...conversation, messages: [] },
      messages: { data: [], has_more: false, oldest_message_id: null },
    });
    (api.getConversationExecution as any).mockResolvedValue({ data: null });
    (api.markConversationAsRead as any).mockResolvedValue({ unread_count: 0 });
  });

  it('adds an incoming visitor message to the open agent conversation immediately', async () => {
    render(<ConversationsView />);

    const conversationItem = await screen.findByTestId('conversation-item');
    fireEvent.click(conversationItem);

    await waitFor(() => {
      expect(api.getConversation).toHaveBeenCalledWith('conv-realtime-1');
    });
    await waitFor(() => {
      expect(echoMock.privateChannels).toContain('conversation.conv-realtime-1');
    });
    expect(echoMock.listeners['conversation.conv-realtime-1:.MessageCreated']).toBeTypeOf('function');
    expect(echoMock.privateChannels.filter((channel) => channel === 'inbox')).toHaveLength(1);

    act(() => {
      echoMock.listeners['conversation.conv-realtime-1:.MessageCreated']({
        id: 'visitor-message-1',
        conversation_id: 'conv-realtime-1',
        sender_type: 'contact',
        direction: 'inbound',
        message_type: 'text',
        visibility: 'public',
        content: 'Mensaje del visitante actualizado en vivo',
        metadata: {},
        sent_at: '2026-11-16T12:00:00Z',
        created_at: '2026-11-16T12:00:00Z',
      });
    });

    expect(await screen.findByText('Mensaje del visitante actualizado en vivo')).toBeInTheDocument();
    expect(echoMock.privateChannels.filter((channel) => channel === 'inbox')).toHaveLength(1);
  });
});
