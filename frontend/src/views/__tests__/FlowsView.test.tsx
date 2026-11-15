import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { FlowsView } from '../FlowsView';
import { api } from '../../services/api';

vi.mock('../../context/useAuth', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai' },
    hasPermission: () => true,
  }),
}));

vi.mock('../../services/api', () => ({
  api: {
    getFlows: vi.fn(),
    getFlow: vi.fn(),
    getChannels: vi.fn(),
    getFlowVersions: vi.fn(),
    createFlow: vi.fn(),
    deleteFlow: vi.fn(),
    publishFlowVersion: vi.fn(),
    rollbackFlowVersion: vi.fn(),
  },
}));

describe('FlowsView Safe Deletion', () => {
  const mockFlows = [
    {
      id: 'flow-linked',
      name: 'Flujo de Ventas',
      description: 'Captura y calificación de leads',
      status: 'published',
      channel_applicability: ['webchat'],
      active_version_id: 'v-1',
      active_version_number: 1,
      created_by: 'Admin Conexa',
      updated_by: 'Admin Conexa',
      created_at: '2026-10-10T10:00:00Z',
      updated_at: '2026-10-10T10:00:00Z',
    },
    {
      id: 'flow-free',
      name: 'Flujo Experimental',
      description: 'Borrador de prueba',
      status: 'draft',
      channel_applicability: ['webchat'],
      active_version_id: null,
      active_version_number: null,
      created_by: 'Admin Conexa',
      updated_by: 'Admin Conexa',
      created_at: '2026-10-10T11:00:00Z',
      updated_at: '2026-10-10T11:00:00Z',
    },
  ];

  const mockChannels = [
    {
      id: 'ch-webchat',
      name: 'Webchat Principal',
      entry_flow_id: 'flow-linked',
    },
    {
      id: 'ch-support',
      name: 'Soporte WhatsApp',
      entry_flow_id: null,
    },
  ];

  beforeEach(() => {
    vi.restoreAllMocks();
    vi.mocked(api.getFlows).mockResolvedValue({ data: mockFlows as any });
    vi.mocked(api.getChannels).mockResolvedValue({ data: mockChannels as any });
    vi.mocked(api.getFlowVersions).mockResolvedValue({
      data: [{ id: 'v-1', version_number: 1, status: 'published' }] as any,
      active_version_id: 'v-1',
    });
  });

  it('renders flows table and delete button for each flow', async () => {
    render(<FlowsView />);

    await waitFor(() => {
      expect(screen.getByText('Flujo de Ventas')).toBeInTheDocument();
      expect(screen.getByText('Flujo Experimental')).toBeInTheDocument();
    });

    const deleteButtons = screen.getAllByTitle('Eliminar flujo');
    expect(deleteButtons).toHaveLength(2);
  });

  it('blocks deletion when flow is configured as entry flow in channels', async () => {
    render(<FlowsView />);

    await waitFor(() => {
      expect(screen.getByText('Flujo de Ventas')).toBeInTheDocument();
    });

    const deleteBtn = screen.getByLabelText('Eliminar flujo Flujo de Ventas');
    fireEvent.click(deleteBtn);

    await waitFor(() => {
      expect(screen.getByText('Eliminar Flujo Conversacional')).toBeInTheDocument();
      expect(screen.getByText('Eliminación bloqueada:')).toBeInTheDocument();
      expect(screen.getByText('Webchat Principal')).toBeInTheDocument();
    });

    // Delete confirmation input and action button must not be present when blocked
    expect(screen.queryByPlaceholderText('Flujo de Ventas')).not.toBeInTheDocument();
    expect(screen.queryByText('Eliminar flujo definitivamente')).not.toBeInTheDocument();
  });

  it('requires exact flow name confirmation to enable deletion button', async () => {
    render(<FlowsView />);

    await waitFor(() => {
      expect(screen.getByText('Flujo Experimental')).toBeInTheDocument();
    });

    const deleteBtn = screen.getByLabelText('Eliminar flujo Flujo Experimental');
    fireEvent.click(deleteBtn);

    await waitFor(() => {
      expect(screen.getByText('Eliminar Flujo Conversacional')).toBeInTheDocument();
      expect(screen.queryByText('Eliminación bloqueada:')).not.toBeInTheDocument();
    });

    const input = screen.getByPlaceholderText('Flujo Experimental');
    const submitBtn = screen.getByText('Eliminar flujo definitivamente').closest('button');

    expect(submitBtn).toBeDisabled();

    // Type partial or wrong name
    fireEvent.change(input, { target: { value: 'Flujo' } });
    expect(submitBtn).toBeDisabled();

    // Type exact matching name
    fireEvent.change(input, { target: { value: 'Flujo Experimental' } });
    expect(submitBtn).not.toBeDisabled();
  });

  it('deletes flow successfully and updates the UI', async () => {
    vi.mocked(api.deleteFlow).mockResolvedValue({
      message: "Flujo 'Flujo Experimental' eliminado exitosamente.",
    });

    render(<FlowsView />);

    await waitFor(() => {
      expect(screen.getByText('Flujo Experimental')).toBeInTheDocument();
    });

    const deleteBtn = screen.getByLabelText('Eliminar flujo Flujo Experimental');
    fireEvent.click(deleteBtn);

    await waitFor(() => {
      expect(screen.getByText('Eliminar Flujo Conversacional')).toBeInTheDocument();
    });

    const input = screen.getByPlaceholderText('Flujo Experimental');
    fireEvent.change(input, { target: { value: 'Flujo Experimental' } });

    const submitBtn = screen.getByText('Eliminar flujo definitivamente').closest('button');
    expect(submitBtn).not.toBeDisabled();

    fireEvent.click(submitBtn!);

    await waitFor(() => {
      expect(api.deleteFlow).toHaveBeenCalledWith('flow-free', false);
      expect(screen.getByText("Flujo 'Flujo Experimental' eliminado exitosamente.")).toBeInTheDocument();
    });
  });

  it('handles backend requires_force response by prompting for forced deletion', async () => {
    const errorResponse = {
      message: 'El flujo tiene 3 conversación(es) activa(s).',
      data: {
        requires_force: true,
        conversations_count: 3,
      },
    };
    vi.mocked(api.deleteFlow)
      .mockRejectedValueOnce(errorResponse)
      .mockResolvedValueOnce({
        message: 'Flujo eliminado con force.',
      });

    render(<FlowsView />);

    await waitFor(() => {
      expect(screen.getByText('Flujo Experimental')).toBeInTheDocument();
    });

    const deleteBtn = screen.getByLabelText('Eliminar flujo Flujo Experimental');
    fireEvent.click(deleteBtn);

    await waitFor(() => {
      expect(screen.getByText('Eliminar Flujo Conversacional')).toBeInTheDocument();
    });

    const input = screen.getByPlaceholderText('Flujo Experimental');
    fireEvent.change(input, { target: { value: 'Flujo Experimental' } });

    const submitBtn = screen.getByText('Eliminar flujo definitivamente').closest('button');
    fireEvent.click(submitBtn!);

    await waitFor(() => {
      expect(screen.getByText(/Advertencia de integridad referencial:/)).toBeInTheDocument();
      expect(screen.getByText(/3 conversación\(es\) activa\(s\)/)).toBeInTheDocument();
    });

    // Click again to submit with force: true
    fireEvent.click(submitBtn!);

    await waitFor(() => {
      expect(api.deleteFlow).toHaveBeenLastCalledWith('flow-free', true);
    });
  });
});
