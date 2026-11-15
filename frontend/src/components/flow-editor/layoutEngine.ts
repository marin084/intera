import ELK, { type ElkNode, type ElkExtendedEdge, type ElkPort } from 'elkjs/lib/elk.bundled.js';
import type { Node, Edge } from '@xyflow/react';
import type { EditorNodeData } from './adapter';

export interface LayoutOptions {
  direction?: 'DOWN' | 'RIGHT';
  nodeSpacing?: number;
  layerSpacing?: number;
  marginX?: number;
  marginY?: number;
}

export const NODE_DIMENSIONS: Record<string, { width: number; height: number }> = {
  start: { width: 260, height: 105 },
  message: { width: 260, height: 145 },
  image: { width: 260, height: 210 },
  file: { width: 260, height: 175 },
  question: { width: 260, height: 170 },
  condition: { width: 260, height: 175 },
  menu: { width: 270, height: 180 },
  http_request: { width: 260, height: 155 },
  goto: { width: 240, height: 115 },
  set_variable: { width: 260, height: 135 },
  transfer_to_agent: { width: 260, height: 130 },
  end: { width: 260, height: 105 },
};

const DEFAULT_NODE_DIMENSION = { width: 260, height: 145 };

const elk = new ELK();

function getHandleOrderScore(sourceType?: string, handle?: string | null): number {
  if (!handle) return 100;
  const h = String(handle).trim().toLowerCase();
  if (sourceType === 'condition') {
    if (h === 'true') return 10;
    if (h === 'false') return 20;
  }
  if (sourceType === 'menu') {
    if (h.startsWith('opt_')) {
      const optKey = h.replace(/^opt_/, '');
      const num = parseInt(optKey, 10);
      return isNaN(num) ? 50 : 10 + num;
    }
  }
  return 100;
}

/**
 * Calculates a deterministic, professional top-to-bottom layout for nodes and edges
 * using the Eclipse Layout Kernel (ELK.js) layered Sugiyama algorithm,
 * calibrated with generous horizontal/vertical spacing, margins, and geometric handle awareness.
 */
