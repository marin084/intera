import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { ChannelsView } from '../ChannelsView';
import { api } from '../../services/api';
import { ToastProvider } from '../../context/ToastContext';

const renderChannelsView = () => render(<ToastProvider><ChannelsView /></ToastProvider>);

vi.mock('../../context/useAuth', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai' },
    hasPermission: () => true,
  }),
}));

vi.mock('../../services/api', () => ({
  api: {
    getChannels: vi.fn(),
    getFlows: vi.fn(),
    createChannel: vi.fn(),
    updateChannel: vi.fn(),
    updateChannelStatus: vi.fn(),
    regenerateWebchatKey: vi.fn(),
    deleteChannel: vi.fn(),
  },
}));

describe('ChannelsView Confirmation Modals (ConfirmModal)', () => {
  const mockChannels = [
    {
      id: 'ch-active',
      name: 'Webchat Soporte',
      type: 'webchat',
      is_active: true,
      entry_flow_id: null,
      created_at: '2026-10-10T10:00:00Z',
      updated_at: '2026-10-10T10:00:00Z',
      webchat_settings: {
        id: 'ws-1',
        public_key: 'wb_key_123',
        display_name: 'Asistente Soporte',
        allowed_origins: ['http://localhost:3000'],
        accent_color: '#2563EB',
        launcher_position: 'bottom-right',
      },
    },
    {
      id: 'ch-inactive',
      name: 'WhatsApp Marketing',
      type: 'whatsapp',
      is_active: false,
      entry_flow_id: null,
      created_at: '2026-10-10T11:00:00Z',
      updated_at: '2026-10-10T11:00:00Z',
      configuration: {
        phone_number_id: '12345678',
      },
    },
  ];

  beforeEach(() => {
    vi.restoreAllMocks();
    vi.mocked(api.getChannels).mockResolvedValue({ data: mockChannels as any });
    vi.mocked(api.getFlows).mockResolvedValue({ data: [] });
    vi.mocked(api.updateChannelStatus).mockResolvedValue({ message: 'Estado actualizado.' } as any);
    vi.mocked(api.deleteChannel).mockResolvedValue({ message: 'Canal eliminado exitosamente.' } as any);
  });

  it('opens ConfirmModal when clicking Desactivar and cancels without API call', async () => {
    renderChannelsView();

    await waitFor(() => {
      expect(screen.getByText('Webchat Soporte')).toBeInTheDocument();
      expect(screen.getByText('WhatsApp Marketing')).toBeInTheDocument();
    });

    // Click Desactivar on active channel
    const deactivateBtn = screen.getByRole('button', { name: /Desactivar canal Webchat Soporte/i });
    fireEvent.click(deactivateBtn);

    // ConfirmModal should be displayed with expected text
    expect(screen.getByTestId('confirm-toggle-status-modal')).toBeInTheDocument();
    expect(screen.getByText('¿Desactivar canal «Webchat Soporte»?')).toBeInTheDocument();
    expect(screen.getByText(/El canal dejará de recibir y procesar mensajes en tiempo real/i)).toBeInTheDocument();

    // Click Cancelar
    const cancelBtn = screen.getByTestId('cancel-toggle-status-btn');
    fireEvent.click(cancelBtn);

    expect(screen.queryByTestId('confirm-toggle-status-modal')).not.toBeInTheDocument();
    expect(api.updateChannelStatus).not.toHaveBeenCalled();
  });

  it('updates channel status to inactive when confirmed in ConfirmModal', async () => {
    renderChannelsView();

    await waitFor(() => {
      expect(screen.getByText('Webchat Soporte')).toBeInTheDocument();
    });

    const deactivateBtn = screen.getByRole('button', { name: /Desactivar canal Webchat Soporte/i });
    fireEvent.click(deactivateBtn);

    const confirmBtn = screen.getByTestId('confirm-toggle-status-btn');
    expect(confirmBtn).toHaveTextContent('Sí, desactivar canal');
    fireEvent.click(confirmBtn);

    await waitFor(() => {
      expect(api.updateChannelStatus).toHaveBeenCalledWith('ch-active', false);
    });
  });

  it('activates inactive channel directly when clicking Activar', async () => {
    renderChannelsView();

    await waitFor(() => {
      expect(screen.getByText('WhatsApp Marketing')).toBeInTheDocument();
    });

    const activateBtn = screen.getByRole('button', { name: /Activar canal WhatsApp Marketing/i });
    fireEvent.click(activateBtn);

    await waitFor(() => {
      expect(api.updateChannelStatus).toHaveBeenCalledWith('ch-inactive', true);
    });
  });

  it('opens ConfirmModal when clicking delete and cancels without deleting', async () => {
    renderChannelsView();

    await waitFor(() => {
      expect(screen.getByText('WhatsApp Marketing')).toBeInTheDocument();
    });

    // Click delete on channel
    const deleteBtn = screen.getByRole('button', { name: /Eliminar canal WhatsApp Marketing/i });
    fireEvent.click(deleteBtn);

    expect(screen.getByTestId('confirm-delete-channel-modal')).toBeInTheDocument();
    expect(screen.getByText('¿Eliminar canal «WhatsApp Marketing»?')).toBeInTheDocument();
    expect(screen.getByText(/Esta acción eliminará la configuración del canal y no se podrá deshacer/i)).toBeInTheDocument();

    // Click Cancelar
    const cancelBtn = screen.getByTestId('cancel-delete-channel-btn');
    fireEvent.click(cancelBtn);

    expect(screen.queryByTestId('confirm-delete-channel-modal')).not.toBeInTheDocument();
    expect(api.deleteChannel).not.toHaveBeenCalled();
  });

  it('executes channel deletion when confirmed in ConfirmModal and handles retry with force if needed', async () => {
    const errorWithForce: any = new Error('El canal tiene conversaciones asociadas.');
    errorWithForce.status = 422;
    errorWithForce.data = {
      message: 'El canal tiene 3 conversación(es) asociada(s). Confirma la eliminación forzada.',
      requires_force: true,
      conversations_count: 3,
    };

    vi.mocked(api.deleteChannel)
      .mockRejectedValueOnce(errorWithForce)
      .mockResolvedValueOnce({ message: 'Canal eliminado exitosamente.' } as any);

    renderChannelsView();

    await waitFor(() => {
      expect(screen.getByText('WhatsApp Marketing')).toBeInTheDocument();
    });

    const deleteBtn = screen.getByRole('button', { name: /Eliminar canal WhatsApp Marketing/i });
    fireEvent.click(deleteBtn);

    const confirmBtn = screen.getByTestId('confirm-delete-channel-btn');
    expect(confirmBtn).toHaveTextContent('Sí, eliminar canal');
    fireEvent.change(screen.getByTestId('confirm-delete-channel-modal-confirmation-input'), {
      target: { value: 'WhatsApp Marketing' },
    });
    fireEvent.click(confirmBtn);

    await waitFor(() => {
      expect(api.deleteChannel).toHaveBeenCalledWith('ch-inactive', false);
      expect(api.deleteChannel).toHaveBeenCalledWith('ch-inactive', true);
    });

    await waitFor(() => {
      expect(screen.getByText('Canal eliminado exitosamente.')).toBeInTheDocument();
    });
  });
});

