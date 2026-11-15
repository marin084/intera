import { describe, it, expect } from 'vitest';
import { toReactFlowNodes, toReactFlowEdges, toBackendGraph } from '../adapter';
import type { FlowNode, FlowEdge } from '../../../types';

describe('Flow Editor Adapter', () => {
  it('converts backend FlowNodes to React Flow nodes with positions and protection', () => {
    const backendNodes: FlowNode[] = [
      {
        id: 'node-1',
        node_key: 'start_1',
        type: 'start',
        name: 'Inicio',
        configuration: {},
        position_x: 150.4,
        position_y: 200.8,
      },
      {
        id: 'node-2',
        node_key: 'msg_1',
        type: 'message',
        name: 'Bienvenida',
        configuration: { text: 'Hola {{nombre}}' },
        position_x: 150,
        position_y: 350,
      },
    ];

    const errorKeys = new Set(['msg_1']);
    const rfNodes = toReactFlowNodes(backendNodes, errorKeys, false);

    expect(rfNodes).toHaveLength(2);

    // Start node assertions
    expect(rfNodes[0].id).toBe('start_1');
    expect(rfNodes[0].type).toBe('start');
    expect(rfNodes[0].position).toEqual({ x: 150.4, y: 200.8 });
    expect(rfNodes[0].deletable).toBe(false); // Start is protected
    expect(rfNodes[0].data.hasError).toBe(false);

    // Message node assertions
    expect(rfNodes[1].id).toBe('msg_1');
    expect(rfNodes[1].type).toBe('message');
    expect(rfNodes[1].deletable).toBe(true);
    expect(rfNodes[1].data.hasError).toBe(true);
    expect(rfNodes[1].data.configuration.text).toBe('Hola {{nombre}}');
  });

  it('marks all nodes non-deletable and non-draggable when isReadOnly is true', () => {
    const backendNodes: FlowNode[] = [
      {
        id: 'node-1',
        node_key: 'msg_1',
        type: 'message',
        name: 'Mensaje',
        configuration: { text: 'Test' },
        position_x: 100,
        position_y: 100,
      },
    ];

    const rfNodes = toReactFlowNodes(backendNodes, new Set(), true);
    expect(rfNodes[0].deletable).toBe(false);
    expect(rfNodes[0].draggable).toBe(false);
    expect(rfNodes[0].data.isReadOnly).toBe(true);
  });

  it('converts backend FlowEdges to React Flow edges with TRUE and FALSE styling', () => {
    const backendEdges: FlowEdge[] = [
      {
        source_node_key: 'start_1',
        target_node_key: 'cond_1',
      },
      {
        source_node_key: 'cond_1',
        target_node_key: 'msg_true',
        condition_handle: 'true',
      },
      {
        source_node_key: 'cond_1',
        target_node_key: 'msg_false',
        condition_handle: 'false',
      },
    ];

    const rfEdges = toReactFlowEdges(backendEdges, false);

    expect(rfEdges).toHaveLength(3);

    // Default edge
    expect(rfEdges[0].source).toBe('start_1');
    expect(rfEdges[0].target).toBe('cond_1');
    expect(rfEdges[0].label).toBeUndefined();
    expect(rfEdges[0].style?.stroke).toBe('#64748b');

    // TRUE condition edge
    expect(rfEdges[1].source).toBe('cond_1');
    expect(rfEdges[1].target).toBe('msg_true');
    expect(rfEdges[1].sourceHandle).toBe('true');
    expect(rfEdges[1].label).toBe('TRUE');
    expect(rfEdges[1].style?.stroke).toBe('#16a34a');

    // FALSE condition edge
    expect(rfEdges[2].source).toBe('cond_1');
    expect(rfEdges[2].target).toBe('msg_false');
    expect(rfEdges[2].sourceHandle).toBe('false');
    expect(rfEdges[2].label).toBe('FALSE');
    expect(rfEdges[2].style?.stroke).toBe('#dc2626');
  });

  it('converts React Flow nodes & edges back to backend graph with rounded positions', () => {
    const rfNodes = [
      {
        id: 'start_1',
        type: 'start' as const,
        position: { x: 120.7, y: 230.2 },
        data: {
          node_key: 'start_1',
          type: 'start' as const,
          name: 'Inicio',
          configuration: {},
        },
      },
      {
        id: 'cond_1',
        type: 'condition' as const,
        position: { x: 300.1, y: 400.9 },
        data: {
          node_key: 'cond_1',
          type: 'condition' as const,
          name: 'Evaluar VIP',
          configuration: { variable: 'es_vip', operator: 'equals', value: 'si' },
        },
      },
    ];

    const rfEdges = [
      {
        id: 'e1',
        source: 'start_1',
        target: 'cond_1',
      },
      {
        id: 'e2',
        source: 'cond_1',
        target: 'msg_vip',
        sourceHandle: 'true',
        data: { condition_handle: 'true' },
      },
    ];

    const backendGraph = toBackendGraph(rfNodes, rfEdges);

    expect(backendGraph.nodes).toEqual([
      {
        id: undefined,
        node_key: 'start_1',
        type: 'start',
        name: 'Inicio',
        configuration: {},
        position_x: 121,
        position_y: 230,
      },
      {
        id: undefined,
        node_key: 'cond_1',
        type: 'condition',
        name: 'Evaluar VIP',
        configuration: { variable: 'es_vip', operator: 'equals', value: 'si' },
        position_x: 300,
        position_y: 401,
      },
    ]);

    expect(backendGraph.edges).toEqual([
      {
        source_node_key: 'start_1',
        target_node_key: 'cond_1',
        condition_handle: null,
        metadata: {},
      },
      {
        source_node_key: 'cond_1',
        target_node_key: 'msg_vip',
        condition_handle: 'true',
        metadata: {},
      },
    ]);
  });

  it('resolves menu option labels from backendNodes configuration when condition_handle starts with opt_', () => {
    const backendNodes: FlowNode[] = [
      {
        id: 'node-menu',
        node_key: 'menu_1',
        type: 'menu',
        name: 'Menú Principal',
        configuration: {
          text: 'Seleccione opción:',
          options: [
            { id: 'opt_ventas', text: 'Ventas y Planes' },
            { id: 'opt_soporte', text: 'Soporte Técnico Especializado' },
          ],
        },
        position_x: 0,
        position_y: 0,
      },
      {
        id: 'node-ventas',
        node_key: 'msg_ventas',
        type: 'message',
        name: 'Ventas',
        configuration: { text: 'Bienvenido a ventas' },
        position_x: 0,
        position_y: 100,
      },
    ];

    const backendEdges: FlowEdge[] = [
      {
        source_node_key: 'menu_1',
        target_node_key: 'msg_ventas',
        condition_handle: 'opt_opt_ventas',
      },
    ];

    const rfEdges = toReactFlowEdges(backendEdges, false, backendNodes);

    expect(rfEdges).toHaveLength(1);
    expect(rfEdges[0].source).toBe('menu_1');
    expect(rfEdges[0].target).toBe('msg_ventas');
    expect(rfEdges[0].label).toBe('Ventas y Planes');
    expect(rfEdges[0].style?.stroke).toBe('#0284c7');
  });
});
