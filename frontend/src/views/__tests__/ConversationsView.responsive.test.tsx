import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ConversationsView } from '../ConversationsView';
import { api } from '../../services/api';

vi.mock('../../context/useAuth', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai' },
    hasPermission: () => true,
  }),
}));

vi.mock('../../services/echo', () => ({
  createEcho: () => null,
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
  },
}));

describe('ConversationsView Responsive Behavior', () => {
  const mockConversations = [
    {
      id: 'conv-1',
      status: 'active',
      contact: {
        id: 'contact-1',
        display_name: 'Juan Perez',
        email: 'juan@example.com',
        phone: '+521234567890',
      },
      channel: {
        id: 'ch-1',
        name: 'WhatsApp Principal',
        type: 'whatsapp',
      },
      unread_count: 0,
      messages: [],
    },
  ];

  beforeEach(() => {
    vi.restoreAllMocks();
    (api.getConversations as any).mockResolvedValue({
      data: mockConversations,
      current_page: 1,
      last_page: 1,
      total: 1,
    });
    (api.getUsers as any).mockResolvedValue({ data: [] });
    (api.getChannels as any).mockResolvedValue({ data: [{ id: 'ch-1', name: 'WhatsApp Principal' }] });
    (api.getConversation as any).mockResolvedValue({ data: mockConversations[0] });
    (api.getConversationExecution as any).mockRejectedValue(new Error('no execution'));
    (api.markConversationAsRead as any).mockResolvedValue({});
  });

  const setupMatchMedia = (matches: boolean) => {
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: vi.fn().mockImplementation((query: string) => ({
        matches,
        media: query,
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
      })),
    });
  };

  it('renders both list and thread side by side on desktop (> 768px)', async () => {
    setupMatchMedia(false); // Desktop

    render(<ConversationsView />);

    await waitFor(() => {
      expect(screen.getByTestId('inbox-conversation-list-panel')).toBeInTheDocument();
      expect(screen.getByTestId('inbox-thread-panel')).toBeInTheDocument();
    });

    // On desktop, the back button should NOT be rendered even after selecting a conversation
    const convItem = screen.getByTestId('conversation-item');
    fireEvent.click(convItem);

    await waitFor(() => {
      expect(screen.getByText('Juan Perez')).toBeInTheDocument();
    });

    expect(screen.queryByTestId('back-to-inbox-btn')).not.toBeInTheDocument();
  });

  it('alternates between conversation list and thread panel on mobile (<= 768px)', async () => {
    setupMatchMedia(true); // Mobile

    render(<ConversationsView />);

    // Initially: list is visible, thread panel is not
    await waitFor(() => {
      expect(screen.getByTestId('inbox-conversation-list-panel')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('inbox-thread-panel')).not.toBeInTheDocument();

    // Select conversation
    const convItem = screen.getByTestId('conversation-item');
    fireEvent.click(convItem);

    // After selection: thread panel is visible with back button, list panel is hidden
    await waitFor(() => {
      expect(screen.getByTestId('inbox-thread-panel')).toBeInTheDocument();
      expect(screen.getByTestId('back-to-inbox-btn')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('inbox-conversation-list-panel')).not.toBeInTheDocument();

    // Click back button: returns to conversation list
    const backBtn = screen.getByTestId('back-to-inbox-btn');
    fireEvent.click(backBtn);

    await waitFor(() => {
      expect(screen.getByTestId('inbox-conversation-list-panel')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('inbox-thread-panel')).not.toBeInTheDocument();
  });

  it('renders only one row per contact in the inbox list and displays session dividers in thread', async () => {
    setupMatchMedia(false);

    // Mock multiple conversations for the same contact "Luis"
    const mockMultiConversations = [
      {
        id: 'conv-recent',
        status: 'waiting_agent',
        contact_id: 'contact-luis-1',
        contact: {
          id: 'contact-luis-1',
          display_name: 'Luis',
          email: 'luis@example.com',
          phone: '+521111111111',
        },
        channel: {
          id: 'ch-1',
          name: 'Webchat Principal',
          type: 'webchat',
        },
        unread_count: 0,
        messages: [
          {
            id: 'm-1',
            conversation_id: 'conv-recent',
            sender_type: 'contact',
            direction: 'inbound',
            message_type: 'text',
            content: 'Hola, primera sesión',
            sent_at: '2026-11-04T01:00:00Z',
          },
          {
            id: 'm-div',
            conversation_id: 'conv-recent',
            sender_type: 'system',
            direction: 'outbound',
            message_type: 'system',
            visibility: 'external',
            content: '── Sesión finalizada ──',
            metadata: { event: 'session_divider', timestamp: '2026-11-04T01:30:00Z' },
            sent_at: '2026-11-04T01:30:00Z',
          },
          {
            id: 'm-2',
            conversation_id: 'conv-recent',
            sender_type: 'contact',
            direction: 'inbound',
            message_type: 'text',
            content: 'Hola de nuevo, segunda sesión',
            sent_at: '2026-11-04T01:35:00Z',
          },
        ],
      },
      {
        id: 'conv-old',
        status: 'closed',
        contact_id: 'contact-luis-1',
        contact: {
          id: 'contact-luis-1',
          display_name: 'Luis',
          email: 'luis@example.com',
          phone: '+521111111111',
        },
        channel: {
          id: 'ch-1',
          name: 'Webchat Principal',
          type: 'webchat',
        },
        unread_count: 0,
        messages: [],
      },
    ];

    (api.getConversations as any).mockResolvedValue({
      data: mockMultiConversations,
      current_page: 1,
      last_page: 1,
      total: 2,
    });
    (api.getConversation as any).mockResolvedValue({ data: mockMultiConversations[0] });

    render(<ConversationsView />);

    // Verify there is ONLY ONE conversation item row rendered in the left list for Luis
    await waitFor(() => {
      expect(screen.getByTestId('inbox-conversation-list-panel')).toBeInTheDocument();
      expect(screen.getAllByTestId('conversation-item')).toHaveLength(1);
      expect(screen.getByText('Luis')).toBeInTheDocument();
    });

    const items = screen.getAllByTestId('conversation-item');

    // Click on the conversation to load thread
    fireEvent.click(items[0]);

    // Verify messages and session divider in thread
    await waitFor(() => {
      expect(screen.getByText('Hola, primera sesión')).toBeInTheDocument();
      expect(screen.getByTestId('session-divider')).toBeInTheDocument();
      expect(screen.getByText(/Conversación anterior finalizada/i)).toBeInTheDocument();
      expect(screen.getByText('Hola de nuevo, segunda sesión')).toBeInTheDocument();
    });
  });
});
