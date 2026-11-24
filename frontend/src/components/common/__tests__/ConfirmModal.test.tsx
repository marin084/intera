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

  it('requires the exact text before enabling a destructive confirmation', () => {
    render(
      <ConfirmModal
        isOpen={true}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
        title="Eliminar flujo"
        description="Esta acción no se puede deshacer."
        requiredText="Flujo de ventas"
        requiredTextLabel="flujo"
        confirmTestId="confirm-btn"
        cancelTestId="cancel-btn"
      />
    );

    const confirmButton = screen.getByTestId('confirm-btn');
    const cancelButton = screen.getByTestId('cancel-btn');
    const input = screen.getByTestId('confirm-modal-confirmation-input');

    expect(input).toHaveFocus();
    expect(confirmButton).toBeDisabled();
    expect(cancelButton).toBeEnabled();

    fireEvent.change(input, { target: { value: 'Flujo incorrecto' } });
    expect(confirmButton).toBeDisabled();

    fireEvent.change(input, { target: { value: 'Flujo de ventas' } });
    expect(confirmButton).toBeEnabled();
  });

  it('announces the dialog description and restores focus to the opener', () => {
    const opener = document.createElement('button');
    opener.type = 'button';
    document.body.appendChild(opener);
    opener.focus();

    const { rerender } = render(
      <ConfirmModal
        isOpen={true}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
        title="Confirmar acción"
        description="La acción requiere confirmación."
        testId="accessibility-modal"
      />
    );

    const dialog = screen.getByTestId('accessibility-modal');
    expect(dialog).toHaveAttribute('aria-describedby', 'accessibility-modal-description');
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(screen.getByTestId('cancel-modal-btn')).toHaveFocus();

    rerender(
      <ConfirmModal
        isOpen={false}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
        title="Confirmar acción"
        description="La acción requiere confirmación."
        testId="accessibility-modal"
      />
    );

    expect(opener).toHaveFocus();
    opener.remove();
  });

  it('keeps keyboard focus inside the dialog', () => {
    render(
      <ConfirmModal
        isOpen={true}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
        title="Confirmar acción"
        description="Descripción"
        confirmTestId="confirm-btn"
        cancelTestId="cancel-btn"
      />
    );

    const confirmButton = screen.getByTestId('confirm-btn');
    const cancelButton = screen.getByTestId('cancel-btn');
    const closeButton = screen.getByRole('button', { name: 'Cerrar ventana de confirmación' });

    confirmButton.focus();
    fireEvent.keyDown(window, { key: 'Tab' });
    expect(closeButton).toHaveFocus();

    closeButton.focus();
    fireEvent.keyDown(window, { key: 'Tab', shiftKey: true });
    expect(confirmButton).toHaveFocus();

    cancelButton.focus();
  });
});
