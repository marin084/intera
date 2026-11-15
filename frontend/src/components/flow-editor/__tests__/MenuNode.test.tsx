import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ReactFlowProvider } from '@xyflow/react';
import { MenuNode } from '../nodes/MenuNode';
import type { EditorNodeData } from '../adapter';

describe('MenuNode Component', () => {
  const baseData: EditorNodeData = {
    node_key: 'node_menu_1',
    type: 'menu',
    name: 'Menú Principal',
    configuration: {
      text: '¿Cómo podemos ayudarte hoy?',
      options: [
        { id: '1', text: 'Ventas y Planes', is_hidden: false },
        { id: '2', text: 'Hablar con un asesor', hide_if_no_agents: true },
        { id: '9', text: 'Opción Administrativa', is_hidden: true },
      ],
    },
    isReadOnly: false,
    hasError: false,
  };

  it('renders menu options including badges for hidden and agent-only options', () => {
    const props: any = {
      id: 'node_menu_1',
      data: baseData,
      selected: false,
      type: 'menu',
    };

    render(
      <ReactFlowProvider>
        <MenuNode {...props} />
      </ReactFlowProvider>
    );

    expect(screen.getByText('Menú Principal')).toBeInTheDocument();
    expect(screen.getByText('¿Cómo podemos ayudarte hoy?')).toBeInTheDocument();
    expect(screen.getByText('Ventas y Planes')).toBeInTheDocument();
    expect(screen.getByText('Hablar con un asesor')).toBeInTheDocument();
    expect(screen.getByText('Opción Administrativa')).toBeInTheDocument();

    // Verify badges
    const hiddenBadge = screen.getByTestId('badge-hidden-option');
    expect(hiddenBadge).toBeInTheDocument();
    expect(hiddenBadge).toHaveTextContent('Oculta');

    const agentBadge = screen.getByTestId('badge-agent-option');
    expect(agentBadge).toBeInTheDocument();
    expect(agentBadge).toHaveTextContent('Agente');
  });
});
