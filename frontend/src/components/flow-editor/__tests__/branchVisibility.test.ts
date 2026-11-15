import { describe, it, expect } from 'vitest';
import type { Node, Edge } from '@xyflow/react';
import type { EditorNodeData } from '../adapter';
import {
  getExclusiveDescendantNodeKeys,
  computeVisibleGraph,
  searchNodes,
} from '../branchVisibility';

function mockNode(id: string, type: string, name: string, config: any = {}): Node<EditorNodeData> {
  return {
    id,
    type,
    position: { x: 0, y: 0 },
    data: {
      node_key: id,
      type: type as any,
      name,
      configuration: config,
    },
  };
}

describe('branchVisibility', () => {
  const nodes: Node<EditorNodeData>[] = [
    mockNode('start', 'start', 'Inicio'),
    mockNode('menu', 'question', 'Menú Principal', { text: '¿En qué te podemos ayudar?' }),
    mockNode('sales_1', 'message', 'Ventas Intro', { text: 'Bienvenido a ventas' }),
    mockNode('sales_2', 'question', 'Ventas Pregunta', { variable: 'producto' }),
    mockNode('sales_end', 'end', 'Fin Ventas'),
    mockNode('support_1', 'message', 'Soporte Intro', { text: 'Atención técnica' }),
    mockNode('support_transfer', 'transfer_to_agent', 'Transferir Soporte'),
    mockNode('shared_faq', 'message', 'FAQ Común', { text: 'Preguntas frecuentes' }),
  ];

  const edges: Edge[] = [
    { id: 'e1', source: 'start', target: 'menu' },
    { id: 'e2', source: 'menu', target: 'sales_1' },
    { id: 'e3', source: 'sales_1', target: 'sales_2' },
    { id: 'e4', source: 'sales_2', target: 'sales_end' },
    { id: 'e5', source: 'sales_2', target: 'shared_faq' }, // Convergence
    { id: 'e6', source: 'menu', target: 'support_1' },
    { id: 'e7', source: 'support_1', target: 'support_transfer' },
    { id: 'e8', source: 'support_1', target: 'shared_faq' }, // Convergence
  ];

  describe('getExclusiveDescendantNodeKeys', () => {
    it('identifies exclusive descendants of sales_1 without collapsing shared_faq', () => {
      const descendants = getExclusiveDescendantNodeKeys('sales_1', edges, nodes);

      // sales_2 and sales_end are exclusive to sales_1
      expect(descendants.has('sales_2')).toBe(true);
      expect(descendants.has('sales_end')).toBe(true);

      // shared_faq is reached from support_1 as well, so it must NOT be collapsed
      expect(descendants.has('shared_faq')).toBe(false);
      // support nodes must not be included
      expect(descendants.has('support_1')).toBe(false);
    });

    it('identifies exclusive descendants of support_1', () => {
      const descendants = getExclusiveDescendantNodeKeys('support_1', edges, nodes);
      expect(descendants.has('support_transfer')).toBe(true);
      expect(descendants.has('shared_faq')).toBe(false); // Because sales_2 also links to it
    });
  });

  describe('computeVisibleGraph', () => {
    it('in full mode hides collapsed exclusive descendants and provides collapsedCount badge', () => {
      const collapsedSet = new Set(['sales_1']);
      const result = computeVisibleGraph(nodes, edges, {
        viewMode: 'full',
        collapsedNodeKeys: collapsedSet,
      });

      const visibleIds = result.visibleNodes.map((n) => n.id);
      expect(visibleIds).toContain('start');
      expect(visibleIds).toContain('menu');
      expect(visibleIds).toContain('sales_1');
      expect(visibleIds).not.toContain('sales_2');
      expect(visibleIds).not.toContain('sales_end');
      // shared_faq remains visible because it converges from support
      expect(visibleIds).toContain('shared_faq');

      const salesNode = result.visibleNodes.find((n) => n.id === 'sales_1');
      expect(salesNode?.data?.isCollapsed).toBe(true);
      expect(salesNode?.data?.collapsedCount).toBe(2);
    });

    it('in branch mode isolates focused branch with upstream input context', () => {
      const result = computeVisibleGraph(nodes, edges, {
        viewMode: 'branch',
        collapsedNodeKeys: new Set(),
        focusedNodeKey: 'support_1',
      });

      const visibleIds = result.visibleNodes.map((n) => n.id);
      expect(visibleIds).toContain('support_1');
      expect(visibleIds).toContain('support_transfer');
      // Upstream input predecessor 'menu' should be included for context
      expect(visibleIds).toContain('menu');
      // Other unrelated branches must be hidden
      expect(visibleIds).not.toContain('sales_1');

      const menuNode = result.visibleNodes.find((n) => n.id === 'menu');
      expect(menuNode?.data?.isContextInput).toBe(true);
      expect(result.focusedBranchTitle).toBe('Soporte Intro');
    });

    it('in overview mode collapses branching structures', () => {
      // In overview mode, condition or branching nodes are summarized
      const result = computeVisibleGraph(nodes, edges, {
        viewMode: 'overview',
        collapsedNodeKeys: new Set(),
      });

      expect(result.visibleNodes.length).toBeGreaterThan(0);
      expect(result.visibleNodes.length).toBeLessThanOrEqual(nodes.length);
    });
  });

  describe('searchNodes', () => {
    it('finds node by name', () => {
      const results = searchNodes(nodes, 'Ventas Intro');
      expect(results).toHaveLength(1);
      expect(results[0].id).toBe('sales_1');
    });

    it('finds node by configuration text content', () => {
      const results = searchNodes(nodes, 'ayudar');
      expect(results).toHaveLength(1);
      expect(results[0].id).toBe('menu');
    });

    it('finds node by variable name', () => {
      const results = searchNodes(nodes, 'producto');
      expect(results).toHaveLength(1);
      expect(results[0].id).toBe('sales_2');
    });

    it('finds nodes by type', () => {
      const results = searchNodes(nodes, 'transfer_to_agent');
      expect(results).toHaveLength(1);
      expect(results[0].id).toBe('support_transfer');
    });

    it('returns empty array when query is empty or not found', () => {
      expect(searchNodes(nodes, '')).toEqual([]);
      expect(searchNodes(nodes, 'nonexistent_key_xyz')).toEqual([]);
    });
  });
});
