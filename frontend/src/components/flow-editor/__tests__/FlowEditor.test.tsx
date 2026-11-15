import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { FlowEditor } from '../FlowEditor';
import { api } from '../../../services/api';
import type { Flow, FlowVersion } from '../../../types';

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

describe('FlowEditor', () => {
  const mockFlow: Flow = {
    id: 'flow-123',
    name: 'Flujo de Ventas',
    description: null,
    status: 'draft',
    channel_applicability: ['webchat'],
    active_version_id: 'ver-draft',
    created_at: '2026-10-04T10:00:00Z',
    updated_at: '2026-10-04T10:00:00Z',
    versions: [
      {
        id: 'ver-draft',
        flow_id: 'flow-123',
        version_number: 1,
        status: 'draft',
        description: null,
        published_at: null,
        updated_at: '2026-10-04T10:00:00Z',
      },
    ],
  };

  const mockVersionDraft: FlowVersion = {
    id: 'ver-draft',
    flow_id: 'flow-123',
    version_number: 1,
    status: 'draft',
    description: null,
    published_at: null,
    updated_at: '2026-10-04T10:00:00Z',
    nodes: [
      {
        id: 'node-start',
        node_key: 'start_1',
        type: 'start',
        name: 'Inicio',
        configuration: {},
        position_x: 200,
        position_y: 100,
      },
      {
        id: 'node-msg',
        node_key: 'msg_welcome',
        type: 'message',
        name: 'Bienvenida',
        configuration: { text: 'Hola bienvenido' },
        position_x: 200,
        position_y: 250,
      },
    ],
    edges: [
      {
        id: 'edge-1',
        source_node_key: 'start_1',
        target_node_key: 'msg_welcome',
      },
    ],
  };

  const mockVersionPublished: FlowVersion = {
    ...mockVersionDraft,
    id: 'ver-pub',
    version_number: 2,
    status: 'published',
  };

  beforeEach(() => {
    vi.clearAllMocks();
    window.alert = vi.fn();
    window.confirm = vi.fn(() => true);

    let store: Record<string, string> = {};
    const localStorageMock = {
      getItem: (key: string) => store[key] || null,
      setItem: (key: string, value: string) => {
        store[key] = value.toString();
      },
      removeItem: (key: string) => {
        delete store[key];
      },
      clear: () => {
        store = {};
      },
    };
    Object.defineProperty(window, 'localStorage', {
      value: localStorageMock,
      writable: true,
    });
  });

  it('loads flow and draft version, renders toolbar and nodes', async () => {
    vi.mocked(api.getFlow).mockResolvedValueOnce({ data: mockFlow } as any);
    vi.mocked(api.getFlowVersion).mockResolvedValueOnce({ data: mockVersionDraft } as any);

    render(<FlowEditor flowId="flow-123" initialVersionId="ver-draft" onBack={vi.fn()} />);

    // Initial loading indicator
    expect(screen.getByText(/Cargando constructor de flujos/i)).toBeInTheDocument();

    // Loaded state
    await waitFor(() => {
      expect(screen.getAllByText('Flujo de Ventas').length).toBeGreaterThanOrEqual(1);
    });

    expect(screen.getAllByText(/Borrador/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getByRole('button', { name: /^Guardar$/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Validar flujo/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Publicar$/i })).toBeInTheDocument();
    expect(screen.getByText(/Guardado/i)).toBeInTheDocument();
  });

  it('renders read-only banner and disables saving when version is published', async () => {
    const publishedFlow: Flow = {
      ...mockFlow,
      active_version_id: 'ver-pub',
      versions: [mockVersionPublished],
    };

    vi.mocked(api.getFlow).mockResolvedValueOnce({ data: publishedFlow } as any);
    vi.mocked(api.getFlowVersion).mockResolvedValueOnce({ data: mockVersionPublished } as any);

    render(<FlowEditor flowId="flow-123" initialVersionId="ver-pub" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getByText(/publicada — solo lectura/i)).toBeInTheDocument();
    });

    expect(screen.getAllByRole('button', { name: /Crear nueva versión/i }).length).toBeGreaterThanOrEqual(1);
    expect(screen.queryByRole('button', { name: /^Guardar$/i })).not.toBeInTheDocument();
  });

  it('adds nodes from palette, modifies dirty state, and saves payload with positions', async () => {
    vi.mocked(api.getFlow).mockResolvedValueOnce({ data: mockFlow } as any);
    vi.mocked(api.getFlowVersion).mockResolvedValueOnce({ data: mockVersionDraft } as any);
    vi.mocked(api.saveFlowVersionGraph).mockResolvedValueOnce({
      message: 'Guardado con éxito',
      data: { updated_at: '2026-10-04T10:05:00Z' },
    } as any);

    render(<FlowEditor flowId="flow-123" initialVersionId="ver-draft" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getAllByText('Flujo de Ventas').length).toBeGreaterThanOrEqual(1);
    });

    // Palette: click "Pregunta" to add a Question node
    const addQuestionBtn = screen.getByTitle('Añadir Pregunta');
    fireEvent.click(addQuestionBtn);

    // Dirty state should now indicate unsaved changes
    await waitFor(() => {
      expect(screen.getByText(/Cambios sin guardar/i)).toBeInTheDocument();
    });

    // Click "Guardar"
    const saveButton = screen.getByRole('button', { name: /^Guardar$/i });
    fireEvent.click(saveButton);

    await waitFor(() => {
      expect(api.saveFlowVersionGraph).toHaveBeenCalledTimes(1);
    });

    // Check payload structure sent to API
    const callArgs = vi.mocked(api.saveFlowVersionGraph).mock.calls[0];
    expect(callArgs[0]).toBe('flow-123');
    expect(callArgs[1]).toBe('ver-draft');

    const payload = callArgs[2];
    expect(payload.expected_updated_at).toBe('2026-10-04T10:00:00Z');
    expect(payload.nodes.length).toBeGreaterThanOrEqual(3); // Start, Message, + New Question

    // Verify node properties in save payload
    const startPayloadNode = payload.nodes.find((n: any) => n.type === 'start');
    expect(startPayloadNode).toBeDefined();
    expect(startPayloadNode.node_key).toBe('start_1');
    expect(startPayloadNode.position_x).toBe(200);
    expect(startPayloadNode.position_y).toBe(100);

    const questionPayloadNode = payload.nodes.find((n: any) => n.type === 'question');
    expect(questionPayloadNode).toBeDefined();
    expect(questionPayloadNode.node_key).toContain('node_question_');

    // State returns to saved clean
    await waitFor(() => {
      expect(screen.getByText(/Guardado/i)).toBeInTheDocument();
    });
  });

  it('handles optimistic concurrency conflict (409 Conflict) on save', async () => {
    vi.mocked(api.getFlow).mockResolvedValueOnce({ data: mockFlow });
    vi.mocked(api.getFlowVersion).mockResolvedValueOnce({ data: mockVersionDraft });

    const conflictError: any = new Error('Conflict');
    conflictError.status = 409;
    vi.mocked(api.saveFlowVersionGraph).mockRejectedValueOnce(conflictError);

    render(<FlowEditor flowId="flow-123" initialVersionId="ver-draft" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getAllByText('Flujo de Ventas').length).toBeGreaterThanOrEqual(1);
    });

    // Make dirty by adding a node
    const addMsgBtn = screen.getByTitle('Añadir Mensaje');
    fireEvent.click(addMsgBtn);

    const saveButton = screen.getByRole('button', { name: /^Guardar$/i });
    fireEvent.click(saveButton);

    await waitFor(() => {
      expect(screen.getByText(/Este flujo fue modificado en otra sesión/i)).toBeInTheDocument();
    });
  });

  it('shows validation errors drawer and structured error messages', async () => {
    vi.mocked(api.getFlow).mockResolvedValueOnce({ data: mockFlow });
    vi.mocked(api.getFlowVersion).mockResolvedValueOnce({ data: mockVersionDraft });
    vi.mocked(api.validateFlowVersion).mockResolvedValueOnce({
      valid: false,
      is_valid: false,
      errors: ['El nodo msg_welcome no tiene conexiones salientes.'],
      structured_errors: [
        {
          code: 'DEAD_END',
          node_key: 'msg_welcome',
          message: 'El nodo msg_welcome no tiene conexiones salientes.',
        },
      ],
    } as any);

    render(<FlowEditor flowId="flow-123" initialVersionId="ver-draft" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getAllByText('Flujo de Ventas').length).toBeGreaterThanOrEqual(1);
    });

    // Click "Validar flujo"
    const validateBtn = screen.getByRole('button', { name: /Validar flujo/i });
    fireEvent.click(validateBtn);

    await waitFor(() => {
      expect(screen.getByText(/1 problema encontrado/i)).toBeInTheDocument();
    });

    expect(screen.getByText('El nodo msg_welcome no tiene conexiones salientes.')).toBeInTheDocument();
    expect(screen.getByText('msg_welcome')).toBeInTheDocument();
    expect(screen.getByText('Localizar')).toBeInTheDocument();
  });

  it('publishes flow successfully when valid and confirmed', async () => {
    vi.mocked(api.getFlow).mockResolvedValue({ data: mockFlow });
    vi.mocked(api.getFlowVersion).mockResolvedValue({ data: mockVersionDraft });
    vi.mocked(api.validateFlowVersion).mockResolvedValueOnce({
      valid: true,
      is_valid: true,
      errors: [],
      structured_errors: [],
    } as any);
    vi.mocked(api.publishFlowVersion).mockResolvedValueOnce({
      message: 'Versión publicada con éxito.',
      data: mockVersionPublished,
    });

    render(<FlowEditor flowId="flow-123" initialVersionId="ver-draft" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getAllByText('Flujo de Ventas').length).toBeGreaterThanOrEqual(1);
    });

    // Click "Publicar" in toolbar
    const publishBtn = screen.getByRole('button', { name: /^Publicar$/i });
    fireEvent.click(publishBtn);

    // Modal opens
    await waitFor(() => {
      expect(screen.getByText(/Publicar versión 1/i)).toBeInTheDocument();
    });

    expect(screen.getByText(/Una versión publicada es/i)).toBeInTheDocument();

    // Confirm in modal
    const confirmPublishBtn = screen.getByRole('button', { name: /Publicar versión/i });
    fireEvent.click(confirmPublishBtn);

    await waitFor(() => {
      expect(api.publishFlowVersion).toHaveBeenCalledWith('flow-123', 'ver-draft');
    });

    // Success step rendered in modal without browser alert
    await waitFor(() => {
      expect(screen.getByText(/¡Versión 1 publicada con éxito!/i)).toBeInTheDocument();
    });
    expect(
      screen.getByText(/Esta versión ya está activa y atendiendo a los usuarios en tus canales conectados/i)
    ).toBeInTheDocument();
    expect(window.alert).not.toHaveBeenCalled();

    // Click "Continuar en el editor" to close success modal
    const continueBtn = screen.getByRole('button', { name: /Continuar en el editor/i });
    fireEvent.click(continueBtn);

    await waitFor(() => {
      expect(screen.queryByText(/¡Versión 1 publicada con éxito!/i)).not.toBeInTheDocument();
    });
  });

  it('opens styled CreateDraftModal and clones version without window.confirm', async () => {
    vi.mocked(api.getFlow).mockResolvedValue({
      data: {
        ...mockFlow,
        active_version_id: 'ver-published',
        versions: [mockVersionPublished],
      },
    });
    vi.mocked(api.getFlowVersion).mockResolvedValue({ data: mockVersionPublished });
    vi.mocked(api.createFlowVersion).mockResolvedValueOnce({
      message: 'Versión borrador creada',
      data: {
        ...mockVersionDraft,
        id: 'ver-draft-2',
        version_number: 2,
      },
    } as any);

    render(<FlowEditor flowId="flow-123" initialVersionId="ver-published" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getAllByText('Flujo de Ventas').length).toBeGreaterThanOrEqual(1);
    });

    // Click "Crear nueva versión borrador" in toolbar
    const cloneBtn = screen.getByRole('button', { name: /Crear nueva versión borrador/i });
    fireEvent.click(cloneBtn);

    // Styled modal opens instead of window.confirm
    await waitFor(() => {
      expect(screen.getByText('Crear nueva versión borrador')).toBeInTheDocument();
    });
    expect(screen.getByText(/Vas a generar una nueva versión borrador basada en la/i)).toBeInTheDocument();
    expect(screen.getByText('Versión 2')).toBeInTheDocument();

    // Click "Crear borrador" in modal
    const confirmDraftBtn = screen.getByRole('button', { name: /^Crear borrador$/i });
    fireEvent.click(confirmDraftBtn);

    await waitFor(() => {
      expect(api.createFlowVersion).toHaveBeenCalledWith('flow-123', expect.objectContaining({
        from_version_id: 'ver-pub',
      }));
    });
  });

  it('allows collapsing properties panel, persists in localStorage, and restores via expand tab', async () => {
    vi.mocked(api.getFlow).mockResolvedValue({ data: mockFlow });
    vi.mocked(api.getFlowVersion).mockResolvedValue({ data: mockVersionDraft });

    render(<FlowEditor flowId="flow-123" initialVersionId="ver-draft" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getAllByText('Flujo de Ventas').length).toBeGreaterThanOrEqual(1);
    });

    const panel = screen.getByTestId('properties-panel');
    expect(panel).not.toHaveClass('collapsed');

    // Click collapse button
    const toggleBtn = screen.getByTestId('properties-toggle-btn');
    fireEvent.click(toggleBtn);

    expect(panel).toHaveClass('collapsed');
    expect(localStorage.getItem('conexa_flow_properties_collapsed')).toBe('true');

    // Floating expand tab must now be visible
    const expandTab = screen.getByTestId('properties-expand-tab');
    expect(expandTab).toBeInTheDocument();

    // Click expand tab to restore
    fireEvent.click(expandTab);
    expect(panel).not.toHaveClass('collapsed');
    expect(localStorage.getItem('conexa_flow_properties_collapsed')).toBe('false');
  });

  it('toggles Zen / Fullscreen mode and responds to Escape key', async () => {
    vi.mocked(api.getFlow).mockResolvedValue({ data: mockFlow });
    vi.mocked(api.getFlowVersion).mockResolvedValue({ data: mockVersionDraft });

    render(<FlowEditor flowId="flow-123" initialVersionId="ver-draft" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getAllByText('Flujo de Ventas').length).toBeGreaterThanOrEqual(1);
    });

    const zenBtn = screen.getByTestId('zen-mode-toggle-btn');
    expect(zenBtn).toBeInTheDocument();
    expect(document.body).not.toHaveClass('zen-mode');

    // Toggle on
    fireEvent.click(zenBtn);
    expect(document.body).toHaveClass('zen-mode');

    // Toggle off
    fireEvent.click(zenBtn);
    expect(document.body).not.toHaveClass('zen-mode');

    // Keyboard shortcut F11
    fireEvent.keyDown(window, { key: 'F11' });
    expect(document.body).toHaveClass('zen-mode');

    // Escape exits zen mode
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(document.body).not.toHaveClass('zen-mode');
  });

  it('toggles canvas-first palette drawer via toolbar button, floating canvas button, close button, and escape key', async () => {
    vi.mocked(api.getFlow).mockResolvedValue({ data: mockFlow });
    vi.mocked(api.getFlowVersion).mockResolvedValue({ data: mockVersionDraft });

    render(<FlowEditor flowId="flow-123" initialVersionId="ver-draft" onBack={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getAllByText('Flujo de Ventas').length).toBeGreaterThanOrEqual(1);
    });

    const paletteDrawer = screen.getByTestId('node-palette-drawer');
    expect(paletteDrawer).toHaveClass('is-closed');

    // 1. Open via toolbar button
    const toolbarBtn = screen.getByTestId('toggle-palette-btn');
    fireEvent.click(toolbarBtn);
    expect(paletteDrawer).toHaveClass('is-open');

    // 2. Close via close button in drawer header
    const closeBtn = screen.getByTestId('close-palette-btn');
    fireEvent.click(closeBtn);
    expect(paletteDrawer).toHaveClass('is-closed');

    // 3. Open via floating canvas button
    const floatingBtn = screen.getByTestId('floating-add-node-btn');
    fireEvent.click(floatingBtn);
    expect(paletteDrawer).toHaveClass('is-open');

    // 4. Close via Escape key
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(paletteDrawer).toHaveClass('is-closed');
  });
});
