import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { VariablePicker } from '../VariablePicker';
import type { FlowVariable } from '../../../types';

describe('VariablePicker Component', () => {
  const mockSelect = vi.fn();
  const testVars: FlowVariable[] = [
    { name: 'user_pedido', type: 'text', scope: 'conversation', description: 'Número de Pedido' },
    { name: 'user_vip', type: 'boolean', scope: 'conversation', description: 'Es Cliente VIP' },
  ];

  it('renders trigger button and toggles dropdown on click', () => {
    render(<VariablePicker variables={testVars} onSelectVariable={mockSelect} />);

    const triggerBtn = screen.getByRole('button', { name: /Insertar variable/i });
    expect(triggerBtn).toBeInTheDocument();

    // Dropdown is not visible initially
    expect(screen.queryByTestId('variable-picker-dropdown')).not.toBeInTheDocument();

    // Click to open
    fireEvent.click(triggerBtn);

    const dropdown = screen.getByTestId('variable-picker-dropdown');
    expect(dropdown).toBeInTheDocument();
    expect(dropdown).toHaveClass('variable-picker-dropdown');
    expect(dropdown.style.zIndex).toBe('60');
    expect(dropdown.style.maxHeight).toBe('240px');
    expect(dropdown.style.overflowY).toBe('auto');

    // Shows custom flow variables
    expect(screen.getByText('{{user_pedido}}')).toBeInTheDocument();
    expect(screen.getByText('{{user_vip}}')).toBeInTheDocument();

    // Shows system variables
    expect(screen.getByText('{{contact.name}}')).toBeInTheDocument();
    expect(screen.getByText('{{system.agents_online}}')).toBeInTheDocument();

    // Click a variable
    fireEvent.click(screen.getByText('{{user_pedido}}'));
    expect(mockSelect).toHaveBeenCalledWith('user_pedido');

    // Closes on selection
    expect(screen.queryByTestId('variable-picker-dropdown')).not.toBeInTheDocument();
  });
});
