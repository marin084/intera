import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { ConversationsView } from '../ConversationsView';
import { api } from '../../services/api';
import { SOUND_STORAGE_KEY } from '../../hooks/useInboxNotifications';

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

describe('ConversationsView Inbox Sound & Notification Controls', () => {
  const mockConversations = [
    {
      id: 'conv-101',
      status: 'waiting_agent',
      contact: {
        id: 'contact-1',
        display_name: 'Juan Perez',
        phone: '+5215559876543',
      },
      channel: {
        id: 'ch-1',
        name: 'WhatsApp Soporte',
      },
      unread_count: 2,
      messages: [],
    },
  ];

  const storage: Record<string, string> = {};
  const mockLocalStorage = {
    getItem: (key: string) => storage[key] ?? null,
    setItem: (key: string, val: string) => {
      storage[key] = String(val);
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
    configurable: true,
  });

  beforeEach(() => {
    mockLocalStorage.clear();
    vi.restoreAllMocks();

    (api.getConversations as any).mockResolvedValue({
      data: mockConversations,
      current_page: 1,
      last_page: 1,
      total: 1,
      counts: {
        all: 1,
        waiting_agent: 1,
        assigned: 0,
        closed: 0,
      },
    });
    (api.getUsers as any).mockResolvedValue({ data: [] });
    (api.getChannels as any).mockResolvedValue({ data: [] });
  });

  it('renders the sound toggle button in the inbox top bar and toggles mute state', async () => {
    render(<ConversationsView />);

    await waitFor(() => {
      expect(screen.getByTestId('inbox-sound-toggle-btn')).toBeInTheDocument();
    });

    const toggleBtn = screen.getByTestId('inbox-sound-toggle-btn');
    // Initially enabled by default
    expect(toggleBtn).toHaveAttribute('title', 'Alertas sonoras activadas (clic para silenciar)');
    expect(toggleBtn).toHaveTextContent('Sonido');

    // Click to mute
    await act(async () => {
      fireEvent.click(toggleBtn);
    });
    expect(toggleBtn).toHaveAttribute('title', 'Alertas silenciadas (clic para activar)');
    expect(toggleBtn).toHaveTextContent('Silencio');
    expect(storage[SOUND_STORAGE_KEY]).toBe('false');

    // Click to re-enable
    await act(async () => {
      fireEvent.click(toggleBtn);
    });
    expect(toggleBtn).toHaveAttribute('title', 'Alertas sonoras activadas (clic para silenciar)');
    expect(toggleBtn).toHaveTextContent('Sonido');
    expect(storage[SOUND_STORAGE_KEY]).toBe('true');
  });

  it('updates document.title dynamic badge with unread or waiting conversations', async () => {
    render(<ConversationsView />);

    await waitFor(() => {
      expect(document.title).toContain('Conexa — Bandeja de Entrada');
    });

    // We have 1 conversation waiting_agent with unread_count: 2
    expect(document.title).toBe('(1) Conexa — Bandeja de Entrada');
  });
});
