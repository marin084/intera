import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ContactDetailPanel } from '../ContactDetailPanel';
import { api } from '../../../services/api';
import type { Conversation } from '../../../types';

vi.mock('../../../services/api', () => ({
  api: {
    updateConversationContact: vi.fn(),
    addConversationTag: vi.fn(),
    removeConversationTag: vi.fn(),
    sendMessage: vi.fn(),
  },
}));

describe('ContactDetailPanel', () => {
  const mockConversation: Conversation = {
    id: 'conv-123',
    channel_id: 'chan-1',
    contact_id: 'contact-456',
    status: 'assigned',
    assigned_user_id: 1,
    active_flow_id: null,
    started_at: '2026-11-06T10:00:00Z',
    last_message_at: '2026-11-06T10:30:00Z',
    closed_at: null,
    channel: { id: 'chan-1', name: 'WhatsApp Ventas', type: 'whatsapp' },
    contact: {
      id: 'contact-456',
      name: 'Carlos Mendoza',
      display_name: 'Carlos Mendoza',
      phone: '+525512345678',
      email: 'carlos@example.com',
      country: 'MX',
      external_identifier: 'wa_525512345678',
      created_at: '2026-10-01T12:00:00Z',
    },
    tags: ['vip', 'renovacion'],
    variables: {
      tipo_plan: 'premium',
      presupuesto: 1500,
      origen_lead: 'campana_google',
    },
    internal_notes: [
      {
        id: 'note-1',
        content: 'Cliente interesado en anualidad con 15% de descuento.',
        author_name: 'Ana Supervisora',
        created_at: '2026-11-06T10:15:00Z',
      },
    ],
    attachments: [
      {
        id: 'att-1',
        message_id: 'msg-1',
        type: 'image',
        url: 'https://example.com/comprobante.jpg',
        file_name: 'comprobante.jpg',
        file_size: 102400,
        sent_at: '2026-11-06T10:20:00Z',
      },
      {
        id: 'att-2',
        message_id: 'msg-2',
        type: 'file',
        url: 'https://example.com/contrato.pdf',
        file_name: 'contrato.pdf',
        file_size: 204800,
        sent_at: '2026-11-06T10:25:00Z',
      },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders contact identity header and contact information', () => {
    render(
      <ContactDetailPanel
        conversation={mockConversation}
        isOpen={true}
        onClose={vi.fn()}
      />
    );

    // Initial avatar with initials "CM"
    expect(screen.getByText('CM')).toBeDefined();

    // Contact name
    expect(screen.getByText('Carlos Mendoza')).toBeDefined();

    // Channel badge
    expect(screen.getByText('WhatsApp')).toBeDefined();

    // Phone and Email in details
    expect(screen.getAllByText('+525512345678').length).toBeGreaterThan(0);
    expect(screen.getAllByText('carlos@example.com').length).toBeGreaterThan(0);

    // Country badge
    expect(screen.getAllByText('MX').length).toBeGreaterThan(0);

    // Identifier
    expect(screen.getByText('wa_525512345678')).toBeDefined();
  });

  it('does not render when isOpen is false', () => {
    const { container } = render(
      <ContactDetailPanel
        conversation={mockConversation}
        isOpen={false}
        onClose={vi.fn()}
      />
    );

    expect(container.firstChild).toBeNull();
  });

  it('calls onClose when close button is clicked', () => {
    const onClose = vi.fn();
    render(
      <ContactDetailPanel
        conversation={mockConversation}
        isOpen={true}
        onClose={onClose}
      />
    );

    const closeBtn = screen.getByTestId('close-contact-panel-btn');
    fireEvent.click(closeBtn);
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('allows inline editing of contact name', async () => {
    const onContactUpdated = vi.fn();
    (api.updateConversationContact as any).mockResolvedValue({
      data: {
        id: 'contact-456',
        name: 'Carlos Mendoza Editado',
        display_name: 'Carlos Mendoza Editado',
      },
    });

    render(
      <ContactDetailPanel
        conversation={mockConversation}
        isOpen={true}
        onClose={vi.fn()}
        onContactUpdated={onContactUpdated}
      />
    );

    // Click edit name button
    const editBtn = screen.getByTestId('edit-contact-name-btn');
    fireEvent.click(editBtn);

    const input = screen.getByTestId('edit-contact-name-input');
    fireEvent.change(input, { target: { value: 'Carlos Mendoza Editado' } });

    // Submit save
    const saveBtn = screen.getByTestId('save-contact-name-btn');
    fireEvent.click(saveBtn);

    await waitFor(() => {
      expect(api.updateConversationContact).toHaveBeenCalledWith('conv-123', 'Carlos Mendoza Editado');
      expect(onContactUpdated).toHaveBeenCalled();
    });
  });

  it('renders variables and allows collapsing sections', () => {
    render(
      <ContactDetailPanel
        conversation={mockConversation}
        isOpen={true}
        onClose={vi.fn()}
      />
    );

    // Check variable keys and values
    expect(screen.getByText('$tipo_plan')).toBeDefined();
    expect(screen.getByText('premium')).toBeDefined();
    expect(screen.getByText('$presupuesto')).toBeDefined();
    expect(screen.getByText('1500')).toBeDefined();

    // Toggle variables section
    const variablesHeader = screen.getByText(/Variables del Flujo/);
    fireEvent.click(variablesHeader);

    // After collapse, variables content should be hidden
    expect(screen.queryByText('$tipo_plan')).toBeNull();

    // Expand again
    fireEvent.click(variablesHeader);
    expect(screen.getByText('$tipo_plan')).toBeDefined();
  });

  it('displays tags and allows adding and removing tags', async () => {
    const onTagAdded = vi.fn();
    const onTagRemoved = vi.fn();
    (api.addConversationTag as any).mockResolvedValue({
      data: { tags: ['vip', 'renovacion', 'urgente'] },
    });
    (api.removeConversationTag as any).mockResolvedValue({
      data: { tags: ['renovacion'] },
    });

    render(
      <ContactDetailPanel
        conversation={mockConversation}
        isOpen={true}
        onClose={vi.fn()}
        onTagAdded={onTagAdded}
        onTagRemoved={onTagRemoved}
      />
    );

    expect(screen.getByText('vip')).toBeDefined();
    expect(screen.getByText('renovacion')).toBeDefined();

    // Remove "vip" tag
    const removeVipBtn = screen.getByTestId('remove-tag-vip');
    fireEvent.click(removeVipBtn);

    await waitFor(() => {
      expect(api.removeConversationTag).toHaveBeenCalledWith('conv-123', 'vip');
      expect(onTagRemoved).toHaveBeenCalledWith('vip');
    });

    // Add new tag
    const addTagTrigger = screen.getByTestId('add-tag-trigger-btn');
    fireEvent.click(addTagTrigger);

    const tagInput = screen.getByTestId('new-tag-input');
    fireEvent.change(tagInput, { target: { value: 'urgente' } });

    const saveTagBtn = screen.getByTestId('save-tag-btn');
    fireEvent.click(saveTagBtn);

    await waitFor(() => {
      expect(api.addConversationTag).toHaveBeenCalledWith('conv-123', 'urgente');
      expect(onTagAdded).toHaveBeenCalledWith('urgente');
    });
  });

  it('renders internal notes and allows adding a new internal note', async () => {
    const onNoteAdded = vi.fn();
    (api.sendMessage as any).mockResolvedValue({
      data: {
        id: 'msg-new-note',
        content: 'Nueva nota interna de prueba',
        sender_type: 'agent',
        sent_at: '2026-11-06T10:45:00Z',
      },
    });

    render(
      <ContactDetailPanel
        conversation={mockConversation}
        isOpen={true}
        onClose={vi.fn()}
        onNoteAdded={onNoteAdded}
      />
    );

    // Existing note
    expect(screen.getByText('Cliente interesado en anualidad con 15% de descuento.')).toBeDefined();
    expect(screen.getByText('Ana Supervisora')).toBeDefined();

    // Add new note
    const noteInput = screen.getByTestId('new-note-input');
    fireEvent.change(noteInput, { target: { value: 'Nueva nota interna de prueba' } });

    const submitNoteBtn = screen.getByTestId('submit-note-btn');
    fireEvent.click(submitNoteBtn);

    await waitFor(() => {
      expect(api.sendMessage).toHaveBeenCalledWith('conv-123', 'Nueva nota interna de prueba', {
        visibility: 'internal',
      });
      expect(onNoteAdded).toHaveBeenCalled();
    });
  });

  it('renders attachments and handles empty states gracefully', () => {
    const emptyConversation: Conversation = {
      ...mockConversation,
      tags: [],
      variables: {},
      internal_notes: [],
      attachments: [],
    };

    render(
      <ContactDetailPanel
        conversation={emptyConversation}
        isOpen={true}
        onClose={vi.fn()}
      />
    );

    // Check empty state messages
    expect(screen.getByText('Sin variables capturadas aún')).toBeDefined();
    expect(screen.getByText('Sin etiquetas asignadas')).toBeDefined();
    expect(screen.getByText('Sin notas internas aún')).toBeDefined();
    expect(screen.getByText('Sin archivos compartidos')).toBeDefined();
  });
});
