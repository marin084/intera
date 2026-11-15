import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { CannedResponseAutocomplete } from '../CannedResponseAutocomplete';
import { CannedResponsesTab } from '../CannedResponsesTab';
import { CannedResponseModal } from '../CannedResponseModal';
import { ConversationsView } from '../../../views/ConversationsView';
import { api } from '../../../services/api';
import type { CannedResponse } from '../../../types';

vi.mock('../../../context/useAuth', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai', roles: ['admin'] },
    hasPermission: () => true,
  }),
}));

vi.mock('../../../services/echo', () => ({
  createEcho: () => null,
}));

vi.mock('../../../services/api', () => ({
  api: {
    createCannedResponse: vi.fn(),
    updateCannedResponse: vi.fn(),
    deleteCannedResponse: vi.fn(),
    getCannedResponses: vi.fn(),
    getConversations: vi.fn(),
    getUsers: vi.fn(),
    getChannels: vi.fn(),
    getConversation: vi.fn(),
    getConversationExecution: vi.fn(),
    markConversationAsRead: vi.fn(),
  },
}));

describe('Canned Responses Module', () => {
  const mockResponses: CannedResponse[] = [
    {
      id: 'canned-1',
      title: 'Cuentas Banco de Costa Rica',
      shortcut: 'bcr',
      formatted_shortcut: '/bcr',
      content: 'Estimado/a, para transferencias en CR: Colones IBAN CR24015201001000002303.',
      category: 'Finanzas',
      is_active: true,
    },
    {
      id: 'canned-2',
      title: 'Datos Bancolombia Nequi',
      shortcut: 'nequi',
      formatted_shortcut: '/nequi',
      content: 'Para pagos en Colombia: Cuenta Ahorros 123-456789-01.',
      category: 'Finanzas',
      is_active: true,
    },
    {
      id: 'canned-3',
      title: 'Canal Oficial de YouTube',
      shortcut: 'youtube',
      formatted_shortcut: '/youtube',
      content: 'Suscríbete a nuestro canal de YouTube: https://youtube.com/@CanalOficial',
      category: 'Atención',
      is_active: true,
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('CannedResponseAutocomplete', () => {
    it('renders autocomplete items when open and filters by filterText', () => {
      const onSelect = vi.fn();
      render(
        <CannedResponseAutocomplete
          isOpen={true}
          filterText="bc"
          responses={mockResponses}
          selectedIndex={0}
          onSelect={onSelect}
          onClose={vi.fn()}
        />
      );

      // Should show BCR
      expect(screen.getByText('Cuentas Banco de Costa Rica')).toBeDefined();
      expect(screen.getByText('/bcr')).toBeDefined();

      // Should not show Nequi or YouTube because filter is "bc"
      expect(screen.queryByText('Datos Bancolombia Nequi')).toBeNull();
      expect(screen.queryByText('Canal Oficial de YouTube')).toBeNull();

      // Click BCR item
      const item = screen.getByTestId('canned-item-bcr');
      fireEvent.click(item);
      expect(onSelect).toHaveBeenCalledWith(mockResponses[0]);
    });

    it('shows empty message when no responses match filter', () => {
      render(
        <CannedResponseAutocomplete
          isOpen={true}
          filterText="inexistente"
          responses={mockResponses}
          selectedIndex={0}
          onSelect={vi.fn()}
          onClose={vi.fn()}
        />
      );

      expect(screen.getByTestId('canned-autocomplete-empty')).toBeDefined();
      expect(screen.getByText(/No se encontraron respuestas con/)).toBeDefined();
    });

    it('does not render when isOpen is false', () => {
      const { container } = render(
        <CannedResponseAutocomplete
          isOpen={false}
          filterText=""
          responses={mockResponses}
          selectedIndex={0}
          onSelect={vi.fn()}
          onClose={vi.fn()}
        />
      );

      expect(container.firstChild).toBeNull();
    });

    it('filters by title in addition to shortcut', () => {
      render(
        <CannedResponseAutocomplete
          isOpen={true}
          filterText="costa"
          responses={mockResponses}
          selectedIndex={0}
          onSelect={vi.fn()}
          onClose={vi.fn()}
        />
      );

      // Should show BCR because title contains "Costa"
      expect(screen.getByText('Cuentas Banco de Costa Rica')).toBeDefined();
      expect(screen.queryByText('Datos Bancolombia Nequi')).toBeNull();
      expect(screen.queryByText('Canal Oficial de YouTube')).toBeNull();
    });

    it('immediately displays all items when filterText is empty string (typing /)', () => {
      render(
        <CannedResponseAutocomplete
          isOpen={true}
          filterText=""
          responses={mockResponses}
          selectedIndex={0}
          onSelect={vi.fn()}
          onClose={vi.fn()}
        />
      );

      expect(screen.getByText('Cuentas Banco de Costa Rica')).toBeDefined();
      expect(screen.getByText('Datos Bancolombia Nequi')).toBeDefined();
      expect(screen.getByText('Canal Oficial de YouTube')).toBeDefined();
    });

    it('has proper z-index and absolute positioning above composer', () => {
      render(
        <CannedResponseAutocomplete
          isOpen={true}
          filterText=""
          responses={mockResponses}
          selectedIndex={0}
          onSelect={vi.fn()}
          onClose={vi.fn()}
        />
      );

      const popover = screen.getByTestId('canned-responses-autocomplete-popover');
      expect(popover.style.position).toBe('absolute');
      expect(popover.style.bottom).toBe('100%');
      expect(popover.style.zIndex).toBe('50');
    });
  });

  describe('CannedResponsesTab', () => {
    it('renders list of canned responses and allows searching', () => {
      const onUseInChat = vi.fn();
      render(
        <CannedResponsesTab
          responses={mockResponses}
          loading={false}
          canManage={true}
          onUseInChat={onUseInChat}
          onCreate={vi.fn()}
          onEdit={vi.fn()}
          onDelete={vi.fn()}
        />
      );

      expect(screen.getByText('Cuentas Banco de Costa Rica')).toBeDefined();
      expect(screen.getByText('Datos Bancolombia Nequi')).toBeDefined();
      expect(screen.getByText('Canal Oficial de YouTube')).toBeDefined();

      // Filter by search
      const searchInput = screen.getByTestId('canned-search-input');
      fireEvent.change(searchInput, { target: { value: 'nequi' } });

      expect(screen.getByText('Datos Bancolombia Nequi')).toBeDefined();
      expect(screen.queryByText('Cuentas Banco de Costa Rica')).toBeNull();
    });

    it('allows filtering by category pills', () => {
      render(
        <CannedResponsesTab
          responses={mockResponses}
          loading={false}
          canManage={true}
          onUseInChat={vi.fn()}
          onCreate={vi.fn()}
          onEdit={vi.fn()}
          onDelete={vi.fn()}
        />
      );

      // Click Atención category
      const atencionPill = screen.getByTestId('canned-category-Atención');
      fireEvent.click(atencionPill);

      expect(screen.getByText('Canal Oficial de YouTube')).toBeDefined();
      expect(screen.queryByText('Cuentas Banco de Costa Rica')).toBeNull();
      expect(screen.queryByText('Datos Bancolombia Nequi')).toBeNull();

      // Click Todas
      const allPill = screen.getByTestId('canned-category-all');
      fireEvent.click(allPill);
      expect(screen.getByText('Cuentas Banco de Costa Rica')).toBeDefined();
    });

    it('triggers onUseInChat when "Usar en chat" button is clicked', () => {
      const onUseInChat = vi.fn();
      render(
        <CannedResponsesTab
          responses={mockResponses}
          loading={false}
          canManage={false}
          onUseInChat={onUseInChat}
          onCreate={vi.fn()}
          onEdit={vi.fn()}
          onDelete={vi.fn()}
        />
      );

      const useBtn = screen.getByTestId('use-canned-btn-canned-1');
      fireEvent.click(useBtn);

      expect(onUseInChat).toHaveBeenCalledWith(mockResponses[0].content);
    });

    it('allows copying content to clipboard', async () => {
      // Mock clipboard
      const writeTextMock = vi.fn().mockResolvedValue(undefined);
      Object.assign(navigator, {
        clipboard: {
          writeText: writeTextMock,
        },
      });

      render(
        <CannedResponsesTab
          responses={mockResponses}
          loading={false}
          canManage={false}
          onUseInChat={vi.fn()}
          onCreate={vi.fn()}
          onEdit={vi.fn()}
          onDelete={vi.fn()}
        />
      );

      const copyBtn = screen.getByTestId('copy-canned-btn-canned-1');
      fireEvent.click(copyBtn);

      expect(writeTextMock).toHaveBeenCalledWith(mockResponses[0].content);
      expect(screen.getByText('¡Copiado!')).toBeDefined();
    });
  });

  describe('CannedResponseModal', () => {
    it('creates new canned response when form is submitted', async () => {
      const onSaved = vi.fn();
      const onClose = vi.fn();
      (api.createCannedResponse as any).mockResolvedValue({
        data: {
          id: 'canned-new',
          title: 'Nueva Respuesta Creada',
          shortcut: 'nueva',
          content: 'Mensaje de prueba',
          is_active: true,
        },
      });

      render(
        <CannedResponseModal
          isOpen={true}
          cannedResponse={null}
          onClose={onClose}
          onSaved={onSaved}
        />
      );

      fireEvent.change(screen.getByTestId('canned-title-input'), {
        target: { value: 'Nueva Respuesta Creada' },
      });
      fireEvent.change(screen.getByTestId('canned-shortcut-input'), {
        target: { value: 'nueva' },
      });
      fireEvent.change(screen.getByTestId('canned-content-textarea'), {
        target: { value: 'Mensaje de prueba' },
      });

      fireEvent.click(screen.getByTestId('save-canned-modal-btn'));

      await waitFor(() => {
        expect(api.createCannedResponse).toHaveBeenCalledWith({
          title: 'Nueva Respuesta Creada',
          shortcut: 'nueva',
          category: null,
          content: 'Mensaje de prueba',
          is_active: true,
        });
        expect(onSaved).toHaveBeenCalled();
        expect(onClose).toHaveBeenCalled();
      });
    });

    it('edits existing canned response when modal opens with response', async () => {
      const onSaved = vi.fn();
      const onClose = vi.fn();
      (api.updateCannedResponse as any).mockResolvedValue({
        data: {
          ...mockResponses[0],
          title: 'BCR Modificado',
        },
      });

      render(
        <CannedResponseModal
          isOpen={true}
          cannedResponse={mockResponses[0]}
          onClose={onClose}
          onSaved={onSaved}
        />
      );

      const titleInput = screen.getByTestId('canned-title-input');
      expect((titleInput as HTMLInputElement).value).toBe('Cuentas Banco de Costa Rica');

      fireEvent.change(titleInput, { target: { value: 'BCR Modificado' } });
      fireEvent.click(screen.getByTestId('save-canned-modal-btn'));

      await waitFor(() => {
        expect(api.updateCannedResponse).toHaveBeenCalledWith('canned-1', expect.objectContaining({
          title: 'BCR Modificado',
        }));
        expect(onSaved).toHaveBeenCalled();
        expect(onClose).toHaveBeenCalled();
      });
    });
  });

  describe('Composer Canned Responses Integration in ConversationsView', () => {
    const baseConversation = {
      id: 'conv-canned-1',
      status: 'assigned',
      bot_enabled: true,
      channel_id: 'ch-1',
      contact_id: 'contact-1',
      started_at: '2026-11-06T10:00:00Z',
      last_message_at: '2026-11-06T10:05:00Z',
      closed_at: null,
      contact: {
        id: 'contact-1',
        display_name: 'Ana Mora',
        phone: '+50688889999',
      },
      channel: {
        id: 'ch-1',
        name: 'WhatsApp Soporte',
        type: 'whatsapp',
      },
      unread_count: 0,
      messages: [],
    };

    beforeEach(() => {
      (api.getConversations as any).mockResolvedValue({
        data: [baseConversation],
        current_page: 1,
        last_page: 1,
        total: 1,
      });
      (api.getUsers as any).mockResolvedValue({ data: [] });
      (api.getChannels as any).mockResolvedValue({ data: [{ id: 'ch-1', name: 'WhatsApp Soporte' }] });
      (api.getConversation as any).mockResolvedValue({ data: baseConversation });
      (api.getConversationExecution as any).mockResolvedValue({ data: null });
      (api.markConversationAsRead as any).mockResolvedValue({});
      (api.getCannedResponses as any).mockResolvedValue({ data: mockResponses });
    });

    it('renders suggestions popover when typing / in reply input', async () => {
      render(<ConversationsView />);

      await waitFor(() => {
        expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
      });
      fireEvent.click(screen.getByTestId('conversation-item'));

      await waitFor(() => {
        expect(screen.getByTestId('agent-reply-input')).toBeInTheDocument();
      });

      const replyInput = screen.getByTestId('agent-reply-input');

      // Type / to trigger autocomplete
      fireEvent.change(replyInput, { target: { value: '/' } });

      await waitFor(() => {
        expect(screen.getByTestId('canned-responses-autocomplete-popover')).toBeInTheDocument();
      });
      expect(screen.getByText('Cuentas Banco de Costa Rica')).toBeInTheDocument();
    });

    it('filters suggestions by title when typing /banco', async () => {
      render(<ConversationsView />);

      await waitFor(() => {
        expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
      });
      fireEvent.click(screen.getByTestId('conversation-item'));

      await waitFor(() => {
        expect(screen.getByTestId('agent-reply-input')).toBeInTheDocument();
      });

      const replyInput = screen.getByTestId('agent-reply-input');

      // Type /banco to filter by title
      fireEvent.change(replyInput, { target: { value: '/banco' } });

      await waitFor(() => {
        expect(screen.getByTestId('canned-responses-autocomplete-popover')).toBeInTheDocument();
      });

      // Both BCR and Bancolombia should match title
      expect(screen.getByText('Cuentas Banco de Costa Rica')).toBeInTheDocument();
      expect(screen.getByText('Datos Bancolombia Nequi')).toBeInTheDocument();
      expect(screen.queryByText('Canal Oficial de YouTube')).not.toBeInTheDocument();
    });

    it('clicking the ⚡ button opens the responses list and inserts /', async () => {
      render(<ConversationsView />);

      await waitFor(() => {
        expect(screen.getByTestId('conversation-item')).toBeInTheDocument();
      });
      fireEvent.click(screen.getByTestId('conversation-item'));

      await waitFor(() => {
        expect(screen.getByTestId('canned-responses-trigger-btn')).toBeInTheDocument();
      });

      const triggerBtn = screen.getByTestId('canned-responses-trigger-btn');
      expect(triggerBtn).toHaveAttribute('title', 'Respuestas rápidas (/)');

      fireEvent.click(triggerBtn);

      await waitFor(() => {
        expect(screen.getByTestId('canned-responses-autocomplete-popover')).toBeInTheDocument();
      });

      const replyInput = screen.getByTestId('agent-reply-input') as HTMLInputElement;
      expect(replyInput.value).toBe('/');
    });
  });
});
