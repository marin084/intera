import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { MenuNode } from '../nodes/MenuNode';
import { HttpRequestNode } from '../nodes/HttpRequestNode';
import { GotoNode } from '../nodes/GotoNode';
import { SetVariableNode } from '../nodes/SetVariableNode';
import { ContextualNodeMenu, type ContextualInsertionTarget } from '../ContextualNodeMenu';
import { VariableManagerModal } from '../VariableManagerModal';
import { MultichannelPreview } from '../MultichannelPreview';
import { PropertiesPanel } from '../PropertiesPanel';
import { ConexaEdge } from '../edges/ConexaEdge';
import type { FlowNode, FlowVariable } from '../../../types';

// Mock React Flow Handle and xyflow components
vi.mock('@xyflow/react', async () => {
  const actual = await vi.importActual('@xyflow/react');
  return {
    ...actual,
    Handle: ({ position, id, type }: any) => (
      <div data-testid={`handle-${type}-${position}-${id || 'default'}`} />
    ),
    EdgeLabelRenderer: ({ children }: any) => <div data-testid="edge-label-renderer">{children}</div>,
    BaseEdge: () => <svg><path /></svg>,
  };
});

describe('Conversational Builder V3 - Frontend Components', () => {
  describe('MenuNode', () => {
    it('renders menu node with options and handles', () => {
      const nodeProps = {
        id: 'menu_node_1',
        data: {
          node_key: 'menu_node_1',
          type: 'menu' as const,
          name: 'Menú Principal',
          configuration: {
            title: '¿Qué deseas hacer?',
            options: [
              { id: '1', text: 'Ofertar', value: 'opt_1' },
              { id: '2', text: 'Petición de oración', value: 'opt_2' },
              { id: '3', text: 'Consultas', value: 'opt_3' },
            ],
          },
          hasError: false,
          isReadOnly: false,
        },
        selected: false,
      } as any;

      render(<MenuNode {...nodeProps} />);

      expect(screen.getByText('Menú Principal')).toBeInTheDocument();
      expect(screen.getByText('¿Qué deseas hacer?')).toBeInTheDocument();
      expect(screen.getByText('Ofertar')).toBeInTheDocument();
      expect(screen.getByText('Petición de oración')).toBeInTheDocument();
      expect(screen.getByText('Consultas')).toBeInTheDocument();
      expect(screen.getByText('3 OPCIONES')).toBeInTheDocument();

      // Check option handles exist
      expect(screen.getByTestId('handle-source-bottom-opt_1')).toBeInTheDocument();
      expect(screen.getByTestId('handle-source-bottom-opt_2')).toBeInTheDocument();
      expect(screen.getByTestId('handle-source-bottom-opt_3')).toBeInTheDocument();
    });

    it('toggles compact and expanded mode on collapse button click', () => {
      const nodeProps = {
        id: 'menu_node_1',
        data: {
          node_key: 'menu_node_1',
          type: 'menu' as const,
          name: 'Menú Grande',
          configuration: {
            title: 'Menú con muchas opciones',
            options: [
              { id: '1', text: 'Opción 1' },
              { id: '2', text: 'Opción 2' },
              { id: '3', text: 'Opción 3' },
              { id: '4', text: 'Opción 4' },
            ],
          },
          hasError: false,
        },
      } as any;

      render(<MenuNode {...nodeProps} />);
      expect(screen.getByText('Opción 1')).toBeInTheDocument();

      const collapseBtn = screen.getByTitle('Vista compacta');
      fireEvent.click(collapseBtn);

      // In compact mode, options list is collapsed
      expect(screen.queryByText('Opción 1')).not.toBeInTheDocument();
      expect(screen.getByText('4 OPCIONES')).toBeInTheDocument();

      const expandBtn = screen.getByTitle('Vista detallada');
      fireEvent.click(expandBtn);
      expect(screen.getByText('Opción 1')).toBeInTheDocument();
    });

    it('renders hidden menu options with attenuated style and keeps their output handle in canvas', () => {
      const nodeProps = {
        id: 'menu_node_hidden',
        data: {
          node_key: 'menu_node_hidden',
          type: 'menu' as const,
          name: 'Menú con Opción Oculta',
          configuration: {
            title: 'Elige una opción:',
            options: [
              { id: '1', text: 'Ventas', is_hidden: false },
              { id: '2', text: 'Soporte', is_hidden: false },
              { id: '9', text: 'Varios (Admin)', is_hidden: true },
            ],
          },
          hasError: false,
          isReadOnly: false,
        },
        selected: false,
      } as any;

      render(<MenuNode {...nodeProps} />);

      // All options are visible in editor canvas
      expect(screen.getByText('Ventas')).toBeInTheDocument();
      expect(screen.getByText('Soporte')).toBeInTheDocument();
      expect(screen.getByText('Varios (Admin)')).toBeInTheDocument();

      // Hidden option badge
      const hiddenBadges = screen.getAllByTestId('badge-hidden-option');
      expect(hiddenBadges.length).toBe(1);
      expect(hiddenBadges[0]).toHaveTextContent('Oculta');

      // CRITICAL: Canvas output handles exist for ALL options including hidden one
      expect(screen.getByTestId('handle-source-bottom-opt_1')).toBeInTheDocument();
      expect(screen.getByTestId('handle-source-bottom-opt_2')).toBeInTheDocument();
      expect(screen.getByTestId('handle-source-bottom-opt_9')).toBeInTheDocument();
    });
  });

  describe('HttpRequestNode, GotoNode, SetVariableNode', () => {
    it('renders HttpRequestNode with method pill and URL', () => {
      const nodeProps = {
        id: 'http_1',
        data: {
          node_key: 'http_1',
          type: 'http_request' as const,
          name: 'Consultar Pedido',
          configuration: {
            method: 'POST',
            url: 'https://api.conexa.ai/orders',
            response_mapping: [{ path: 'order.status', variable: 'estadoPedido' }],
          },
        },
      } as any;

      render(<HttpRequestNode {...nodeProps} />);
      expect(screen.getByText('Consultar Pedido')).toBeInTheDocument();
      expect(screen.getByText('POST')).toBeInTheDocument();
      expect(screen.getByText('https://api.conexa.ai/orders')).toBeInTheDocument();
      expect(screen.getByText(/Extrae 1 variable/i)).toBeInTheDocument();
      expect(screen.getByTestId('handle-source-bottom-output')).toBeInTheDocument();
    });

    it('renders GotoNode with violet jump styling', () => {
      const nodeProps = {
        id: 'goto_1',
        data: {
          node_key: 'goto_1',
          type: 'goto' as const,
          name: 'Ir a Menú Principal',
          configuration: {
            target_node_key: 'menu_node_1',
          },
        },
      } as any;

      render(<GotoNode {...nodeProps} />);
      expect(screen.getByText('Ir a Menú Principal')).toBeInTheDocument();
      expect(screen.getByText('→ menu_node_1')).toBeInTheDocument();
      expect(screen.getByText('Ver')).toBeInTheDocument();
    });

    it('renders SetVariableNode with variable assignment pill', () => {
      const nodeProps = {
        id: 'var_1',
        data: {
          node_key: 'var_1',
          type: 'set_variable' as const,
          name: 'Guardar Estado',
          configuration: {
            variable: 'estadoPedido',
            operation: 'set',
            value: 'entregado',
          },
        },
      } as any;

      render(<SetVariableNode {...nodeProps} />);
      expect(screen.getByText('Guardar Estado')).toBeInTheDocument();
      expect(screen.getByText('$estadoPedido')).toBeInTheDocument();
      expect(screen.getByText('=')).toBeInTheDocument();
      expect(screen.getByText(/"entregado"/i)).toBeInTheDocument();
    });
  });

  describe('ContextualNodeMenu', () => {
    it('renders categories and filters blocks by search term', () => {
      const target: ContextualInsertionTarget = {
        type: 'canvas',
        position: { x: 200, y: 300 },
      };
      const onSelect = vi.fn();
      const onClose = vi.fn();

      render(
        <ContextualNodeMenu
          isOpen={true}
          target={target}
          onClose={onClose}
          onSelectNodeType={onSelect}
        />
      );

      expect(screen.getByText(/Añadir nuevo bloque al flujo/i)).toBeInTheDocument();
      expect(screen.getByText('Menú con opciones')).toBeInTheDocument();
      expect(screen.getByText('Petición HTTP')).toBeInTheDocument();
      expect(screen.getByText('Ir a bloque')).toBeInTheDocument();

      // Search filter
      const searchInput = screen.getByPlaceholderText(/Buscar bloques/i);
      fireEvent.change(searchInput, { target: { value: 'HTTP' } });

      expect(screen.getByText('Petición HTTP')).toBeInTheDocument();
      expect(screen.queryByText('Menú con opciones')).not.toBeInTheDocument();

      // Click HTTP item
      fireEvent.click(screen.getByText('Petición HTTP'));
      expect(onSelect).toHaveBeenCalledWith('http_request', target);
    });

    it('renders contextual menu for edge insertion with all requested node types', () => {
      const target: ContextualInsertionTarget = {
        type: 'edge',
        edgeId: 'edge_1',
        sourceNodeKey: 'node_start',
        targetNodeKey: 'node_msg',
        sourceHandle: 'true',
        position: { x: 200, y: 175 },
        clientPosition: { clientX: 300, clientY: 250 },
      };
      const onSelect = vi.fn();
      const onClose = vi.fn();

      render(
        <ContextualNodeMenu
          isOpen={true}
          target={target}
          onClose={onClose}
          onSelectNodeType={onSelect}
        />
      );

      // Contextual title for edge insertion
      expect(screen.getByText('Insertar bloque en la conexión')).toBeInTheDocument();
      expect(screen.getByText(/Divide la conexión e inserta el bloque automáticamente/i)).toBeInTheDocument();

      // All 9 core node types present
      expect(screen.getByTestId('contextual-node-message')).toBeInTheDocument();
      expect(screen.getByTestId('contextual-node-image')).toBeInTheDocument();
      expect(screen.getByTestId('contextual-node-file')).toBeInTheDocument();
      expect(screen.getByTestId('contextual-node-menu')).toBeInTheDocument();
      expect(screen.getByTestId('contextual-node-question')).toBeInTheDocument();
      expect(screen.getByTestId('contextual-node-condition')).toBeInTheDocument();
      expect(screen.getByTestId('contextual-node-set_variable')).toBeInTheDocument();
      expect(screen.getByTestId('contextual-node-http_request')).toBeInTheDocument();
      expect(screen.getByTestId('contextual-node-goto')).toBeInTheDocument();

      // Click condition node
      fireEvent.click(screen.getByTestId('contextual-node-condition'));
      expect(onSelect).toHaveBeenCalledWith('condition', target);
    });
  });

  describe('VariableManagerModal', () => {
    it('lists variables and detects references in flow nodes', () => {
      const mockVars: FlowVariable[] = [
        { name: 'user_name', type: 'text', scope: 'contact', description: 'Nombre' },
        { name: 'order_status', type: 'text', scope: 'conversation', description: 'Estado' },
      ];
      const mockNodes: FlowNode[] = [
        {
          id: 'n1',
          node_key: 'n1',
          type: 'message',
          name: 'Bienvenida',
          configuration: { text: 'Hola {{user_name}}' },
          position_x: 0,
          position_y: 0,
        },
      ];

      const onSave = vi.fn();
      const onClose = vi.fn();

      render(
        <VariableManagerModal
          isOpen={true}
          variables={mockVars}
          nodes={mockNodes}
          onClose={onClose}
          onSaveVariables={onSave}
        />
      );

      expect(screen.getByText('Administrador de Variables')).toBeInTheDocument();
      expect(screen.getByText('{{user_name}}')).toBeInTheDocument();
      expect(screen.getByText(/1 bloque/i)).toBeInTheDocument();
      expect(screen.getByText('{{order_status}}')).toBeInTheDocument();
      expect(screen.getByText('Sin uso')).toBeInTheDocument();

      // Open new variable form
      fireEvent.click(screen.getByText('Crear Variable'));
      expect(screen.getByPlaceholderText('ej: estadoPedido, nombreCliente')).toBeInTheDocument();

      // Fill and create variable
      fireEvent.change(screen.getByPlaceholderText('ej: estadoPedido, nombreCliente'), {
        target: { value: 'nuevo_saldo' },
      });
      fireEvent.click(screen.getByText('Guardar Variable'));

      // Check it was added to list and saved
      expect(screen.getByText('{{nuevo_saldo}}')).toBeInTheDocument();
      expect(onSave).toHaveBeenCalledWith(
        expect.arrayContaining([
          expect.objectContaining({ name: 'nuevo_saldo' }),
        ])
      );
    });
  });

  describe('MultichannelPreview', () => {
    it('switches between Webchat buttons and WhatsApp limits preview', () => {
      const options = [
        { id: '1', text: 'Opción 1' },
        { id: '2', text: 'Opción 2' },
        { id: '3', text: 'Opción 3' },
        { id: '4', text: 'Opción 4' },
      ];

      render(<MultichannelPreview title="Menú de Prueba" options={options} />);

      expect(screen.getByText(/Webchat/i)).toBeInTheDocument();
      expect(screen.getByText(/WhatsApp/i)).toBeInTheDocument();

      // Click WhatsApp tab
      fireEvent.click(screen.getByText(/WhatsApp/i));

      // 4 options => WhatsApp renders Interactive List, not Quick Replies (limit 3)
      expect(screen.getByText(/Lista Interactiva/i)).toBeInTheDocument();
      expect(screen.getByText(/Ver opciones/i)).toBeInTheDocument();
    });
  });

  describe('PropertiesPanel for V3 Nodes', () => {
    it('configures menu options and allows reordering and adding', () => {
      const menuNode = {
        id: 'menu_1',
        type: 'menu',
        data: {
          node_key: 'menu_1',
          type: 'menu' as const,
          name: 'Menú Test',
          configuration: {
            title: 'Selecciona una opción',
            options: [
              { id: '1', text: 'Opción Uno', value: 'opt_1' },
              { id: '2', text: 'Opción Dos', value: 'opt_2' },
            ],
          },
          hasError: false,
        },
      } as any;

      const mockUpdate = vi.fn();

      render(
        <PropertiesPanel
          selectedNode={menuNode}
          flow={null}
          version={null}
          availableVariables={[]}
          onUpdateNodeData={mockUpdate}
          onDuplicateNode={vi.fn()}
          onDeleteNode={vi.fn()}
        />
      );

      expect(screen.getByText(/Opciones del Menú/i)).toBeInTheDocument();
      expect(screen.getByDisplayValue('Opción Uno')).toBeInTheDocument();

      // Add a third option
      const addInput = screen.getByPlaceholderText('Nueva opción de menú...');
      fireEvent.change(addInput, { target: { value: 'Opción Tres' } });
      fireEvent.click(screen.getByText('Añadir'));

      expect(mockUpdate).toHaveBeenCalledWith('menu_1', {
        configuration: expect.objectContaining({
          options: expect.arrayContaining([
            expect.objectContaining({ text: 'Opción Tres' }),
          ]),
        }),
      });
    });
  });

  describe('ConexaEdge Connection Management', () => {
    it('renders delete connection button and triggers onDeleteEdge on click', () => {
      const mockDeleteEdge = vi.fn();
      const mockInsertNode = vi.fn();

      render(
        <ConexaEdge
          {...({
            id: 'edge_test_1',
            source: 'node_start',
            target: 'node_msg',
            sourceX: 100,
            sourceY: 100,
            targetX: 100,
            targetY: 300,
            sourcePosition: 'bottom',
            targetPosition: 'top',
            label: 'TEST_LABEL',
            data: {
              isReadOnly: false,
              onDeleteEdge: mockDeleteEdge,
              onInsertNode: mockInsertNode,
            },
          } as any)}
        />
      );

      expect(screen.getByText('TEST_LABEL')).toBeInTheDocument();
      const deleteBtn = screen.getByTitle('Eliminar conexión');
      expect(deleteBtn).toBeInTheDocument();

      fireEvent.click(deleteBtn);
      expect(mockDeleteEdge).toHaveBeenCalledTimes(1);
      expect(mockDeleteEdge).toHaveBeenCalledWith('edge_test_1');
    });

    it('hides delete button when isReadOnly is true', () => {
      const mockDeleteEdge = vi.fn();

      render(
        <ConexaEdge
          {...({
            id: 'edge_test_ro',
            source: 'node_start',
            target: 'node_msg',
            sourceX: 100,
            sourceY: 100,
            targetX: 100,
            targetY: 300,
            sourcePosition: 'bottom',
            targetPosition: 'top',
            data: {
              isReadOnly: true,
              onDeleteEdge: mockDeleteEdge,
            },
          } as any)}
        />
      );

      expect(screen.queryByTitle('Eliminar conexión')).not.toBeInTheDocument();
    });

    it('triggers onInsertNode with coordinates on (+) button click', () => {
      const mockInsertNode = vi.fn();

      render(
        <ConexaEdge
          {...({
            id: 'edge_test_insert',
            source: 'node_a',
            target: 'node_b',
            sourceHandleId: 'true',
            sourceX: 100,
            sourceY: 100,
            targetX: 100,
            targetY: 300,
            sourcePosition: 'bottom',
            targetPosition: 'top',
            data: {
              isReadOnly: false,
              onInsertNode: mockInsertNode,
            },
          } as any)}
        />
      );

      const addBtn = screen.getByTitle('Insertar bloque entre esta conexión (+)');
      expect(addBtn).toBeInTheDocument();

      fireEvent.click(addBtn, { clientX: 250, clientY: 180 });
      expect(mockInsertNode).toHaveBeenCalledTimes(1);
      expect(mockInsertNode).toHaveBeenCalledWith(
        'edge_test_insert',
        'node_a',
        'node_b',
        'true',
        expect.objectContaining({ clientX: 250, clientY: 180 })
      );
    });
  });
});
