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
    sendMessage: vi.fn(),
  },
}));

describe('ConversationsView Auto-Scroll & Interactive Options', () => {
  const mockConversationWithButtons = {
    id: 'conv-auto-1',
    status: 'active',
    contact: {
      id: 'contact-1',
      display_name: 'Maria Lopez',
      email: 'maria@example.com',
      phone: '+5215551234567',
    },
    channel: {
      id: 'ch-1',
      name: 'WhatsApp Principal',
      type: 'whatsapp',
    },
    unread_count: 0,
    messages: [
      {
        id: 'msg-1',
        conversation_id: 'conv-auto-1',
        sender_type: 'contact',
        direction: 'inbound',
        message_type: 'text',
        content: 'Hola, quiero información',
        sent_at: '2026-11-06T12:00:00Z',
        created_at: '2026-11-06T12:00:00Z',
      },
      {
        id: 'msg-2',
        conversation_id: 'conv-auto-1',
        sender_type: 'bot',
        direction: 'outbound',
        message_type: 'text',
        content: 'Selecciona alguna de las siguientes opciones:',
        payload: {
          options: [
            { id: '1', title: 'Ofrenda en Línea' },
            { id: '2', title: 'Depósito Bancario' },
          ],
        },
        sent_at: '2026-11-06T12:00:01Z',
        created_at: '2026-11-06T12:00:01Z',
      },
    ],
  };

  beforeEach(() => {
    vi.restoreAllMocks();
    (api.getConversations as any).mockResolvedValue({
      data: [mockConversationWithButtons],
      current_page: 1,
      last_page: 1,
      total: 1,
    });
    (api.getUsers as any).mockResolvedValue({ data: [] });
    (api.getChannels as any).mockResolvedValue({ data: [{ id: 'ch-1', name: 'WhatsApp Principal' }] });
    (api.getConversation as any).mockResolvedValue({ data: mockConversationWithButtons });
    (api.getConversationExecution as any).mockRejectedValue(new Error('no execution'));
    (api.markConversationAsRead as any).mockResolvedValue({});
  });

  it('renders interactive option preview pills inside bot message bubble', async () => {
    render(<ConversationsView />);

    await waitFor(() => {
      expect(screen.getByText('Maria Lopez')).toBeInTheDocument();
    });

    const convItem = screen.getByTestId('conversation-item');
    fireEvent.click(convItem);

    await waitFor(() => {
      expect(screen.getByText('Selecciona alguna de las siguientes opciones:')).toBeInTheDocument();
    });

    // Check that interactive options preview is rendered
    expect(screen.getByTestId('interactive-options-preview')).toBeInTheDocument();
    expect(screen.getByText(/Ofrenda en Línea/)).toBeInTheDocument();
    expect(screen.getByText(/Depósito Bancario/)).toBeInTheDocument();
  });

  it('contains the messagesEndRef anchor element with zero height and aria-hidden', async () => {
    const { container } = render(<ConversationsView />);

    await waitFor(() => {
      expect(screen.getByText('Maria Lopez')).toBeInTheDocument();
    });

    const convItem = screen.getByTestId('conversation-item');
    fireEvent.click(convItem);

    await waitFor(() => {
      expect(screen.getByText('Selecciona alguna de las siguientes opciones:')).toBeInTheDocument();
    });

    const anchor = container.querySelector('.h-0.w-full.shrink-0[aria-hidden="true"]');
    expect(anchor).toBeInTheDocument();
  });
});
