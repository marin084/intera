import type { Node, Edge } from '@xyflow/react';
import type { EditorNodeData } from './adapter';

export type ViewMode = 'full' | 'overview' | 'branch';

export interface BranchVisibilityOptions {
  viewMode: ViewMode;
  collapsedNodeKeys: Set<string>;
  focusedNodeKey?: string | null;
}

export interface VisibleGraphResult {
  visibleNodes: Node<EditorNodeData>[];
  visibleEdges: Edge[];
  collapsedCounts: Map<string, number>;
  focusedBranchTitle?: string;
}

/**
 * Computes all exclusive descendants of a start node in a directed graph.
 * A node is an "exclusive descendant" of startNodeKey if ALL its upstream paths
 * from any root node pass through startNodeKey.
 * This ensures converging nodes or shared branches from other parts of the flow are not hidden.
 */
export function getExclusiveDescendantNodeKeys(
  startNodeKey: string,
  edges: Edge[],
  nodes: Node<EditorNodeData>[]
): Set<string> {
  const nodeKeySet = new Set(nodes.map((n) => n.id));
  if (!nodeKeySet.has(startNodeKey)) return new Set();

  // 1. Build adjacency maps
  const outgoingMap = new Map<string, string[]>();
  const incomingMap = new Map<string, string[]>();

  for (const edge of edges) {
    if (!outgoingMap.has(edge.source)) outgoingMap.set(edge.source, []);
    outgoingMap.get(edge.source)!.push(edge.target);

    if (!incomingMap.has(edge.target)) incomingMap.set(edge.target, []);
    incomingMap.get(edge.target)!.push(edge.source);
  }

  // 2. Find all reachable descendants from startNodeKey (BFS, guarding against cycles)
  const reachable = new Set<string>();
  const queue = [...(outgoingMap.get(startNodeKey) || [])];

  while (queue.length > 0) {
    const current = queue.shift()!;
    if (current === startNodeKey || reachable.has(current)) continue;
    reachable.add(current);
    const nexts = outgoingMap.get(current) || [];
    for (const nxt of nexts) {
      if (!reachable.has(nxt) && nxt !== startNodeKey) {
        queue.push(nxt);
      }
    }
  }

  // 3. Filter reachable nodes: keep only those whose incoming edges all originate from
  // either startNodeKey or another node in `reachable` (i.e. no outside upstream paths)
  const exclusiveDescendants = new Set<string>();

  // Iteratively prune nodes that have outside inputs
  let candidates = new Set(reachable);
  let changed = true;

  while (changed) {
    changed = false;
    for (const cand of Array.from(candidates)) {
      const parents = incomingMap.get(cand) || [];
      // If candidate has any parent not in (startNodeKey + candidates), it is not exclusive
      const hasOutsideParent = parents.some(
        (p) => p !== startNodeKey && !candidates.has(p)
      );

      if (hasOutsideParent) {
        candidates.delete(cand);
        changed = true;
      }
    }
  }

  for (const id of candidates) {
    exclusiveDescendants.add(id);
  }

  return exclusiveDescendants;
}

/**
 * Computes the visible nodes and edges for rendering according to view mode,
 * collapsed branches, and branch focus.
 * The underlying graph is NEVER mutated.
 */
