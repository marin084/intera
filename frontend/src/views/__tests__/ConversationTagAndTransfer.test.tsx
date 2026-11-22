import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { UsersAndSessionsTab } from '../Analytics/UsersAndSessionsTab';
import { ConversationsView } from '../ConversationsView';
import { api } from '../../services/api';
import { useAuth } from '../../context/useAuth';
import { ToastProvider } from '../../context/ToastContext';

// Mock AuthContext
vi.mock('../../context/useAuth', () => ({
  useAuth: vi.fn(),
}));

// Mock api service
vi.mock('../../services/api', () => ({
  api: {
    getChannels: vi.fn(),
    getTeams: vi.fn(),
    getTags: vi.fn(),
    createTag: vi.fn(),
    syncConversationTags: vi.fn(),
    removeConversationTag: vi.fn(),
    transferConversation: vi.fn(),
    getUsersAndSessions: vi.fn(),
    getUsersAndSessionsExportUrl: vi.fn().mockReturnValue('/api/v1/analytics/users-and-sessions/export'),
    getConversations: vi.fn(),
    getConversation: vi.fn(),
    getConversationMessages: vi.fn(),
    getUsers: vi.fn(),
    getCannedResponses: vi.fn(),
    markConversationAsRead: vi.fn().mockResolvedValue({ status: 'ok' }),
  },
}));

// Mock echo service
vi.mock('../../services/echo', () => ({
  createEcho: vi.fn(() => ({
    private: vi.fn(() => ({
      listen: vi.fn().mockReturnThis(),
      stopListening: vi.fn().mockReturnThis(),
    })),
    leave: vi.fn(),
    disconnect: vi.fn(),
  })),
}));