export async function calculateAutoLayout(
  nodes: Node<EditorNodeData>[],
  edges: Edge[],
  options: LayoutOptions = {}
): Promise<Node<EditorNodeData>[]> {
  if (nodes.length === 0) return [];

  const direction = options.direction || 'DOWN';
  const nodeSpacing = options.nodeSpacing ?? 120; // 100px - 140px horizontal branch separation
  const layerSpacing = options.layerSpacing ?? 110; // 90px - 120px vertical rank separation
  const marginX = options.marginX ?? 50; // Perimeter margin >= 50px
  const marginY = options.marginY ?? 50;

  const nodeMap = new Map<string, Node<EditorNodeData>>();
  const nodeIndexMap = new Map<string, number>();
  nodes.forEach((n, idx) => {
    nodeMap.set(n.id, n);
    nodeIndexMap.set(n.id, idx);
  });

  // Build ELK graph nodes with precise dimensions and output ports for branches
  const elkChildren: ElkNode[] = nodes.map((node) => {
    const type = node.type || node.data?.type || 'message';
    const dim = NODE_DIMENSIONS[type] || DEFAULT_NODE_DIMENSION;

    // Use measured dimensions from ReactFlow DOM if available
    const measuredWidth = (node as any).measured?.width ?? node.width;
    const measuredHeight = (node as any).measured?.height ?? node.height;

    let width = measuredWidth && measuredWidth > 0 ? measuredWidth : dim.width;
    let height = measuredHeight && measuredHeight > 0 ? measuredHeight : dim.height;

    if (!measuredHeight || measuredHeight <= 0) {
      if (type === 'menu') {
        const optionsCount = (node.data?.configuration?.options || []).length;
        const isExpanded = (node.data as any)?.isExpanded !== false;
        height = isExpanded ? Math.max(160, 115 + optionsCount * 34) : 115;
        width = 270;
      }
    }

    let ports: ElkPort[] | undefined = undefined;
    let portConstraints: string | undefined = undefined;

    if (type === 'condition') {
      portConstraints = 'FIXED_POS';
      ports = [
        {
          id: `${node.id}:true`,
          x: Math.round(width * 0.25),
          y: height,
          layoutOptions: { 'port.side': 'SOUTH' },
        },
        {
          id: `${node.id}:false`,
          x: Math.round(width * 0.75),
          y: height,
          layoutOptions: { 'port.side': 'SOUTH' },
        },
      ];
    } else if (type === 'menu') {
      const options = (node.data?.configuration?.options || []) as Array<{ id?: string | number }>;
      if (options.length > 0) {
        portConstraints = 'FIXED_POS';
        ports = options.map((opt, idx) => {
          const leftPercent = (idx + 1) / (options.length + 1);
          const optId = opt.id ?? idx;
          return {
            id: `${node.id}:opt_${optId}`,
            x: Math.round(width * leftPercent),
            y: height,
            layoutOptions: { 'port.side': 'SOUTH' },
          };
        });
      }
    }

    const elkChild: ElkNode = {
      id: node.id,
      width,
      height,
    };

    if (ports && ports.length > 0) {
      elkChild.ports = ports;
      elkChild.layoutOptions = {
        'elk.portConstraints': portConstraints || 'FIXED_POS',
      };
    }

    return elkChild;
  });

  // Build a set of registered port IDs for fast validation
  const portIdSet = new Set<string>();
  for (const child of elkChildren) {
    if (child.ports) {
      for (const p of child.ports) {
        if (p.id) portIdSet.add(p.id);
      }
    }
  }

  // Pre-sort edges to ensure geometric left-to-right insertion order
  const sortedEdges = [...edges].sort((a, b) => {
    const srcIndexA = nodeIndexMap.get(a.source) ?? 9999;
    const srcIndexB = nodeIndexMap.get(b.source) ?? 9999;
    if (srcIndexA !== srcIndexB) return srcIndexA - srcIndexB;

    const srcNodeA = nodeMap.get(a.source);
    const srcTypeA = srcNodeA?.type || srcNodeA?.data?.type;
    const handleA = a.sourceHandle ?? (a.data as any)?.condition_handle;
    const handleB = b.sourceHandle ?? (b.data as any)?.condition_handle;

    const scoreA = getHandleOrderScore(srcTypeA, handleA);
    const scoreB = getHandleOrderScore(srcTypeA, handleB);
    if (scoreA !== scoreB) return scoreA - scoreB;

    const tgtIndexA = nodeIndexMap.get(a.target) ?? 9999;
    const tgtIndexB = nodeIndexMap.get(b.target) ?? 9999;
    return tgtIndexA - tgtIndexB;
  });

  // Build ELK edges with handle-awareness
  const elkEdges: ElkExtendedEdge[] = sortedEdges.map((edge, index) => {
    const sourceNode = nodeMap.get(edge.source);
    const sourceType = sourceNode?.type || sourceNode?.data?.type;
    const rawHandle =
      edge.sourceHandle ??
      (edge.data as any)?.condition_handle ??
      (edge as any).source_handle;
    const normHandle = rawHandle ? String(rawHandle).trim().toLowerCase() : '';

    let sourcePort: string | null = null;
    if (sourceType === 'condition') {
      if (normHandle === 'true') {
        sourcePort = `${edge.source}:true`;
      } else if (normHandle === 'false') {
        sourcePort = `${edge.source}:false`;
      }
    } else if (sourceType === 'menu') {
      if (rawHandle) {
        const handleStr = String(rawHandle).trim();
        const candidate1 = `${edge.source}:${handleStr}`;
        const candidate2 = `${edge.source}:opt_${handleStr.replace(/^opt_/, '')}`;
        if (portIdSet.has(candidate1)) {
          sourcePort = candidate1;
        } else if (portIdSet.has(candidate2)) {
          sourcePort = candidate2;
        }
      }
    }

    const effectiveSource = sourcePort && portIdSet.has(sourcePort) ? sourcePort : edge.source;

    return {
      id: edge.id || `elk-edge-${index}`,
      sources: [effectiveSource],
      targets: [edge.target],
    };
  });

  const elkGraph: ElkNode = {
    id: 'root',
    layoutOptions: {
      'elk.algorithm': 'layered',
      'elk.direction': direction,
      'elk.spacing.nodeNode': String(nodeSpacing),
      'elk.layered.spacing.nodeNodeBetweenLayers': String(layerSpacing),
      'elk.padding': `[top=${marginY},left=${marginX},bottom=${marginY},right=${marginX}]`,
      'elk.layered.nodePlacement.strategy': 'BRANDES_KOEPF',
      'elk.layered.cycleBreaking.strategy': 'DEPTH_FIRST',
      'elk.layered.crossingMinimization.strategy': 'LAYER_SWEEP',
      'elk.separateConnectedComponents': 'true',
      'elk.spacing.componentComponent': '100',
    },
    children: elkChildren,
    edges: elkEdges,
  };

  const layoutedGraph = await elk.layout(elkGraph);

  const positionMap = new Map<string, { x: number; y: number }>();
  if (layoutedGraph.children) {
    for (const child of layoutedGraph.children) {
      if (child.id && child.x !== undefined && child.y !== undefined) {
        // Snap to clean pixel grid
        positionMap.set(child.id, {
          x: Math.round(child.x),
          y: Math.round(child.y),
        });
      }
    }
  }

  return nodes.map((node) => {
    const newPos = positionMap.get(node.id);
    if (!newPos) return node;

    return {
      ...node,
      position: {
        x: newPos.x,
        y: newPos.y,
      },
    };
  });
}

/**
 * Checks whether a set of nodes already has valid, non-overlapping manual coordinates.
 * Returns false if nodes are at default coordinates (all 0,0 or all 100,100) or overlapping.
 */
export function hasValidPositions(nodes: Node<EditorNodeData>[]): boolean {
  if (nodes.length <= 1) return true;

  // Check if all or most nodes are at (0,0) or (100,100)
  const posCounts = new Map<string, number>();
  let identicalCount = 0;

  for (const n of nodes) {
    const key = `${Math.round(n.position.x)},${Math.round(n.position.y)}`;
    const count = (posCounts.get(key) || 0) + 1;
    posCounts.set(key, count);
    if (count > identicalCount) {
      identicalCount = count;
    }
  }

  // If more than 50% of nodes share the exact same coordinates, positions are invalid
  if (identicalCount > nodes.length * 0.5) {
    return false;
  }

  return true;
}
