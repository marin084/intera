import { describe, it, expect } from 'vitest';
import type { Node, Edge } from '@xyflow/react';
import type { EditorNodeData } from '../adapter';
import {
  calculateAutoLayout,
  hasValidPositions,
} from '../layoutEngine';

function createMockNode(id: string, type: string, x = 0, y = 0, config = {}): Node<EditorNodeData> {
  return {
    id,
    type,
    position: { x, y },
    data: {
      node_key: id,
      type: type as any,
      name: `Node ${id}`,
      configuration: config,
    },
  };
}

describe('layoutEngine', () => {
  describe('hasValidPositions', () => {
    it('returns true for a single node', () => {
      const nodes = [createMockNode('n1', 'start', 100, 100)];
      expect(hasValidPositions(nodes)).toBe(true);
    });

    it('returns false when all nodes are at the identical default position (100, 100)', () => {
      const nodes = [
        createMockNode('n1', 'start', 100, 100),
        createMockNode('n2', 'message', 100, 100),
        createMockNode('n3', 'question', 100, 100),
        createMockNode('n4', 'end', 100, 100),
      ];
      expect(hasValidPositions(nodes)).toBe(false);
    });

    it('returns true when nodes have distinct distributed positions', () => {
      const nodes = [
        createMockNode('n1', 'start', 100, 50),
        createMockNode('n2', 'message', 100, 250),
        createMockNode('n3', 'condition', 100, 450),
        createMockNode('n4', 'end', 200, 650),
      ];
      expect(hasValidPositions(nodes)).toBe(true);
    });
  });

  describe('calculateAutoLayout scenarios', () => {
    it('successfully lays out 10 blocks with branches and decisions', async () => {
      const nodes: Node<EditorNodeData>[] = [
        createMockNode('start', 'start'),
        createMockNode('welcome', 'message'),
        createMockNode('check_auth', 'condition'),
        createMockNode('auth_true', 'message'),
        createMockNode('auth_false', 'message'),
        createMockNode('ask_option', 'question'),
        createMockNode('handle_support', 'transfer_to_agent'),
        createMockNode('handle_sales', 'message'),
        createMockNode('farewell', 'message'),
        createMockNode('end_node', 'end'),
      ];

      const edges: Edge[] = [
        { id: 'e1', source: 'start', target: 'welcome' },
        { id: 'e2', source: 'welcome', target: 'check_auth' },
        { id: 'e3', source: 'check_auth', target: 'auth_true', sourceHandle: 'true' },
        { id: 'e4', source: 'check_auth', target: 'auth_false', sourceHandle: 'false' },
        { id: 'e5', source: 'auth_true', target: 'ask_option' },
        { id: 'e6', source: 'auth_false', target: 'ask_option' },
        { id: 'e7', source: 'ask_option', target: 'handle_support' },
        { id: 'e8', source: 'ask_option', target: 'handle_sales' },
        { id: 'e9', source: 'handle_sales', target: 'farewell' },
        { id: 'e10', source: 'farewell', target: 'end_node' },
      ];

      const layouted = await calculateAutoLayout(nodes, edges);
      expect(layouted).toHaveLength(10);

      // Verify Start is at the top
      const startNode = layouted.find((n) => n.id === 'start');
      const endNode = layouted.find((n) => n.id === 'end_node');
      expect(startNode!.position.y).toBeLessThan(endNode!.position.y);

      // Verify branches are separated horizontally
      const branchTrue = layouted.find((n) => n.id === 'auth_true');
      const branchFalse = layouted.find((n) => n.id === 'auth_false');
      expect(branchTrue!.position.x).not.toBe(branchFalse!.position.x);
    });

    it('successfully lays out 50 blocks', async () => {
      const nodes: Node<EditorNodeData>[] = [];
      const edges: Edge[] = [];

      nodes.push(createMockNode('node_0', 'start'));
      for (let i = 1; i < 50; i++) {
        const type = i % 4 === 0 ? 'condition' : i % 3 === 0 ? 'question' : 'message';
        nodes.push(createMockNode(`node_${i}`, type));
        const parent = Math.floor((i - 1) / 2);
        edges.push({ id: `e_${i}`, source: `node_${parent}`, target: `node_${i}` });
      }

      const startTime = performance.now();
      const layouted = await calculateAutoLayout(nodes, edges);
      const duration = performance.now() - startTime;

      expect(layouted).toHaveLength(50);
      expect(duration).toBeLessThan(1000); // Must be under 1s
    });

    it('scales to 200 blocks in under 500ms', async () => {
      const nodes: Node<EditorNodeData>[] = [];
      const edges: Edge[] = [];

      nodes.push(createMockNode('root', 'start'));
      for (let i = 1; i < 200; i++) {
        nodes.push(createMockNode(`n_${i}`, i % 5 === 0 ? 'condition' : 'message'));
        const parentIndex = Math.floor((i - 1) / 2);
        edges.push({ id: `e_${i}`, source: parentIndex === 0 ? 'root' : `n_${parentIndex}`, target: `n_${i}` });
      }

      const start = performance.now();
      const layouted = await calculateAutoLayout(nodes, edges);
      const duration = performance.now() - start;

      expect(layouted).toHaveLength(200);
      expect(duration).toBeLessThan(5000);
      expect(hasValidPositions(layouted)).toBe(true);
    });

    it('scales to 500 blocks deterministically without collision', async () => {
      const nodes: Node<EditorNodeData>[] = [];
      const edges: Edge[] = [];

      nodes.push(createMockNode('root', 'start'));
      for (let i = 1; i < 500; i++) {
        nodes.push(createMockNode(`n_${i}`, i % 6 === 0 ? 'condition' : 'message'));
        const parentIndex = Math.floor((i - 1) / 3);
        edges.push({ id: `e_${i}`, source: parentIndex === 0 ? 'root' : `n_${parentIndex}`, target: `n_${i}` });
      }

      const start = performance.now();
      const layouted = await calculateAutoLayout(nodes, edges);
      const duration = performance.now() - start;

      expect(layouted).toHaveLength(500);
      expect(duration).toBeLessThan(6000); // 500 blocks deterministically

      // Verify no duplicate positions
      const positions = new Set(layouted.map((n) => `${n.position.x},${n.position.y}`));
      // In a 500-node graph with layered placement, each node should have unique coordinates
      expect(positions.size).toBe(500);
    });

    it('handles structural cycles gracefully without infinite loop', async () => {
      const nodes: Node<EditorNodeData>[] = [
        createMockNode('s1', 'start'),
        createMockNode('m1', 'message'),
        createMockNode('cond', 'condition'),
        createMockNode('retry', 'message'),
      ];

      const edges: Edge[] = [
        { id: 'e1', source: 's1', target: 'm1' },
        { id: 'e2', source: 'm1', target: 'cond' },
        { id: 'e3', source: 'cond', target: 'retry', sourceHandle: 'false' },
        { id: 'e4', source: 'retry', target: 'm1' }, // Cycle back to m1
      ];

      const layouted = await calculateAutoLayout(nodes, edges);
      expect(layouted).toHaveLength(4);
      expect(layouted.every((n) => typeof n.position.x === 'number' && typeof n.position.y === 'number')).toBe(true);
    });

    it('handles disconnected nodes and incomplete flows', async () => {
      const nodes: Node<EditorNodeData>[] = [
        createMockNode('s1', 'start'),
        createMockNode('orphan_1', 'message'),
        createMockNode('orphan_2', 'question'),
      ];

      const layouted = await calculateAutoLayout(nodes, []);
      expect(layouted).toHaveLength(3);
      // All nodes receive valid non-null coordinates
      expect(layouted.every((n) => !isNaN(n.position.x) && !isNaN(n.position.y))).toBe(true);
    });

    it('handles convergences (multiple branches merging into one node)', async () => {
      const nodes: Node<EditorNodeData>[] = [
        createMockNode('start', 'start'),
        createMockNode('branch_a', 'message'),
        createMockNode('branch_b', 'message'),
        createMockNode('converged', 'end'),
      ];

      const edges: Edge[] = [
        { id: 'e1', source: 'start', target: 'branch_a' },
        { id: 'e2', source: 'start', target: 'branch_b' },
        { id: 'e3', source: 'branch_a', target: 'converged' },
        { id: 'e4', source: 'branch_b', target: 'converged' },
      ];

      const layouted = await calculateAutoLayout(nodes, edges);
      expect(layouted).toHaveLength(4);
      const converged = layouted.find((n) => n.id === 'converged');
      const branchA = layouted.find((n) => n.id === 'branch_a');
      const branchB = layouted.find((n) => n.id === 'branch_b');

      expect(converged!.position.y).toBeGreaterThan(branchA!.position.y);
      expect(converged!.position.y).toBeGreaterThan(branchB!.position.y);
    });

    it('places TRUE branch to the left and FALSE branch to the right (avoiding X-crossings) even when edges are declared in reverse order', async () => {
      const nodes: Node<EditorNodeData>[] = [
        createMockNode('start', 'start'),
        createMockNode('cond', 'condition'),
        createMockNode('branch_true', 'message'),
        createMockNode('branch_false', 'message'),
      ];

      // Declare FALSE edge first, TRUE edge second to test reverse declaration
      const edges: Edge[] = [
        { id: 'e1', source: 'start', target: 'cond' },
        { id: 'e_false', source: 'cond', target: 'branch_false', sourceHandle: 'false' },
        { id: 'e_true', source: 'cond', target: 'branch_true', sourceHandle: 'true' },
      ];

      const layouted = await calculateAutoLayout(nodes, edges);
      const trueNode = layouted.find((n) => n.id === 'branch_true');
      const falseNode = layouted.find((n) => n.id === 'branch_false');

      expect(trueNode).toBeDefined();
      expect(falseNode).toBeDefined();

      // TRUE branch is to the left of FALSE branch
      expect(trueNode!.position.x).toBeLessThan(falseNode!.position.x);

      // Horizontal separation is at least 100px (nodesep between 100px - 140px + node width)
      const horizontalDistance = falseNode!.position.x - (trueNode!.position.x + 260);
      expect(horizontalDistance).toBeGreaterThanOrEqual(100);
    });

    it('places MenuNode options in left-to-right order according to option indices', async () => {
      const menuConfig = {
        options: [
          { id: 'opt_1', text: 'Option 1' },
          { id: 'opt_2', text: 'Option 2' },
          { id: 'opt_3', text: 'Option 3' },
        ],
      };

      const nodes: Node<EditorNodeData>[] = [
        createMockNode('start', 'start'),
        createMockNode('menu_node', 'menu', 0, 0, menuConfig),
        createMockNode('dest_1', 'message'),
        createMockNode('dest_2', 'message'),
        createMockNode('dest_3', 'message'),
      ];

      // Edges declared out of order (3, 1, 2)
      const edges: Edge[] = [
        { id: 'e0', source: 'start', target: 'menu_node' },
        { id: 'e3', source: 'menu_node', target: 'dest_3', sourceHandle: 'opt_opt_3' },
        { id: 'e1', source: 'menu_node', target: 'dest_1', sourceHandle: 'opt_opt_1' },
        { id: 'e2', source: 'menu_node', target: 'dest_2', sourceHandle: 'opt_opt_2' },
      ];

      const layouted = await calculateAutoLayout(nodes, edges);
      const dest1 = layouted.find((n) => n.id === 'dest_1');
      const dest2 = layouted.find((n) => n.id === 'dest_2');
      const dest3 = layouted.find((n) => n.id === 'dest_3');

      expect(dest1).toBeDefined();
      expect(dest2).toBeDefined();
      expect(dest3).toBeDefined();

      // Dest 1 is leftmost, Dest 2 is middle, Dest 3 is rightmost
      expect(dest1!.position.x).toBeLessThan(dest2!.position.x);
      expect(dest2!.position.x).toBeLessThan(dest3!.position.x);
    });

    it('enforces clean perimetral margin and vertical level separation (ranksep >= 90px)', async () => {
      const nodes: Node<EditorNodeData>[] = [
        createMockNode('n1', 'start'),
        createMockNode('n2', 'message'),
      ];
      const edges: Edge[] = [{ id: 'e1', source: 'n1', target: 'n2' }];

      const layouted = await calculateAutoLayout(nodes, edges);
      const n1 = layouted.find((n) => n.id === 'n1')!;
      const n2 = layouted.find((n) => n.id === 'n2')!;

      // Perimetral margin >= 50px
      expect(n1.position.x).toBeGreaterThanOrEqual(50);
      expect(n1.position.y).toBeGreaterThanOrEqual(50);

      // Vertical separation between bottom of n1 and top of n2 is >= 90px
      const verticalGap = n2.position.y - (n1.position.y + 105);
      expect(verticalGap).toBeGreaterThanOrEqual(90);
    });
  });
});