describe('ChannelsView Inactivity Auto-Close Configuration', () => {
  const mockChannelWithInactivity = {
    id: 'ch-webchat-1',
    name: 'Canal Webchat Demo',
    type: 'webchat',
    is_active: true,
    entry_flow_id: null,
    created_at: '2026-10-10T10:00:00Z',
    updated_at: '2026-10-10T10:00:00Z',
    configuration: {
      inactivity_enabled: true,
      inactivity_warning_minutes: 8,
      inactivity_warning_message: 'Mensaje personalizado de advertencia para {{nombre}}',
      inactivity_close_minutes: 18,
      inactivity_close_message: 'Mensaje de cierre por inactividad',
    },
    webchat_settings: {
      id: 'ws-demo',
      public_key: 'wb_demo_123',
      display_name: 'Asistente Demo',
      allowed_origins: ['http://localhost:3000'],
      accent_color: '#2563EB',
      launcher_position: 'bottom-right',
    },
  };

  beforeEach(() => {
    vi.restoreAllMocks();
    vi.mocked(api.getChannels).mockResolvedValue({ data: [mockChannelWithInactivity as any] });
    vi.mocked(api.getFlows).mockResolvedValue({ data: [] });
    vi.mocked(api.createChannel).mockResolvedValue({ message: 'Canal creado exitosamente.', data: mockChannelWithInactivity as any });
    vi.mocked(api.updateChannel).mockResolvedValue({ message: 'Canal actualizado exitosamente.', data: mockChannelWithInactivity as any });
  });

  it('renders inactivity auto-close fields in Create Modal with channel-type defaults', async () => {
    renderChannelsView();

    await waitFor(() => {
      expect(screen.getByText('Canal Webchat Demo')).toBeInTheDocument();
    });

    // Open create modal
    const openCreateBtn = screen.getByRole('button', { name: /Crear Canal/i });
    fireEvent.click(openCreateBtn);

    expect(screen.getByText('⏱️ Auto-cierre por Inactividad')).toBeInTheDocument();

    // Inactivity checkbox should be checked by default
    const checkbox = screen.getByLabelText(/Habilitar auto-cierre por inactividad/i) as HTMLInputElement;
    expect(checkbox.checked).toBe(true);

    // Default for webchat: 10 warning, 15 close
    const warningInput = screen.getByLabelText(/Tiempo para advertencia \(minutos\)/i) as HTMLInputElement;
    const closeInput = screen.getByLabelText(/Tiempo para cierre definitivo \(minutos\)/i) as HTMLInputElement;
    expect(warningInput.value).toBe('10');
    expect(closeInput.value).toBe('15');

    // Switch to WhatsApp channel type: defaults should update to 15 warning, 30 close
    const waButton = screen.getByRole('button', { name: /WhatsApp Cloud API/i });
    fireEvent.click(waButton);

    expect(warningInput.value).toBe('15');
    expect(closeInput.value).toBe('30');

    // Switch back to Webchat: defaults should update back to 10 warning, 15 close
    const dialog = screen.getByRole('dialog');
    const webchatButton = within(dialog).getByRole('button', { name: /^Webchat\b/i });
    fireEvent.click(webchatButton);

    expect(warningInput.value).toBe('10');
    expect(closeInput.value).toBe('15');
  });

  it('submits inactivity configuration when creating a channel', async () => {
    renderChannelsView();

    await waitFor(() => {
      expect(screen.getByText('Canal Webchat Demo')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByRole('button', { name: /Crear Canal/i }));

    // Fill form
    fireEvent.change(screen.getByPlaceholderText(/Ej. Webchat Sitio Corporativo/i), {
      target: { value: 'Nuevo Canal con Inactividad' },
    });

    const warningInput = screen.getByLabelText(/Tiempo para advertencia \(minutos\)/i);
    fireEvent.change(warningInput, { target: { value: '12' } });

    const warningMsg = screen.getByLabelText(/Mensaje de advertencia/i);
    fireEvent.change(warningMsg, { target: { value: 'Oye {{nombre}}, ¿sigues ahí?' } });

    const closeInput = screen.getByLabelText(/Tiempo para cierre definitivo \(minutos\)/i);
    fireEvent.change(closeInput, { target: { value: '25' } });

    const closeMsg = screen.getByLabelText(/Mensaje de despedida/i);
    fireEvent.change(closeMsg, { target: { value: 'Sesión finalizada por inactividad.' } });

    // Submit form (second 'Crear Canal' button is the submit button inside modal)
    const submitBtn = screen.getAllByRole('button', { name: /Crear Canal/i })[1];
    fireEvent.click(submitBtn);

    await waitFor(() => {
      expect(api.createChannel).toHaveBeenCalledWith(
        expect.objectContaining({
          name: 'Nuevo Canal con Inactividad',
          configuration: expect.objectContaining({
            inactivity_enabled: true,
            inactivity_warning_minutes: 12,
            inactivity_warning_message: 'Oye {{nombre}}, ¿sigues ahí?',
            inactivity_close_minutes: 25,
            inactivity_close_message: 'Sesión finalizada por inactividad.',
          }),
        })
      );
    });
  });

  it('loads existing inactivity configuration in Edit Modal and saves updates', async () => {
    renderChannelsView();

    await waitFor(() => {
      expect(screen.getByText('Canal Webchat Demo')).toBeInTheDocument();
    });

    // Open edit modal for the channel
    const editBtn = screen.getByTitle(/Editar configuración del canal/i);
    fireEvent.click(editBtn);

    expect(screen.getByText(/Editar Canal: Canal Webchat Demo/i)).toBeInTheDocument();

    const warningInput = screen.getByLabelText(/Tiempo para advertencia \(minutos\)/i) as HTMLInputElement;
    const warningMsg = screen.getByLabelText(/Mensaje de advertencia/i) as HTMLTextAreaElement;
    const closeInput = screen.getByLabelText(/Tiempo para cierre definitivo \(minutos\)/i) as HTMLInputElement;
    const closeMsg = screen.getByLabelText(/Mensaje de despedida/i) as HTMLTextAreaElement;

    expect(warningInput.value).toBe('8');
    expect(warningMsg.value).toBe('Mensaje personalizado de advertencia para {{nombre}}');
    expect(closeInput.value).toBe('18');
    expect(closeMsg.value).toBe('Mensaje de cierre por inactividad');

    // Update close minutes
    fireEvent.change(closeInput, { target: { value: '20' } });

    // Save changes
    fireEvent.click(screen.getByRole('button', { name: /Guardar Cambios/i }));

    await waitFor(() => {
      expect(api.updateChannel).toHaveBeenCalledWith(
        'ch-webchat-1',
        expect.objectContaining({
          configuration: expect.objectContaining({
            inactivity_enabled: true,
            inactivity_warning_minutes: 8,
            inactivity_close_minutes: 20,
          }),
        })
      );
    });
  });

  it('toggles visibility of inactivity fields when switch is toggled', async () => {
    renderChannelsView();

    await waitFor(() => {
      expect(screen.getByText('Canal Webchat Demo')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByRole('button', { name: /Crear Canal/i }));

    expect(screen.getByLabelText(/Tiempo para advertencia \(minutos\)/i)).toBeInTheDocument();

    // Toggle off
    const checkbox = screen.getByLabelText(/Habilitar auto-cierre por inactividad/i);
    fireEvent.click(checkbox);

    expect(screen.queryByLabelText(/Tiempo para advertencia \(minutos\)/i)).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/Tiempo para cierre definitivo \(minutos\)/i)).not.toBeInTheDocument();

    // Toggle on again
    fireEvent.click(checkbox);

    expect(screen.getByLabelText(/Tiempo para advertencia \(minutos\)/i)).toBeInTheDocument();
  });

  it('toggles warning phase with inactivity_warning_enabled checkbox', async () => {
    renderChannelsView();

    await waitFor(() => {
      expect(screen.getByText('Canal Webchat Demo')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByRole('button', { name: /Crear Canal/i }));

    const warningCheckbox = screen.getByLabelText(/Enviar advertencia previa al usuario/i) as HTMLInputElement;
    expect(warningCheckbox).toBeInTheDocument();
    expect(warningCheckbox.checked).toBe(true);

    expect(screen.getByLabelText(/Tiempo para advertencia \(minutos\)/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Mensaje de advertencia/i)).toBeInTheDocument();

    // Toggle off warning phase
    fireEvent.click(warningCheckbox);
    expect(warningCheckbox.checked).toBe(false);

    // Phase 1 inputs should be hidden
    expect(screen.queryByLabelText(/Tiempo para advertencia \(minutos\)/i)).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/Mensaje de advertencia/i)).not.toBeInTheDocument();

    // Informative note should be visible
    expect(
      screen.getByText(/La conversación se cerrará directamente al cumplirse el tiempo de cierre definitivo sin enviar un aviso previo/i)
    ).toBeInTheDocument();

    // Phase 2 should still be visible
    expect(screen.getByLabelText(/Tiempo para cierre definitivo \(minutos\)/i)).toBeInTheDocument();

    // Toggle on warning phase again
    fireEvent.click(warningCheckbox);
    expect(warningCheckbox.checked).toBe(true);

    expect(screen.getByLabelText(/Tiempo para advertencia \(minutos\)/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Mensaje de advertencia/i)).toBeInTheDocument();
  });

  it('submits inactivity_warning_enabled: false when warning is disabled', async () => {
    renderChannelsView();

    await waitFor(() => {
      expect(screen.getByText('Canal Webchat Demo')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByRole('button', { name: /Crear Canal/i }));

    fireEvent.change(screen.getByPlaceholderText(/Ej. Webchat Sitio Corporativo/i), {
      target: { value: 'Canal Cierre Directo' },
    });

    const warningCheckbox = screen.getByLabelText(/Enviar advertencia previa al usuario/i);
    fireEvent.click(warningCheckbox);

    const submitBtn = screen.getAllByRole('button', { name: /Crear Canal/i })[1];
    fireEvent.click(submitBtn);

    await waitFor(() => {
      expect(api.createChannel).toHaveBeenCalledWith(
        expect.objectContaining({
          name: 'Canal Cierre Directo',
          configuration: expect.objectContaining({
            inactivity_enabled: true,
            inactivity_warning_enabled: false,
          }),
        })
      );
    });
  });

  it('renders modal with scrollable body container and fixed sticky header and footer', async () => {
    renderChannelsView();

    await waitFor(() => {
      expect(screen.getByText('Canal Webchat Demo')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByRole('button', { name: /Crear Canal/i }));

    const dialog = screen.getByRole('dialog');
    const modalCard = dialog.querySelector('.modal-content');
    expect(modalCard).toHaveClass('channel-side-panel');

    const header = dialog.querySelector('.modal-header');
    expect(header).toHaveClass('shrink-0');

    const form = dialog.querySelector('form');
    expect(form).toHaveClass('overflow-hidden');

    const scrollableBody = dialog.querySelector('.overscroll-contain');
    expect(scrollableBody).toHaveClass('overflow-y-auto');
    expect(scrollableBody).toHaveClass('flex-1');

    const footer = dialog.querySelector('.modal-footer');
    expect(footer).toHaveClass('shrink-0');
  });
});