describe('ConversationTagAndTransfer (Frontend)', () => {
  const renderConversations = () => render(
    <ToastProvider>
      <ConversationsView />
    </ToastProvider>
  );

  const mockTags = [
    { id: 'tag-1', name: 'Petición de Oración', color: '#8B5CF6' },
    { id: 'tag-2', name: 'Promesa de Ofrenda', color: '#F59E0B' },
    { id: 'tag-3', name: 'Depósito por Validar', color: '#10B981' },
  ];

  const mockUsers = [
    { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai', is_active: true },
    { id: 2, name: 'Agente Ana', email: 'ana@conexa.ai', is_active: true },
    { id: 3, name: 'Agente Bernardo', email: 'bernardo@conexa.ai', is_active: true },
  ];

  beforeEach(() => {
    vi.clearAllMocks();

    (useAuth as any).mockReturnValue({
      user: { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai' },
      hasPermission: vi.fn().mockReturnValue(true),
    });

    (api.getChannels as any).mockResolvedValue({
      data: [{ id: 'chan-1', name: 'WhatsApp Central', type: 'whatsapp' }],
    });
    (api.getTeams as any).mockResolvedValue({ data: [] });

    (api.getTags as any).mockResolvedValue({
      data: mockTags,
    });

    (api.getUsers as any).mockResolvedValue({
      data: mockUsers,
    });

    (api.getCannedResponses as any).mockResolvedValue({
      data: [],
    });
  });

  describe('UsersAndSessionsTab Analytics Integration', () => {
    it('renders tag filter selector and topics distribution card', async () => {
      const mockAnalyticsData = {
        date_range: { from: '2026-11-01', to: '2026-11-08' },
        kpis: {
          total_sessions: 10,
          unique_contacts: 8,
          agent_sessions: 5,
          bot_only_sessions: 5,
          closed_by_agents: 4,
          agent_session_percentage: 50,
          bot_only_percentage: 50,
          message_counts: { user_messages: 20, bot_messages: 15, agent_messages: 12, total_messages: 47 },
        },
        channel_distribution: [
          { channel_id: 'chan-1', channel_name: 'WhatsApp Central', channel_type: 'whatsapp', count: 10, percentage: 100 },
        ],
        topics_distribution: [
          { tag_id: 'tag-1', name: 'Petición de Oración', color: '#8B5CF6', count: 6, percentage: 60 },
          { tag_id: 'tag-2', name: 'Promesa de Ofrenda', color: '#F59E0B', count: 2, percentage: 20 },
        ],
        untagged_count: 2,
        untagged_percentage: 20,
        time_series: [],
      };

      (api.getUsersAndSessions as any).mockResolvedValue({
        data: mockAnalyticsData,
      });

      render(<UsersAndSessionsTab />);

      await waitFor(() => {
        expect(screen.getByTestId('analytics-tag-filter')).toBeInTheDocument();
      });

      // Assert tag options exist
      expect(screen.getAllByText('Petición de Oración').length).toBeGreaterThanOrEqual(1);
      expect(screen.getAllByText('Promesa de Ofrenda').length).toBeGreaterThanOrEqual(1);

      // Assert topics distribution card
      await waitFor(() => {
        expect(screen.getByTestId('chart-topics-distribution')).toBeInTheDocument();
      });

      expect(screen.getByTestId('topics-untagged-count')).toHaveTextContent('Sin tema: 2 (20%)');
      expect(screen.getByTestId('topic-row-tag-1')).toBeInTheDocument();
      expect(screen.getByTestId('topic-row-tag-2')).toBeInTheDocument();

      // Filter by tag
      fireEvent.change(screen.getByTestId('analytics-tag-filter'), {
        target: { value: 'tag-1' },
      });

      await waitFor(() => {
        expect(api.getUsersAndSessions).toHaveBeenCalledWith(
          expect.objectContaining({
            tag_id: 'tag-1',
          })
        );
      });
    });
  });

  describe('ConversationsView Tags and Transfer', () => {
    const mockConversations = [
      {
        id: 'conv-1',
        channel_id: 'chan-1',
        contact_id: 'contact-1',
        status: 'assigned',
        assigned_user_id: 2,
        started_at: '2026-11-08T10:00:00Z',
        last_message_at: '2026-11-08T10:30:00Z',
        contact: { id: 'contact-1', display_name: 'Carlos Santana', phone: '+5215551234' },
        channel: { id: 'chan-1', name: 'WhatsApp Central', type: 'whatsapp' },
        tags: [mockTags[0]],
        messages: [],
      },
    ];

    it('renders inbox tag filter and conversation tag chips', async () => {
      (api.getConversations as any).mockResolvedValue({
        data: mockConversations,
        total: 1,
        counts: { all: 1, assigned: 1 },
      });

      renderConversations();

      await waitFor(() => {
        expect(screen.getByTestId('inbox-tag-filter')).toBeInTheDocument();
      });

      // Check conversation item rendered with tag chip
      await waitFor(() => {
        expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
      });

      expect(screen.getByTestId('conv-tag-chip-tag-1')).toHaveTextContent('Petición de Oración');

      // Change tag filter
      fireEvent.change(screen.getByTestId('inbox-tag-filter'), {
        target: { value: 'tag-1' },
      });

      await waitFor(() => {
        expect(api.getConversations).toHaveBeenCalledWith(
          expect.objectContaining({
            tag_id: 'tag-1',
          })
        );
      });
    });

    it('manages conversation tags and handles conversation transfer', async () => {
      (api.getConversations as any).mockResolvedValue({
        data: mockConversations,
        total: 1,
        counts: { all: 1, assigned: 1 },
      });

      (api.getConversation as any).mockResolvedValue({
        data: mockConversations[0],
        messages: { data: [], has_more: false, oldest_message_id: null },
      });

      (api.syncConversationTags as any).mockResolvedValue({
        data: { id: 'conv-1' },
        tags: [mockTags[0], mockTags[1]],
      });

      (api.removeConversationTag as any).mockResolvedValue({
        message: 'Etiqueta removida.',
        tags: [],
      });

      (api.transferConversation as any).mockResolvedValue({
        message: 'Conversación transferida a Agente Bernardo.',
        data: { id: 'conv-1', assigned_user_id: 3 },
      });

      renderConversations();

      await waitFor(() => {
        expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
      });

      // Select conversation
      fireEvent.click(screen.getByTestId('conversation-item'));

      await waitFor(() => {
        expect(screen.getByTestId('header-tag-tag-1')).toBeInTheDocument();
      });

      // Verify remove tag button exists and works
      const removeBtn = screen.getByTestId('remove-header-tag-tag-1');
      fireEvent.click(removeBtn);
      expect(api.removeConversationTag).toHaveBeenCalledWith('conv-1', 'tag-1');

      // Test + Etiqueta button and popover
      const tagsBtn = screen.getByTestId('conversation-tags-btn');
      fireEvent.click(tagsBtn);

      await waitFor(() => {
        expect(screen.getByTestId('tags-dropdown-popover')).toBeInTheDocument();
      });

      // Toggle tag-2
      const toggleTag2 = screen.getByTestId('toggle-tag-item-tag-2');
      fireEvent.click(toggleTag2);
      expect(api.syncConversationTags).toHaveBeenCalledWith('conv-1', { tag_id: 'tag-2' });

      // Test Transfer button and modal
      const transferBtn = screen.getByTestId('transfer-conversation-btn');
      fireEvent.click(transferBtn);

      await waitFor(() => {
        expect(screen.getByTestId('transfer-conversation-modal')).toBeInTheDocument();
      });

      // Select target agent
      const targetSelect = screen.getByTestId('transfer-target-agent-select');
      fireEvent.change(targetSelect, { target: { value: '3' } });

      // Fill transfer note
      const noteInput = screen.getByTestId('transfer-note-input');
      fireEvent.change(noteInput, { target: { value: 'Cliente solicita atención en temas de ofrendas.' } });

      // Confirm transfer
      const confirmBtn = screen.getByTestId('confirm-transfer-btn');
      fireEvent.click(confirmBtn);

      await waitFor(() => {
        expect(api.transferConversation).toHaveBeenCalledWith('conv-1', {
          target_user_id: 3,
          note: 'Cliente solicita atención en temas de ofrendas.',
        });
      });

      // Assert toast notification shown
      await waitFor(() => {
        expect(screen.getByText('Conversación transferida a Agente Bernardo.')).toBeInTheDocument();
      });
      expect(screen.getByText('Conversación transferida a Agente Bernardo.')).toBeInTheDocument();
    });

    it('hides tag creation form inside popover for agent role and shows it for supervisor', async () => {
      // 1. As Agent: hasPermission('conversations.view_all') returns false
      (useAuth as any).mockReturnValue({
        user: { id: 2, name: 'Agente Ana', email: 'ana@conexa.ai' },
        hasPermission: vi.fn((perm: string) => perm === 'conversations.reply' || perm === 'conversations.view_assigned'),
      });

      const { unmount } = renderConversations();

      await waitFor(() => {
        expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
      });

      fireEvent.click(screen.getByTestId('conversation-item'));

      await waitFor(() => {
        expect(screen.getByTestId('conversation-tags-btn')).toBeInTheDocument();
      });

      fireEvent.click(screen.getByTestId('conversation-tags-btn'));

      await waitFor(() => {
        expect(screen.getByTestId('tags-dropdown-popover')).toBeInTheDocument();
      });

      // Agent can see tags to toggle
      expect(screen.getByTestId('toggle-tag-item-tag-1')).toBeInTheDocument();
      // Agent CANNOT see tag creation inputs or button
      expect(screen.queryByTestId('create-tag-btn')).not.toBeInTheDocument();
      expect(screen.queryByTestId('new-tag-name-input')).not.toBeInTheDocument();

      unmount();

      // 2. As Supervisor/Admin: hasPermission('conversations.view_all') returns true
      (useAuth as any).mockReturnValue({
        user: { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai' },
        hasPermission: vi.fn(() => true),
      });

      renderConversations();

      await waitFor(() => {
        expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
      });

      fireEvent.click(screen.getByTestId('conversation-item'));

      await waitFor(() => {
        expect(screen.getByTestId('conversation-tags-btn')).toBeInTheDocument();
      });

      fireEvent.click(screen.getByTestId('conversation-tags-btn'));

      await waitFor(() => {
        expect(screen.getByTestId('tags-dropdown-popover')).toBeInTheDocument();
      });

      // Supervisor CAN see tag creation form
      expect(screen.getByTestId('create-tag-btn')).toBeInTheDocument();
      expect(screen.getByTestId('new-tag-name-input')).toBeInTheDocument();
    });
  });
});
