import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { FlowEditor } from '../FlowEditor';
import { api } from '../../../services/api';
import type { Flow, FlowVersion } from '../../../types';
import { AuthContext } from '../../../context/authContextDef';

vi.mock('../../../services/api', () => ({
  api: {
    getFlow: vi.fn(),
    getFlowVersion: vi.fn(),
    saveFlowVersionGraph: vi.fn(),
    validateFlowVersion: vi.fn(),
    publishFlowVersion: vi.fn(),
    createFlowVersion: vi.fn(),
  },
}));

describe('FlowEditor V2 Enhancements', () => {
  const mockFlow: Flow = {
    id: 'flow-v2',
    name: 'Flujo Empresarial V2',
    description: null,
    status: 'draft',
    channel_applicability: ['webchat'],
    active_version_id: 'ver-v2',
    created_at: '2026-10-04T10:00:00Z',
    updated_at: '2026-10-04T10:00:00Z',
    versions: [
      {
        id: 'ver-v2',
        flow_id: 'flow-v2',
        version_number: 1,
        status: 'draft',
        description: null,
        published_at: null,
        updated_at: '2026-10-04T10:00:00Z',
      },
    ],
  };

  const mockVersionWithBranches: FlowVersion = {
    id: 'ver-v2',
    flow_id: 'flow-v2',
    version_number: 1,
    status: 'draft',
    description: null,
    published_at: null,
    updated_at: '2026-10-04T10:00:00Z',
    nodes: [
      {
        id: 'n-start',
        node_key: 'node_start',
        type: 'start',
        name: 'Inicio',
        configuration: {},
        position_x: 200,
        position_y: 50,
      },
      {
        id: 'n-menu',
        node_key: 'node_menu',
        type: 'question',
        name: 'Menú Principal',
        configuration: { text: '¿Qué departamento buscas?', variable: 'dept' },
        position_x: 200,
        position_y: 200,
      },
      {
        id: 'n-sales',
        node_key: 'node_sales',
        type: 'message',
        name: 'Ventas Especializadas',
        configuration: { text: 'Conectando con Ventas' },
        position_x: 50,
        position_y: 380,
      },
      {
        id: 'n-sales-end',
        node_key: 'node_sales_end',
        type: 'end',
        name: 'Fin Ventas',
        configuration: {},
        position_x: 50,
        position_y: 540,
      },
      {
        id: 'n-support',
        node_key: 'node_support',
        type: 'transfer_to_agent',
        name: 'Soporte Humano',
        configuration: {},
        position_x: 350,
        position_y: 380,
      },
    ],
    edges: [
      {
        id: 'e1',
        source_node_key: 'node_start',
        target_node_key: 'node_menu',
      },
      {
        id: 'e2',
        source_node_key: 'node_menu',
        target_node_key: 'node_sales',
      },
      {
        id: 'e3',
        source_node_key: 'node_sales',
        target_node_key: 'node_sales_end',
      },
      {
        id: 'e4',
        source_node_key: 'node_menu',
        target_node_key: 'node_support',
      },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    window.alert = vi.fn();
    window.confirm = vi.fn(() => true);
  });

  it('renders search button and opens in-editor search modal', async () => {
    vi.mocked(api.getFlow).mockResolvedValueOnce({ data: mockFlow });
    vi.mocked(api.getFlowVersion).mockResolvedValueOnce({ data: mockVersionWithBranches });

    render(<FlowEditor flowId="flow-v2" initialVersionId="ver-v2" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getAllByText('Flujo Empresarial V2').length).toBeGreaterThanOrEqual(1);
    });

    const searchBtn = screen.getByRole('button', { name: /Buscar/i });
    expect(searchBtn).toBeInTheDocument();

    fireEvent.click(searchBtn);

    // Search input should appear
    const searchInput = screen.getByPlaceholderText(/Buscar bloques por nombre/i);
    expect(searchInput).toBeInTheDocument();

    // Type query
    fireEvent.change(searchInput, { target: { value: 'Ventas Especializadas' } });

    await waitFor(() => {
      expect(screen.getAllByText('Ventas Especializadas').length).toBeGreaterThanOrEqual(1);
      expect(screen.getAllByText(/node_sales/i).length).toBeGreaterThanOrEqual(1);
    });
  }, 15000);

  it('provides Autoorganizar action and recalculates layout', async () => {
    vi.mocked(api.getFlow).mockResolvedValueOnce({ data: mockFlow });
    vi.mocked(api.getFlowVersion).mockResolvedValueOnce({ data: mockVersionWithBranches });

    render(<FlowEditor flowId="flow-v2" initialVersionId="ver-v2" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getAllByText('Flujo Empresarial V2').length).toBeGreaterThanOrEqual(1);
    });

    const autoOrganizeBtn = screen.getByRole('button', { name: /Autoorganizar/i });
    expect(autoOrganizeBtn).toBeInTheDocument();

    fireEvent.click(autoOrganizeBtn);

    // Flow becomes dirty after auto-organize
    await waitFor(() => {
      expect(screen.getByText(/Cambios sin guardar/i)).toBeInTheDocument();
    });
  });

  it('renders unified continuous canvas without residual tabs and with all nodes visible', async () => {
    vi.mocked(api.getFlow).mockResolvedValueOnce({ data: mockFlow });
    vi.mocked(api.getFlowVersion).mockResolvedValueOnce({ data: mockVersionWithBranches });

    render(<FlowEditor flowId="flow-v2" initialVersionId="ver-v2" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getAllByText('Flujo Empresarial V2').length).toBeGreaterThanOrEqual(1);
    });

    // Residual tabs "Completa" and "General" are completely removed from toolbar
    expect(screen.queryByRole('button', { name: /^Completa$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^General$/i })).not.toBeInTheDocument();

    // Verify all nodes from the complete flow are rendered in continuous canvas
    expect(screen.getByText('Inicio')).toBeInTheDocument();
    expect(screen.getByText('Menú Principal')).toBeInTheDocument();
    expect(screen.getByText('Ventas Especializadas')).toBeInTheDocument();
    expect(screen.getByText('Soporte Humano')).toBeInTheDocument();
  });

  it('saves all nodes in backend payload even when branches are visually collapsed', async () => {
    vi.mocked(api.getFlow).mockResolvedValueOnce({ data: mockFlow });
    vi.mocked(api.getFlowVersion).mockResolvedValueOnce({ data: mockVersionWithBranches });
    vi.mocked(api.saveFlowVersionGraph).mockResolvedValueOnce({
      message: 'Guardado',
      data: { updated_at: '2026-10-04T10:10:00Z' },
    } as any);

    render(<FlowEditor flowId="flow-v2" initialVersionId="ver-v2" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getAllByText('Flujo Empresarial V2').length).toBeGreaterThanOrEqual(1);
    });

    // Make dirty by adding a message node
    const addMsgBtn = screen.getByTitle('Añadir Mensaje');
    fireEvent.click(addMsgBtn);

    // Save
    const saveBtn = screen.getByRole('button', { name: /^Guardar$/i });
    fireEvent.click(saveBtn);

    await waitFor(() => {
      expect(api.saveFlowVersionGraph).toHaveBeenCalledTimes(1);
    });

    const payload = vi.mocked(api.saveFlowVersionGraph).mock.calls[0][2];
    // 5 original nodes + 1 added = 6 nodes
    expect(payload.nodes.length).toBe(6);
    expect(payload.edges.length).toBe(4);
  });

  it('respects permission security: shows warning and disables edits if user lacks flows.edit', async () => {
    vi.mocked(api.getFlow).mockResolvedValueOnce({ data: mockFlow });
    vi.mocked(api.getFlowVersion).mockResolvedValueOnce({ data: mockVersionWithBranches });

    const mockAuthContext: any = {
      user: { id: 'agent-1', name: 'Agente Regular', email: 'agent@conexa.ai' },
      roles: ['agent'],
      permissions: ['flows.view'], // Lacks flows.edit!
      isAuthenticated: true,
      isLoading: false,
      login: vi.fn(),
      logout: vi.fn(),
      hasPermission: (perm: string) => perm === 'flows.view',
      hasRole: (role: string) => role === 'agent',
    };

    render(
      <AuthContext.Provider value={mockAuthContext}>
        <FlowEditor flowId="flow-v2" initialVersionId="ver-v2" onBack={vi.fn()} />
      </AuthContext.Provider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Permisos insuficientes para editar/i)).toBeInTheDocument();
    });

    // Editing buttons should be disabled or absent
    expect(screen.queryByRole('button', { name: /^Guardar$/i })).not.toBeInTheDocument();
    const autoOrganizeBtn = screen.getByRole('button', { name: /Autoorganizar/i });
    expect(autoOrganizeBtn).toBeDisabled();
  });
});
