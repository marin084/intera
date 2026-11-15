import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { RolesView } from '../RolesView';
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
    getRoles: vi.fn(),
    getPermissions: vi.fn(),
    updateRole: vi.fn(),
  },
}));

describe('RolesView - Differentiated Auto-assignment Capacity & Naming', () => {
  const mockRoles = [
    {
      id: 1,
      name: 'super_admin',
      display_name: 'Super Administrador', // Backend might still have legacy or updated name
      description: 'Acceso total sin restricciones',
      max_concurrent_conversations: 0,
      permissions: [],
    },
    {
      id: 2,
      name: 'agent',
      display_name: 'Agente de Atención',
      description: 'Atención directa de chats asignados',
      max_concurrent_conversations: 5,
      permissions: [],
    },
    {
      id: 3,
      name: 'flow_manager',
      display_name: 'Gestor de Flujos',
      description: 'Gestión y publicación de flujos',
      max_concurrent_conversations: 0,
      permissions: [],
    },
  ];

  const mockPermissions = [
    {
      id: 1,
      name: 'roles.manage',
      display_name: 'Gestionar Roles',
      module: 'roles',
    },
  ];

  beforeEach(() => {
    vi.restoreAllMocks();
    mockHasPermission.mockReturnValue(true);
    vi.mocked(api.getRoles).mockResolvedValue({ data: mockRoles as any });
    vi.mocked(api.getPermissions).mockResolvedValue({ data: mockPermissions as any });
    vi.mocked(api.updateRole).mockResolvedValue({
      data: {
        id: 1,
        name: 'super_admin',
        display_name: 'Administrador',
        max_concurrent_conversations: 0,
        permissions: [],
      },
    } as any);
  });

  it('renders super_admin with visible name Administrador and shows 0 chats indicator', async () => {
    render(<RolesView />);

    await waitFor(() => {
      // Must be rendered as Administrador, not Super Administrador
      expect(screen.getByText('Administrador')).toBeInTheDocument();
      expect(screen.queryByText('Super Administrador')).not.toBeInTheDocument();
      expect(screen.getByText('Agente de Atención')).toBeInTheDocument();
    });

    // Administrador has 0 capacity => shows badge "Sin autoasignación"
    expect(screen.getAllByText('Sin autoasignación').length).toBeGreaterThan(0);

    // Administrador minus button is disabled at 0
    const adminMinusBtn = screen.getByLabelText('Disminuir capacidad de Administrador');
    expect(adminMinusBtn).toBeDisabled();
  });

  it('restricts minimum capacity to 1 for agent role and disables minus button at 1', async () => {
    render(<RolesView />);

    await waitFor(() => {
      expect(screen.getByText('Agente de Atención')).toBeInTheDocument();
    });

    const agentCapacityInput = screen.getByLabelText('Capacidad para Agente de Atención') as HTMLInputElement;
    expect(agentCapacityInput.value).toBe('5');

    const agentMinusBtn = screen.getByLabelText('Disminuir capacidad de Agente de Atención');

    // Decrease from 5 down to 1
    fireEvent.click(agentMinusBtn); // 4
    fireEvent.click(agentMinusBtn); // 3
    fireEvent.click(agentMinusBtn); // 2
    fireEvent.click(agentMinusBtn); // 1
    expect(agentCapacityInput.value).toBe('1');

    // At 1, minus button must be disabled for agent
    expect(agentMinusBtn).toBeDisabled();

    // Trying to type 0 clamps to min 1 for agent
    fireEvent.change(agentCapacityInput, { target: { value: '0' } });
    expect(agentCapacityInput.value).toBe('1');
  });

  it('allows setting and saving 0 capacity for Administrador', async () => {
    render(<RolesView />);

    await waitFor(() => {
      expect(screen.getByText('Administrador')).toBeInTheDocument();
    });

    const adminInput = screen.getByLabelText('Capacidad para Administrador') as HTMLInputElement;
    const adminPlusBtn = screen.getByLabelText('Aumentar capacidad de Administrador');
    const adminMinusBtn = screen.getByLabelText('Disminuir capacidad de Administrador');

    // Increase to 1
    fireEvent.click(adminPlusBtn);
    expect(adminInput.value).toBe('1');
    expect(adminMinusBtn).not.toBeDisabled();

    // Decrease back to 0
    fireEvent.click(adminMinusBtn);
    expect(adminInput.value).toBe('0');
    expect(adminMinusBtn).toBeDisabled();

    // Save 0 capacity
    const saveButtons = screen.getAllByRole('button', { name: 'Guardar' });
    fireEvent.click(saveButtons[0]);

    await waitFor(() => {
      expect(api.updateRole).toHaveBeenCalledWith(1, { max_concurrent_conversations: 0 });
    });
  });

  it('renders read-only capacity with Sin autoasignación badge for users without roles.manage', async () => {
    mockHasPermission.mockReturnValue(false);

    render(<RolesView />);

    await waitFor(() => {
      expect(screen.getByText('Administrador')).toBeInTheDocument();
    });

    expect(screen.queryByLabelText('Capacidad para Administrador')).not.toBeInTheDocument();
    expect(screen.getAllByText('Sin autoasignación').length).toBeGreaterThan(0);
  });
});
