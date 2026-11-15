import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ReactFlowProvider } from '@xyflow/react';
import { ConditionNode } from '../nodes/ConditionNode';
import type { EditorNodeData } from '../adapter';

describe('ConditionNode Component', () => {
  const baseData: EditorNodeData = {
    node_key: 'node_cond_1',
    type: 'condition',
    name: 'Evaluar Mayoría de Edad',
    configuration: {
      variable: 'edad',
      operator: 'gte',
      value: 18,
    },
    isReadOnly: false,
    hasError: true, // Initially flagged from server or default
  };

  it('renders warning badge and has-error class when branches are missing', () => {
    const props: any = {
      id: 'node_cond_1',
      data: {
        ...baseData,
        edges: [],
      },
      selected: false,
      type: 'condition',
    };

    const { container } = render(
      <ReactFlowProvider>
        <ConditionNode {...props} />
      </ReactFlowProvider>
    );

    expect(screen.getByText('Evaluar Mayoría de Edad')).toBeInTheDocument();
    expect(screen.getByText('CONDICIÓN')).toBeInTheDocument();
    expect(screen.getByText(/Requiere rama TRUE y rama FALSE/i)).toBeInTheDocument();

    const nodeElement = container.querySelector('.custom-node-condition');
    expect(nodeElement).toHaveClass('has-error');
  });

  it('still shows error if only TRUE branch is connected', () => {
    const props: any = {
      id: 'node_cond_1',
      data: {
        ...baseData,
        edges: [
          {
            id: 'e1',
            source: 'node_cond_1',
            target: 'node_target_1',
            sourceHandle: 'true',
          },
        ],
      },
      selected: false,
      type: 'condition',
    };

    const { container } = render(
      <ReactFlowProvider>
        <ConditionNode {...props} />
      </ReactFlowProvider>
    );

    expect(screen.getByText(/Requiere rama TRUE y rama FALSE/i)).toBeInTheDocument();
    const nodeElement = container.querySelector('.custom-node-condition');
    expect(nodeElement).toHaveClass('has-error');
  });

  it('hides warning badge and removes has-error class when both TRUE and FALSE branches are connected', () => {
    const props: any = {
      id: 'node_cond_1',
      data: {
        ...baseData,
        edges: [
          {
            id: 'e1',
            source: 'node_cond_1',
            target: 'node_ok',
            sourceHandle: 'true',
          },
          {
            id: 'e2',
            source: 'node_cond_1',
            target: 'node_fail',
            sourceHandle: 'false',
          },
        ],
      },
      selected: false,
      type: 'condition',
    };

    const { container } = render(
      <ReactFlowProvider>
        <ConditionNode {...props} />
      </ReactFlowProvider>
    );

    expect(screen.queryByText(/Requiere rama TRUE y rama FALSE/i)).not.toBeInTheDocument();

    const nodeElement = container.querySelector('.custom-node-condition');
    expect(nodeElement).not.toHaveClass('has-error');
  });

  it('accepts uppercase TRUE and FALSE handles and data.condition_handle', () => {
    const props: any = {
      id: 'node_cond_1',
      data: {
        ...baseData,
        edges: [
          {
            id: 'e1',
            source: 'node_cond_1',
            target: 'node_ok',
            sourceHandle: 'TRUE',
            data: { condition_handle: 'TRUE' },
          },
          {
            id: 'e2',
            source: 'node_cond_1',
            target: 'node_fail',
            sourceHandle: 'FALSE',
            data: { condition_handle: 'FALSE' },
          },
        ],
      },
      selected: false,
      type: 'condition',
    };

    const { container } = render(
      <ReactFlowProvider>
        <ConditionNode {...props} />
      </ReactFlowProvider>
    );

    expect(screen.queryByText(/Requiere rama TRUE y rama FALSE/i)).not.toBeInTheDocument();

    const nodeElement = container.querySelector('.custom-node-condition');
    expect(nodeElement).not.toHaveClass('has-error');
  });
});
