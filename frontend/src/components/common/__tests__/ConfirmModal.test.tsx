import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { ConfirmModal } from '../ConfirmModal';
import { RotateCcw } from 'lucide-react';

describe('ConfirmModal Component', () => {
  it('does not render when isOpen is false', () => {
    const { container } = render(
      <ConfirmModal
        isOpen={false}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
        title="Título de prueba"
        description="Descripción de prueba"
      />
    );
    expect(container.firstChild).toBeNull();
  });

  it('renders title, description and custom buttons when open', () => {
    render(
      <ConfirmModal
        isOpen={true}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
        title="¿Reiniciar flujo del bot?"
        description="Esta acción cancelará cualquier paso activo."
        confirmText="Sí, reiniciar flujo"
        cancelText="Cancelar"
        icon={<RotateCcw data-testid="modal-icon" size={24} />}
        confirmTestId="confirm-btn"
        cancelTestId="cancel-btn"
      />
    );

    expect(screen.getByText('¿Reiniciar flujo del bot?')).toBeInTheDocument();
    expect(screen.getByText('Esta acción cancelará cualquier paso activo.')).toBeInTheDocument();
    expect(screen.getByTestId('confirm-btn')).toHaveTextContent('Sí, reiniciar flujo');
    expect(screen.getByTestId('cancel-btn')).toHaveTextContent('Cancelar');
    expect(screen.getByTestId('modal-icon')).toBeInTheDocument();
  });

  it('triggers onConfirm when confirm button is clicked', () => {
    const onConfirm = vi.fn();
    render(
      <ConfirmModal
        isOpen={true}
        onClose={vi.fn()}
        onConfirm={onConfirm}
        title="Confirmar acción"
        description="¿Estás seguro?"
        confirmTestId="confirm-btn"
      />
    );

    fireEvent.click(screen.getByTestId('confirm-btn'));
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('triggers onClose when cancel button is clicked', () => {
    const onClose = vi.fn();
    render(
      <ConfirmModal
        isOpen={true}
        onClose={onClose}
        onConfirm={vi.fn()}
        title="Confirmar acción"
        description="¿Estás seguro?"
        cancelTestId="cancel-btn"
      />
    );

    fireEvent.click(screen.getByTestId('cancel-btn'));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('closes on Escape key press', () => {
    const onClose = vi.fn();
    render(
      <ConfirmModal
        isOpen={true}
        onClose={onClose}
        onConfirm={vi.fn()}
        title="Confirmar acción"
        description="¿Estás seguro?"
      />
    );

    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('closes when clicking the backdrop', () => {
    const onClose = vi.fn();
    render(
      <ConfirmModal
        isOpen={true}
        onClose={onClose}
        onConfirm={vi.fn()}
        title="Confirmar acción"
        description="¿Estás seguro?"
        testId="test-backdrop"
      />
    );

    const backdrop = screen.getByTestId('test-backdrop');
    fireEvent.click(backdrop);
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('supports confirmVariant="danger"', () => {
    render(
      <ConfirmModal
        isOpen={true}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
        title="Eliminar recurso"
        description="No se puede deshacer"
        confirmVariant="danger"
        confirmTestId="danger-btn"
      />
    );

    const btn = screen.getByTestId('danger-btn');
    expect(btn.className).toContain('bg-rose-600');
  });
});