export function computeVisibleGraph(
  nodes: Node<EditorNodeData>[],
  edges: Edge[],
  options: BranchVisibilityOptions
): VisibleGraphResult {
  const { viewMode, collapsedNodeKeys, focusedNodeKey } = options;
  const collapsedCounts = new Map<string, number>();

  // 1. Full view with custom collapsed branches
  if (viewMode === 'full') {
    const hiddenNodeKeys = new Set<string>();

    for (const collapsedKey of collapsedNodeKeys) {
      const descendants = getExclusiveDescendantNodeKeys(collapsedKey, edges, nodes);
      collapsedCounts.set(collapsedKey, descendants.size);
      for (const d of descendants) {
        hiddenNodeKeys.add(d);
      }
    }

    const visibleNodes = nodes
      .filter((n) => !hiddenNodeKeys.has(n.id))
      .map((n) => {
        const count = collapsedCounts.get(n.id) || 0;
        const isCollapsed = collapsedNodeKeys.has(n.id);
        return {
          ...n,
          data: {
            ...n.data,
            isCollapsed,
            collapsedCount: count,
          },
        };
      });

    const visibleNodeKeySet = new Set(visibleNodes.map((n) => n.id));
    const visibleEdges = edges.filter(
      (e) => visibleNodeKeySet.has(e.source) && visibleNodeKeySet.has(e.target)
    );

    return { visibleNodes, visibleEdges, collapsedCounts };
  }

  // 2. Branch focus view
  if (viewMode === 'branch' && focusedNodeKey) {
    const focusedNode = nodes.find((n) => n.id === focusedNodeKey);
    const focusedBranchTitle = focusedNode?.data?.name || focusedNodeKey;

    // Direct predecessors (incoming context)
    const incomingEdgeSources = edges
      .filter((e) => e.target === focusedNodeKey)
      .map((e) => e.source);

    // All descendants reachable from focusedNodeKey
    const descendants = getExclusiveDescendantNodeKeys(focusedNodeKey, edges, nodes);

    const allowedNodeKeys = new Set<string>([
      ...incomingEdgeSources,
      focusedNodeKey,
      ...descendants,
    ]);

    const visibleNodes = nodes
      .filter((n) => allowedNodeKeys.has(n.id))
      .map((n) => {
        const isInput = incomingEdgeSources.includes(n.id) && n.id !== focusedNodeKey;
        return {
          ...n,
          data: {
            ...n.data,
            isContextInput: isInput,
          },
        };
      });

    const visibleNodeKeySet = new Set(visibleNodes.map((n) => n.id));
    const visibleEdges = edges.filter(
      (e) => visibleNodeKeySet.has(e.source) && visibleNodeKeySet.has(e.target)
    );

    return {
      visibleNodes,
      visibleEdges,
      collapsedCounts,
      focusedBranchTitle,
    };
  }

  // 3. Overview mode (High-level summary):
  // Automatically identify branching nodes (condition nodes, or nodes with outDegree > 1)
  // and collapse their branches to present an executive high-level view
  const autoCollapsed = new Set<string>();
  for (const n of nodes) {
    if (n.type === 'condition') {
      autoCollapsed.add(n.id);
    }
  }

  const hiddenNodeKeys = new Set<string>();
  for (const key of autoCollapsed) {
    const desc = getExclusiveDescendantNodeKeys(key, edges, nodes);
    collapsedCounts.set(key, desc.size);
    for (const d of desc) {
      hiddenNodeKeys.add(d);
    }
  }

  const visibleNodes = nodes
    .filter((n) => !hiddenNodeKeys.has(n.id))
    .map((n) => {
      const count = collapsedCounts.get(n.id) || 0;
      return {
        ...n,
        data: {
          ...n.data,
          isCollapsed: autoCollapsed.has(n.id),
          collapsedCount: count,
        },
      };
    });

  const visibleNodeKeySet = new Set(visibleNodes.map((n) => n.id));
  const visibleEdges = edges.filter(
    (e) => visibleNodeKeySet.has(e.source) && visibleNodeKeySet.has(e.target)
  );

  return { visibleNodes, visibleEdges, collapsedCounts };
}

/**
 * Searches nodes by name, key, type, or configuration text content.
 */
export function searchNodes(
  nodes: Node<EditorNodeData>[],
  query: string
): Node<EditorNodeData>[] {
  const q = query.trim().toLowerCase();
  if (!q) return [];

  return nodes.filter((n) => {
    // Check name
    if (n.data?.name && n.data.name.toLowerCase().includes(q)) return true;
    // Check node_key
    if (n.id.toLowerCase().includes(q)) return true;
    // Check type
    if (n.type && n.type.toLowerCase().includes(q)) return true;

    // Check configuration properties
    const config = n.data?.configuration || {};
    if (config.text && String(config.text).toLowerCase().includes(q)) return true;
    if (config.message && String(config.message).toLowerCase().includes(q)) return true;
    if (config.variable && String(config.variable).toLowerCase().includes(q)) return true;
    if (config.value && String(config.value).toLowerCase().includes(q)) return true;

    return false;
  });
}
