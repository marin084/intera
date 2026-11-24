import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { ContactDirectoryView } from '../ContactDirectoryView';
import { api } from '../../services/api';

const mockHasRole = vi.fn();

vi.mock('../../context/useAuth', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Workspace Admin', email: 'admin@test.com', is_platform_admin: false },
    hasRole: mockHasRole,
  }),
}));

vi.mock('../../services/api', () => ({
  api: {
    getContacts: vi.fn(),
    getContact: vi.fn(),
    getContactsImportPreview: vi.fn(),
    importContacts: vi.fn(),
    getContactsExportUrl: vi.fn(() => '/api/v1/contacts/export'),
  },
}));

const contact = {
  id: 'contact-1',
  display_name: 'Ana Pérez',
  external_identifier: 'visitor-ana',
  email: 'ana@example.test',
  phone: null,
  country: 'CR',
  conversations_count: 2,
  last_activity_at: '2026-11-22T12:00:00Z',
  channels: [{ id: 'channel-1', name: 'Webchat', type: 'webchat' }],
  variables: { pedido: 'A-100' },
  variable_count: 1,
  attributes: {},
  conversations: [{
    id: 'conversation-1',
    status: 'waiting_agent',
    channel: { id: 'channel-1', name: 'Webchat', type: 'webchat' },
    started_at: null,
    last_activity_at: '2026-11-22T12:00:00Z',
    closed_at: null,
  }],
};

describe('ContactDirectoryView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockHasRole.mockReturnValue(true);
    vi.mocked(api.getContacts).mockResolvedValue({
      data: [contact],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
    vi.mocked(api.getContact).mockResolvedValue({ data: contact });
    vi.mocked(api.getContactsImportPreview).mockResolvedValue({
      data: {
        headers: ['external_id', 'correo_cliente', 'nombre'],
        sample: [{ external_id: 'one', correo_cliente: 'one@example.test', nombre: 'One' }],
        row_count: 1,
        suggested_mapping: { external_identifier: 'external_id', email: 'correo_cliente', display_name: 'nombre' },
        available_channels: [{ id: 'channel-1', name: 'Webchat', type: 'webchat' }],
      },
    });
    vi.mocked(api.importContacts).mockResolvedValue({
      message: 'ok',
      data: { total_rows: 1, created: 1, updated: 0, skipped: 0, errors: [] },
    });
  });

  it('lists contacts and opens the read-only profile', async () => {
    render(<ContactDirectoryView />);

    await waitFor(() => expect(screen.getByText('Ana Pérez')).toBeInTheDocument());
    expect(screen.getByText('Webchat')).toBeInTheDocument();
    expect(screen.getByText('1')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Ver ficha' }));
    await waitFor(() => expect(screen.getByText('Ficha del contacto')).toBeInTheDocument());
    expect(screen.getByText('pedido')).toBeInTheDocument();
    expect(screen.getByText('A-100')).toBeInTheDocument();
    expect(screen.getByText('Esperando agente')).toBeInTheDocument();

    fireEvent.keyDown(window, { key: 'Escape' });
    await waitFor(() => expect(screen.queryByText('Ficha del contacto')).not.toBeInTheDocument());
    expect(screen.getByRole('button', { name: 'Ver ficha' })).toHaveFocus();
  });

  it('shows controls when the directory has more than one page', async () => {
    vi.mocked(api.getContacts).mockResolvedValue({
      data: [contact],
      meta: { current_page: 1, last_page: 2, per_page: 25, total: 26 },
    });

    render(<ContactDirectoryView />);
    await waitFor(() => expect(screen.getByText('Página 1 de 2')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: 'Siguiente' })).toBeEnabled();
  });

  it('shows the empty state when no contacts match', async () => {
    vi.mocked(api.getContacts).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
    });

    render(<ContactDirectoryView />);
    await waitFor(() => expect(screen.getByText('Todavía no hay contactos')).toBeInTheDocument());
  });

  it('does not render the directory for non-admin users', () => {
    mockHasRole.mockReturnValue(false);
    render(<ContactDirectoryView />);
    expect(screen.getByText('Acceso restringido')).toBeInTheDocument();
    expect(api.getContacts).not.toHaveBeenCalled();
  });

  it('previews a CSV and lets the administrator adjust the mapping before importing', async () => {
    render(<ContactDirectoryView />);
    await waitFor(() => expect(screen.getByText('Ana Pérez')).toBeInTheDocument());

    const file = new File(['external_id,correo_cliente,nombre\none,one@example.test,One'], 'contactos.csv', { type: 'text/csv' });
    fireEvent.change(screen.getByLabelText('Seleccionar CSV'), { target: { files: [file] } });

    await waitFor(() => expect(screen.getByRole('heading', { name: 'Importar contactos desde CSV' })).toBeInTheDocument());
    expect(screen.getByText('1 filas detectadas')).toBeInTheDocument();
    expect(screen.getByLabelText(/Correo electrónico/)).toHaveValue('correo_cliente');

    fireEvent.change(screen.getByLabelText(/Correo electrónico/), { target: { value: 'external_id' } });
    expect(screen.getByLabelText(/Correo electrónico/)).toHaveValue('external_id');
    fireEvent.change(screen.getByLabelText('Canal destino'), { target: { value: 'channel-1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Importar contactos' }));

    await waitFor(() => expect(api.importContacts).toHaveBeenCalledWith(file, expect.objectContaining({
      external_identifier: 'external_id',
      email: 'external_id',
    }), 'channel-1'));
  });

  it('does not add an unnecessary source-channel mapping step', async () => {
    vi.mocked(api.getContactsImportPreview).mockResolvedValue({
      data: {
        headers: ['id', 'correo', 'canal'],
        sample: [{ id: 'one', correo: 'one@example.test', canal: 'WhatsApp;Canal histórico' }],
        row_count: 1,
        suggested_mapping: { external_identifier: 'id', email: 'correo' },
        available_channels: [{ id: 'channel-1', name: 'WhatsApp', type: 'whatsapp' }],
      },
    });

    render(<ContactDirectoryView />);
    await waitFor(() => expect(screen.getByText('Ana Pérez')).toBeInTheDocument());
    const file = new File(['id,correo,canal\none,one@example.test,"WhatsApp;Canal histórico"'], 'contactos.csv', { type: 'text/csv' });
    fireEvent.change(screen.getByLabelText('Seleccionar CSV'), { target: { files: [file] } });

    await waitFor(() => expect(screen.getByRole('heading', { name: 'Importar contactos desde CSV' })).toBeInTheDocument());
    expect(screen.queryByText('Canal de origen (opcional)')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Canales de origen')).not.toBeInTheDocument();
  });
});
