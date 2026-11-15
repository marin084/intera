import type { Node, Edge } from '@xyflow/react';
import type { FlowNode, FlowEdge, FlowNodeType } from '../../types';

export interface EditorNodeData extends Record<string, unknown> {
  id?: string;
  node_key: string;
  type: FlowNodeType;
  name: string;
  configuration: Record<string, any>;
  hasError?: boolean;
  errorMessage?: string;
  isReadOnly?: boolean;
  isCollapsed?: boolean;
  collapsedCount?: number;
  isContextInput?: boolean;
  isHighlighted?: boolean;
  isSimulating?: boolean;
  isSimulatingError?: boolean;
  onToggleCollapse?: (nodeKey: string) => void;
  onFocusBranch?: (nodeKey: string) => void;
  onUpdateConfig?: (newConfig: Record<string, any>) => void;
  onUpdateName?: (newName: string) => void;
}

/**
 * Maps backend FlowNode models to React Flow Nodes.
 */
export function toReactFlowNodes(
  backendNodes: FlowNode[],
  errorNodeKeys: Set<string> = new Set(),
  isReadOnly = false
): Node<EditorNodeData>[] {
  return backendNodes.map((node) => ({
    id: node.node_key,
    type: node.type,
    position: {
      x: node.position_x ?? 100,
      y: node.position_y ?? 100,
    },
    data: {
      id: node.id,
      node_key: node.node_key,
      type: node.type,
      name: node.name,
      configuration: node.configuration || {},
      hasError: errorNodeKeys.has(node.node_key),
      isReadOnly,
    },
    deletable: node.type !== 'start' && !isReadOnly,
    draggable: !isReadOnly,
    selectable: true,
  }));
}

/**
 * Maps backend FlowEdge models to React Flow Edges.
 */
export function toReactFlowEdges(backendEdges: FlowEdge[], isReadOnly = false, backendNodes?: FlowNode[]): Edge[] {
  return backendEdges.map((edge, index) => {
    const rawHandle = edge.condition_handle ?? edge.source_handle ?? edge.sourceHandle ?? undefined;
    const normHandle = rawHandle ? String(rawHandle).trim().toLowerCase() : undefined;
    const handleId = normHandle === 'true' || normHandle === 'false' ? normHandle : rawHandle;
    const edgeId = edge.id || `edge-${edge.source_node_key}-${handleId ?? 'default'}-${edge.target_node_key}-${index}`;

    let label: string | undefined;
    let strokeColor = '#64748b';

    if (normHandle === 'true') {
      label = 'TRUE';
      strokeColor = '#16a34a';
    } else if (normHandle === 'false') {
      label = 'FALSE';
      strokeColor = '#dc2626';
    } else if (edge.condition_handle && edge.condition_handle.startsWith('opt_')) {
      label = (edge.metadata as any)?.label;
      if (!label && backendNodes) {
        const sourceNode = backendNodes.find((n) => n.node_key === edge.source_node_key);
        const optId = edge.condition_handle.replace('opt_', '');
        const opt = sourceNode?.configuration?.options?.find((o: any) => o.id === optId);
        if (opt?.text) {
          label = opt.text.length > 20 ? opt.text.substring(0, 18) + '...' : opt.text;
        }
      }
      strokeColor = '#0284c7';
    } else if (edge.condition_handle === 'success') {
      label = 'ÉXITO';
      strokeColor = '#16a34a';
    } else if (edge.condition_handle === 'fail') {
      label = 'ERROR';
      strokeColor = '#dc2626';
    }

    return {
      id: edgeId,
      type: 'conexaEdge',
      source: edge.source_node_key,
      target: edge.target_node_key,
      sourceHandle: handleId,
      targetHandle: undefined,
      label,
      deletable: !isReadOnly,
      style: {
        stroke: strokeColor,
        strokeWidth: 2,
      },
      data: {
        condition_handle: edge.condition_handle,
        metadata: edge.metadata,
        isReadOnly,
      },
    };
  });
}

/**
 * Maps React Flow Nodes & Edges back to backend-compatible FlowNode and FlowEdge arrays.
 */
export function toBackendGraph(
  nodes: Node<EditorNodeData>[],
  edges: Edge[]
): { nodes: FlowNode[]; edges: FlowEdge[] } {
  const backendNodes: FlowNode[] = nodes.map((node) => ({
    id: node.data?.id,
    node_key: node.id,
    type: (node.type || 'message') as FlowNodeType,
    name: node.data?.name || node.id,
    configuration: node.data?.configuration || {},
    position_x: Math.round(node.position.x),
    position_y: Math.round(node.position.y),
  }));

  const backendEdges: FlowEdge[] = edges.map((edge) => {
    const rawHandle = edge.sourceHandle ?? (edge.data as any)?.condition_handle ?? (edge as any).source_handle ?? null;
    const normHandle = rawHandle !== null && rawHandle !== undefined
      ? String(rawHandle).trim().toLowerCase()
      : null;
    const handle = normHandle === 'true' || normHandle === 'false' ? normHandle : (rawHandle ? String(rawHandle).trim() : null);

    return {
      source_node_key: edge.source,
      target_node_key: edge.target,
      condition_handle: handle,
      metadata: (edge.data as any)?.metadata || {},
    };
  });

  return { nodes: backendNodes, edges: backendEdges };
}
