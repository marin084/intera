import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { FlowSimulatorDrawer } from '../FlowSimulatorDrawer';
import { api } from '../../../services/api';
import type { Flow, FlowVersion, SimulationState } from '../../../types';

vi.mock('../../../services/api', () => ({
  api: {
    startSimulation: vi.fn(),
    stepSimulation: vi.fn(),
    resetSimulation: vi.fn(),
    stopSimulation: vi.fn(),
    getSimulationState: vi.fn(),
  },
}));

describe('FlowSimulatorDrawer Component', () => {
  const mockFlow: Flow = {
    id: 'flow-123',
    name: 'Flujo de Ventas',
    description: 'Flujo de prueba',
    status: 'draft',
    channel_applicability: ['webchat'],
    active_version_id: 'ver-456',
    created_at: '2026-10-08T00:00:00Z',
    updated_at: '2026-10-08T00:00:00Z',
  };

  const mockVersion: FlowVersion = {
    id: 'ver-456',
    flow_id: 'flow-123',
    version_number: 1,
    status: 'draft',
    description: null,
    published_at: null,
    nodes: [],
    edges: [],
    updated_at: '2026-10-08T00:00:00Z',
  };

  const initialSimulationState: SimulationState = {
    simulation_id: 'sim-789',
    execution_id: 'exec-101',
    conversation_id: 'conv-202',
    flow_id: 'flow-123',
    flow_version_id: 'ver-456',
    version_number: 1,
    version_status: 'draft',
    status: 'waiting_input',
    current_node: {
      id: 'node-1',
      node_key: 'menu_inicio',
      name: 'Menú Bienvenida',
      type: 'menu',
      configuration: {
        title: 'Selecciona una opción:',
        options: [
          { id: '1', text: 'Comprar', value: 'opt_comprar' },
          { id: '2', text: 'Soporte', value: 'opt_soporte' },
        ],
      },
    },
    context: {
      user_name: 'Simulador Conexa',
    },
    messages: [
      {
        id: 'msg-1',
        sender_type: 'bot',
        direction: 'outbound',
        content: '¡Hola! Bienvenido a Conexa. Selecciona una opción:',
        metadata: {
          choices: ['Comprar', 'Soporte'],
        },
      },
    ],
    steps: [
      {
        id: 'step-1',
        step_number: 1,
        flow_node_id: 'node-start',
        node_key: 'start_1',
        node_name: 'Inicio',
        node_type: 'start',
        status: 'completed',
      },
      {
        id: 'step-2',
        step_number: 2,
        flow_node_id: 'node-1',
        node_key: 'menu_inicio',
        node_name: 'Menú Bienvenida',
        node_type: 'menu',
        status: 'waiting',
      },
    ],
    events: [
      {
        order: 1,
        type: 'SIMULATION_STARTED',
        label: 'Simulación iniciada',
        status: 'success',
        details: 'Contexto de memoria inicializado.',
      },
      {
        order: 2,
        type: 'STEP_START',
        node_key: 'start_1',
        node_name: 'Inicio',
        label: 'Nodo inicial ejecutado: Inicio',
        status: 'success',
      },
      {
        order: 3,
        type: 'STEP_MENU',
        node_key: 'menu_inicio',
        node_name: 'Menú Bienvenida',
        label: 'Menú interactivo presentado: esperando selección (menu_inicio)',
        status: 'waiting',
      },
    ],
    taken_edge: {
      id: 'edge-start-to-menu',
      source_node_key: 'start_1',
      target_node_key: 'menu_inicio',
    },
  };

  beforeEach(() => {
    vi.clearAllMocks();
    (api.startSimulation as any).mockResolvedValue({ data: initialSimulationState });
  });

  it('renders nothing when isOpen is false', () => {
    const { container } = render(
      <FlowSimulatorDrawer
        isOpen={false}
        flow={mockFlow}
        version={mockVersion}
        onClose={vi.fn()}
      />
    );
    expect(container.firstChild).toBeNull();
  });

  it('starts simulation and renders conversational chat with interactive buttons', async () => {
    const onActiveNodeChange = vi.fn();
    const onActiveEdgeChange = vi.fn();

    render(
      <FlowSimulatorDrawer
        isOpen={true}
        flow={mockFlow}
        version={mockVersion}
        onClose={vi.fn()}
        onActiveNodeChange={onActiveNodeChange}
        onActiveEdgeChange={onActiveEdgeChange}
      />
    );

    expect(api.startSimulation).toHaveBeenCalledWith('flow-123', 'ver-456', {});

    await waitFor(() => {
      expect(screen.getByText('Simulador de Flujo')).toBeInTheDocument();
      expect(screen.getByText('¡Hola! Bienvenido a Conexa. Selecciona una opción:')).toBeInTheDocument();
      expect(screen.getByText('Comprar')).toBeInTheDocument();
      expect(screen.getByText('Soporte')).toBeInTheDocument();
    });

    await waitFor(() => {
      expect(onActiveNodeChange).toHaveBeenCalledWith('menu_inicio', false);
      expect(onActiveEdgeChange).toHaveBeenCalledWith(expect.objectContaining({
        source_node_key: 'start_1',
        target_node_key: 'menu_inicio',
      }));
    });
  });

  it('allows clicking an option button to step the simulation', async () => {
    const stepResponse: SimulationState = {
      ...initialSimulationState,
      status: 'completed',
      messages: [
        ...initialSimulationState.messages,
        {
          id: 'msg-user-1',
          sender_type: 'contact',
          direction: 'inbound',
          content: 'Comprar',
        },
        {
          id: 'msg-bot-2',
          sender_type: 'bot',
          direction: 'outbound',
          content: 'Gracias por tu compra.',
        },
      ],
      context: {
        user_name: 'Simulador Conexa',
        opcion_elegida: 'Comprar',
      },
    };

    (api.stepSimulation as any).mockResolvedValue({ data: stepResponse });

    render(
      <FlowSimulatorDrawer
        isOpen={true}
        flow={mockFlow}
        version={mockVersion}
        onClose={vi.fn()}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Comprar')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText('Comprar'));

    expect(api.stepSimulation).toHaveBeenCalledWith('flow-123', 'ver-456', 'exec-101', 'Comprar');

    await waitFor(() => {
      expect(screen.getByText('Gracias por tu compra.')).toBeInTheDocument();
    });
  });

  it('allows typing a message in the input and sending it', async () => {
    (api.stepSimulation as any).mockResolvedValue({
      data: {
        ...initialSimulationState,
        messages: [
          ...initialSimulationState.messages,
          {
            id: 'msg-user-custom',
            sender_type: 'contact',
            direction: 'inbound',
            content: 'Hola bot',
          },
        ],
      },
    });

    render(
      <FlowSimulatorDrawer
        isOpen={true}
        flow={mockFlow}
        version={mockVersion}
        onClose={vi.fn()}
      />
    );

    await waitFor(() => {
      expect(screen.getByPlaceholderText('Escribe tu respuesta o selección...')).toBeInTheDocument();
    });

    const input = screen.getByPlaceholderText('Escribe tu respuesta o selección...');
    fireEvent.change(input, { target: { value: 'Hola bot' } });
    fireEvent.submit(input.closest('form')!);

    expect(api.stepSimulation).toHaveBeenCalledWith('flow-123', 'ver-456', 'exec-101', 'Hola bot');
  });

  it('renders simulated transfer banner when is_simulated_transfer is true', async () => {
    (api.startSimulation as any).mockResolvedValue({
      data: {
        ...initialSimulationState,
        status: 'completed',
        messages: [
          {
            id: 'msg-transfer',
            sender_type: 'bot',
            direction: 'outbound',
            content: 'El bot transferiría esta conversación a un agente.',
            metadata: {
              is_simulated_transfer: true,
            },
          },
        ],
      },
    });

    render(
      <FlowSimulatorDrawer
        isOpen={true}
        flow={mockFlow}
        version={mockVersion}
        onClose={vi.fn()}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Transferencia Simulada a Agente')).toBeInTheDocument();
      expect(
        screen.getByText(/En un entorno real, esta conversación pasaría a la bandeja de atención humana/i)
      ).toBeInTheDocument();
    });
  });

  it('inspects current node and variables in the Inspector tab', async () => {
    const onFocusNode = vi.fn();

    render(
      <FlowSimulatorDrawer
        isOpen={true}
        flow={mockFlow}
        version={mockVersion}
        onClose={vi.fn()}
        onFocusNode={onFocusNode}
      />
    );

    await waitFor(() => {
      expect(screen.getByText(/Inspector/)).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText(/Inspector/));

    expect(screen.getByText('A. Nodo Actual en Ejecución')).toBeInTheDocument();
    expect(screen.getByText('Menú Bienvenida')).toBeInTheDocument();
    expect(screen.getByText('key: menu_inicio')).toBeInTheDocument();

    // Centrar en lienzo
    const centerBtn = screen.getByText('Centrar en lienzo');
    fireEvent.click(centerBtn);
    expect(onFocusNode).toHaveBeenCalledWith('menu_inicio');

    // Variables en memoria
    expect(screen.getByText('B. Variables en Memoria (1)')).toBeInTheDocument();
    expect(screen.getByText('{{user_name}}')).toBeInTheDocument();
    expect(screen.getByText('Simulador Conexa')).toBeInTheDocument();
  });

  it('allows injecting a custom initial variable in the Inspector tab', async () => {
    render(
      <FlowSimulatorDrawer
        isOpen={true}
        flow={mockFlow}
        version={mockVersion}
        onClose={vi.fn()}
      />
    );

    await waitFor(() => {
      expect(screen.getByText(/Inspector/)).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText(/Inspector/));

    const injectToggle = screen.getByText('Inyectar variable');
    fireEvent.click(injectToggle);

    const keyInput = screen.getByPlaceholderText('variable (ej. edad)');
    const valInput = screen.getByPlaceholderText('valor (ej. 25)');
    const addBtn = screen.getByText('Agregar');

    fireEvent.change(keyInput, { target: { value: 'edad_cliente' } });
    fireEvent.change(valInput, { target: { value: '30' } });
    fireEvent.click(addBtn);

    expect(screen.getByText('edad_cliente:')).toBeInTheDocument();
    expect(screen.getByText('30')).toBeInTheDocument();

    const restartWithVarsBtn = screen.getByText('Reiniciar con estas variables');
    fireEvent.click(restartWithVarsBtn);

    await waitFor(() => {
      expect(api.startSimulation).toHaveBeenCalledWith('flow-123', 'ver-456', {
        edad_cliente: 30,
      });
    });
  });

  it('renders chronological event timeline in Events tab', async () => {
    render(
      <FlowSimulatorDrawer
        isOpen={true}
        flow={mockFlow}
        version={mockVersion}
        onClose={vi.fn()}
      />
    );

    await waitFor(() => {
      expect(screen.getByText(/Eventos/)).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText(/Eventos/));

    expect(screen.getByText('Simulación iniciada')).toBeInTheDocument();
    expect(screen.getByText('Nodo inicial ejecutado: Inicio')).toBeInTheDocument();
    expect(
      screen.getByText('Menú interactivo presentado: esperando selección (menu_inicio)')
    ).toBeInTheDocument();
  });

  it('resets simulation cleanly when clicking Reiniciar', async () => {
    (api.resetSimulation as any).mockResolvedValue({ data: initialSimulationState });

    render(
      <FlowSimulatorDrawer
        isOpen={true}
        flow={mockFlow}
        version={mockVersion}
        onClose={vi.fn()}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Reiniciar')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText('Reiniciar'));

    await waitFor(() => {
      expect(api.resetSimulation).toHaveBeenCalledWith('flow-123', 'ver-456', 'exec-101', {});
    });
  });

  it('stops simulation when clicking Detener', async () => {
    (api.stopSimulation as any).mockResolvedValue({
      data: {
        ...initialSimulationState,
        status: 'cancelled',
      },
    });

    render(
      <FlowSimulatorDrawer
        isOpen={true}
        flow={mockFlow}
        version={mockVersion}
        onClose={vi.fn()}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Detener')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText('Detener'));

    await waitFor(() => {
      expect(api.stopSimulation).toHaveBeenCalledWith('flow-123', 'ver-456', 'exec-101');
    });
  });

  it('notifies null when closed', async () => {
    const onActiveNodeChange = vi.fn();
    const onActiveEdgeChange = vi.fn();

    const { rerender } = render(
      <FlowSimulatorDrawer
        isOpen={true}
        flow={mockFlow}
        version={mockVersion}
        onClose={vi.fn()}
        onActiveNodeChange={onActiveNodeChange}
        onActiveEdgeChange={onActiveEdgeChange}
      />
    );

    await waitFor(() => {
      expect(onActiveNodeChange).toHaveBeenCalledWith('menu_inicio', false);
    });

    rerender(
      <FlowSimulatorDrawer
        isOpen={false}
        flow={mockFlow}
        version={mockVersion}
        onClose={vi.fn()}
        onActiveNodeChange={onActiveNodeChange}
        onActiveEdgeChange={onActiveEdgeChange}
      />
    );

    await waitFor(() => {
      expect(onActiveNodeChange).toHaveBeenCalledWith(null);
      expect(onActiveEdgeChange).toHaveBeenCalledWith(null);
    });
  });
});
