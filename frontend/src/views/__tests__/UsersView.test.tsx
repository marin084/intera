import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { UsersView } from '../UsersView';
import { api } from '../../services/api';

const mockHasPermission = vi.fn();

vi.mock('../../context/useAuth', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai' },
    hasPermission: mockHasPermission,
  }),
}));

vi.mock('../../services/api', () => ({
  api: {
    getUsers: vi.fn(),
    getRoles: vi.fn(),
    createUser: vi.fn(),
    updateUser: vi.fn(),
    updateUserStatus: vi.fn(),
  },
}));

describe('UsersView Confirmation Modals (ConfirmModal)', () => {
  const mockUsers = [
    {
      id: 101,
      name: 'Carlos Mendoza',
      email: 'carlos@empresa.com',
      is_active: true,
      last_login_at: '2026-11-01T12:00:00Z',
      roles: [{ id: 1, name: 'agent', display_name: 'Agente' }],
    },
    {
      id: 102,
      name: 'Lucía Fernández',
      email: 'lucia@empresa.com',
      is_active: false,
      last_login_at: null,
      roles: [{ id: 2, name: 'flow_manager', display_name: 'Gestor de Flujos' }],
    },
  ];

  const mockRoles = [
    { id: 1, name: 'agent', display_name: 'Agente' },
    { id: 2, name: 'flow_manager', display_name: 'Gestor de Flujos' },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    mockHasPermission.mockReturnValue(true);
    vi.mocked(api.getUsers).mockResolvedValue({ data: mockUsers as any });
    vi.mocked(api.getRoles).mockResolvedValue({ data: mockRoles as any });
    vi.mocked(api.updateUserStatus).mockResolvedValue({
      message: 'Estado actualizado correctamente.',
      data: {},
    } as any);
  });

  it('renders user table with active and inactive indicators', async () => {
    render(<UsersView />);

    await waitFor(() => {
      expect(screen.getByText('Carlos Mendoza')).toBeInTheDocument();
      expect(screen.getByText('Lucía Fernández')).toBeInTheDocument();
    });

    expect(screen.getByText('Activo')).toBeInTheDocument();
    expect(screen.getByText('Inactivo')).toBeInTheDocument();
  });

  it('opens ConfirmModal when clicking Desactivar and cancels without API call', async () => {
    render(<UsersView />);

    await waitFor(() => {
      expect(screen.getByText('Carlos Mendoza')).toBeInTheDocument();
    });

    // Click Desactivar on active user
    const deactivateBtn = screen.getByRole('button', { name: /Desactivar usuario Carlos Mendoza/i });
    fireEvent.click(deactivateBtn);

    // ConfirmModal should be displayed with expected title and copy
    expect(screen.getByTestId('confirm-deactivate-user-modal')).toBeInTheDocument();
    expect(screen.getByText('¿Desactivar al usuario «Carlos Mendoza»?')).toBeInTheDocument();
    expect(
      screen.getByText(/Se cerrarán sus sesiones activas y perderá el acceso a la plataforma/i)
    ).toBeInTheDocument();

    // Cancel action
    const cancelBtn = screen.getByTestId('cancel-user-modal-btn');
    fireEvent.click(cancelBtn);

    expect(screen.queryByTestId('confirm-deactivate-user-modal')).not.toBeInTheDocument();
    expect(api.updateUserStatus).not.toHaveBeenCalled();
  });

  it('deactivates active user when confirmed in ConfirmModal', async () => {
    render(<UsersView />);

    await waitFor(() => {
      expect(screen.getByText('Carlos Mendoza')).toBeInTheDocument();
    });

    const deactivateBtn = screen.getByRole('button', { name: /Desactivar usuario Carlos Mendoza/i });
    fireEvent.click(deactivateBtn);

    const confirmBtn = screen.getByTestId('confirm-deactivate-user-btn');
    expect(confirmBtn).toHaveTextContent('Sí, desactivar usuario');
    fireEvent.click(confirmBtn);

    await waitFor(() => {
      expect(api.updateUserStatus).toHaveBeenCalledWith(101, false);
    });

    await waitFor(() => {
      expect(screen.getByTestId('user-action-success-alert')).toHaveTextContent(
        'El usuario «Carlos Mendoza» fue desactivado correctamente.'
      );
    });
  });

  it('opens ConfirmModal when clicking Activar on inactive user and cancels without API call', async () => {
    render(<UsersView />);

    await waitFor(() => {
      expect(screen.getByText('Lucía Fernández')).toBeInTheDocument();
    });

    // Click Activar on inactive user
    const activateBtn = screen.getByRole('button', { name: /Activar usuario Lucía Fernández/i });
    fireEvent.click(activateBtn);

    // ConfirmModal should be displayed with expected title and copy
    expect(screen.getByTestId('confirm-activate-user-modal')).toBeInTheDocument();
    expect(screen.getByText('¿Reactivar al usuario «Lucía Fernández»?')).toBeInTheDocument();
    expect(
      screen.getByText(/El usuario podrá volver a iniciar sesión y atender conversaciones/i)
    ).toBeInTheDocument();

    // Cancel action
    const cancelBtn = screen.getByTestId('cancel-user-modal-btn');
    fireEvent.click(cancelBtn);

    expect(screen.queryByTestId('confirm-activate-user-modal')).not.toBeInTheDocument();
    expect(api.updateUserStatus).not.toHaveBeenCalled();
  });

  it('reactivates inactive user when confirmed in ConfirmModal', async () => {
    render(<UsersView />);

    await waitFor(() => {
      expect(screen.getByText('Lucía Fernández')).toBeInTheDocument();
    });

    const activateBtn = screen.getByRole('button', { name: /Activar usuario Lucía Fernández/i });
    fireEvent.click(activateBtn);

    const confirmBtn = screen.getByTestId('confirm-activate-user-btn');
    expect(confirmBtn).toHaveTextContent('Sí, reactivar usuario');
    fireEvent.click(confirmBtn);

    await waitFor(() => {
      expect(api.updateUserStatus).toHaveBeenCalledWith(102, true);
    });

    await waitFor(() => {
      expect(screen.getByTestId('user-action-success-alert')).toHaveTextContent(
        'El usuario «Lucía Fernández» fue reactivado correctamente.'
      );
    });
  });

  it('closes ConfirmModal on Escape key without executing API mutation', async () => {
    render(<UsersView />);

    await waitFor(() => {
      expect(screen.getByText('Carlos Mendoza')).toBeInTheDocument();
    });

    const deactivateBtn = screen.getByRole('button', { name: /Desactivar usuario Carlos Mendoza/i });
    fireEvent.click(deactivateBtn);

    expect(screen.getByTestId('confirm-deactivate-user-modal')).toBeInTheDocument();

    fireEvent.keyDown(window, { key: 'Escape' });

    await waitFor(() => {
      expect(screen.queryByTestId('confirm-deactivate-user-modal')).not.toBeInTheDocument();
    });
    expect(api.updateUserStatus).not.toHaveBeenCalled();
  });

  it('displays error alert when updateUserStatus API call fails', async () => {
    vi.mocked(api.updateUserStatus).mockRejectedValueOnce(new Error('Fallo de red en el servidor.'));

    render(<UsersView />);

    await waitFor(() => {
      expect(screen.getByText('Carlos Mendoza')).toBeInTheDocument();
    });

    const deactivateBtn = screen.getByRole('button', { name: /Desactivar usuario Carlos Mendoza/i });
    fireEvent.click(deactivateBtn);

    const confirmBtn = screen.getByTestId('confirm-deactivate-user-btn');
    fireEvent.click(confirmBtn);

    await waitFor(() => {
      expect(screen.getByTestId('user-action-error-alert')).toHaveTextContent(
        'Fallo de red en el servidor.'
      );
    });
  });

  it('hides action buttons when user does not have users.manage permission', async () => {
    mockHasPermission.mockReturnValue(false);

    render(<UsersView />);

    await waitFor(() => {
      expect(screen.getByText('Carlos Mendoza')).toBeInTheDocument();
    });

    expect(
      screen.queryByRole('button', { name: /Desactivar usuario Carlos Mendoza/i })
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: /Crear Usuario/i })
    ).not.toBeInTheDocument();
  });
});
