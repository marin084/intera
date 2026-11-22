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
    getTeams: vi.fn(),
    getConversation: vi.fn(),
    getConversationExecution: vi.fn(),
    markConversationAsRead: vi.fn(),
    getCannedResponses: vi.fn().mockResolvedValue({ data: [] }),
    toggleConversationBot: vi.fn(),
    restartConversationFlow: vi.fn(),
  },
}));

describe('ConversationBotHandover in ConversationsView', () => {
  const baseConversation = {
    id: 'conv-handover-1',
    status: 'bot',
    bot_enabled: true,
    channel_id: 'ch-1',
    contact_id: 'contact-1',
    started_at: '2026-11-06T10:00:00Z',
    last_message_at: '2026-11-06T10:05:00Z',
    closed_at: null,
    contact: {
      id: 'contact-1',
      display_name: 'Santiago Ramirez',
      first_name: 'Santiago',
      last_name: 'Ramirez',
      email: 'santiago@example.com',
      phone: '+5215559876543',
    },
    channel: {
      id: 'ch-1',
      name: 'WhatsApp Soporte',
      type: 'whatsapp',
    },
    unread_count: 0,
    messages: [
      {
        id: 'msg-1',
        conversation_id: 'conv-handover-1',
        content: 'Hola, requiero información',
        sender_type: 'contact',
        direction: 'inbound',
        message_type: 'text',
        sent_at: '2026-11-06T10:00:00Z',
      },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    (api.getConversations as any).mockResolvedValue({
      data: [baseConversation],
      current_page: 1,
      last_page: 1,
      total: 1,
    });
    (api.getUsers as any).mockResolvedValue({ data: [] });
    (api.getChannels as any).mockResolvedValue({ data: [{ id: 'ch-1', name: 'WhatsApp Soporte' }] });
    (api.getTeams as any).mockResolvedValue({ data: [] });
    (api.getConversation as any).mockResolvedValue({ data: baseConversation });
    (api.getConversationExecution as any).mockRejectedValue(new Error('no execution'));
    (api.markConversationAsRead as any).mockResolvedValue({});
    (api.getCannedResponses as any).mockResolvedValue({ data: [] });
  });

  const selectFirstConversation = async () => {
    await waitFor(() => {
      expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByTestId('conversation-item'));
    await waitFor(() => {
      expect(screen.getByTestId('toggle-bot-btn')).toBeInTheDocument();
    });
  };

  it('renders Bot Activo power button when bot_enabled is true', async () => {
    render(<ConversationsView />);

    await selectFirstConversation();

    const toggleBtn = screen.getByTestId('toggle-bot-btn');
    expect(toggleBtn).toBeInTheDocument();
    expect(toggleBtn).toHaveTextContent('Bot Activo');
    expect(toggleBtn.getAttribute('title')).toContain('Bot activo: responderá automáticamente');

    const restartBtn = screen.getByTestId('restart-flow-btn');
    expect(restartBtn).toBeInTheDocument();
    expect(restartBtn).toHaveTextContent('Reiniciar flujo');

    // Paused banner should not be present
    expect(screen.queryByTestId('paused-bot-banner')).not.toBeInTheDocument();
  });

  it('clicking toggle-bot-btn calls API and pauses the bot with optimistic update', async () => {
    (api.toggleConversationBot as any).mockResolvedValue({
      message: 'Bot pausado correctamente.',
      data: { ...baseConversation, bot_enabled: false },
    });

    render(<ConversationsView />);

    await selectFirstConversation();

    const toggleBtn = screen.getByTestId('toggle-bot-btn');
    fireEvent.click(toggleBtn);

    expect(api.toggleConversationBot).toHaveBeenCalledWith('conv-handover-1');

    await waitFor(() => {
      expect(screen.getByTestId('toggle-bot-btn')).toHaveTextContent('Bot Pausado');
      expect(screen.getByTestId('paused-bot-banner')).toBeInTheDocument();
    });

    expect(screen.getByText(/El bot se encuentra en pausa para esta conversación/i)).toBeInTheDocument();
  });

  it('allows resuming the bot from the paused-bot-banner action', async () => {
    const pausedConversation = { ...baseConversation, bot_enabled: false };
    (api.getConversations as any).mockResolvedValue({
      data: [pausedConversation],
      current_page: 1,
      last_page: 1,
      total: 1,
    });
    (api.getConversation as any).mockResolvedValue({ data: pausedConversation });
    (api.toggleConversationBot as any).mockResolvedValue({
      message: 'Bot reanudado correctamente.',
      data: { ...baseConversation, bot_enabled: true },
    });

    render(<ConversationsView />);

    await selectFirstConversation();

    expect(screen.getByTestId('paused-bot-banner')).toBeInTheDocument();

    const resumeBtn = screen.getByRole('button', { name: /Reanudar Bot/i });
    fireEvent.click(resumeBtn);

    expect(api.toggleConversationBot).toHaveBeenCalledWith('conv-handover-1');

    await waitFor(() => {
      expect(screen.getByTestId('toggle-bot-btn')).toHaveTextContent('Bot Activo');
      expect(screen.queryByTestId('paused-bot-banner')).not.toBeInTheDocument();
    });
  });

  it('opens styled confirmation modal when clicking restart-flow-btn and restarts on confirm', async () => {
    (api.restartConversationFlow as any).mockResolvedValue({
      message: 'Flujo reiniciado correctamente.',
      data: {
        conversation: { ...baseConversation, bot_enabled: true, status: 'bot' },
        execution_id: 'exec-123',
      },
    });

    render(<ConversationsView />);

    await selectFirstConversation();

    const restartBtn = screen.getByTestId('restart-flow-btn');
    fireEvent.click(restartBtn);

    // Modal should be open
    await waitFor(() => {
      expect(screen.getByTestId('restart-flow-modal')).toBeInTheDocument();
    });
    expect(screen.getByText('¿Reiniciar flujo del bot?')).toBeInTheDocument();
    expect(
      screen.getByText(/Esta acción cancelará cualquier paso activo en la conversación/)
    ).toBeInTheDocument();

    const confirmBtn = screen.getByTestId('confirm-restart-flow-btn');
    expect(confirmBtn).toHaveTextContent('Sí, reiniciar flujo');
    fireEvent.click(confirmBtn);

    await waitFor(() => {
      expect(api.restartConversationFlow).toHaveBeenCalledWith('conv-handover-1');
    });

    // Modal should close
    await waitFor(() => {
      expect(screen.queryByTestId('restart-flow-modal')).not.toBeInTheDocument();
    });
  });

  it('does not restart flow if user cancels confirmation modal', async () => {
    render(<ConversationsView />);

    await selectFirstConversation();

    const restartBtn = screen.getByTestId('restart-flow-btn');
    fireEvent.click(restartBtn);

    await waitFor(() => {
      expect(screen.getByTestId('restart-flow-modal')).toBeInTheDocument();
    });

    const cancelBtn = screen.getByTestId('cancel-restart-flow-btn');
    fireEvent.click(cancelBtn);

    await waitFor(() => {
      expect(screen.queryByTestId('restart-flow-modal')).not.toBeInTheDocument();
    });
    expect(api.restartConversationFlow).not.toHaveBeenCalled();
  });
});
