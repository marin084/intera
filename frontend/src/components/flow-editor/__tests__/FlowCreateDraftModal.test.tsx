import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { FlowCreateDraftModal } from '../FlowCreateDraftModal';

describe('FlowCreateDraftModal', () => {
  it('renders confirmation details, version highlight, and actions', () => {
    const onConfirm = vi.fn();
    const onClose = vi.fn();

    render(
      <FlowCreateDraftModal
        isOpen={true}
        currentVersionNumber={5}
        isCreating={false}
        onConfirm={onConfirm}
        onClose={onClose}
      />
    );

    expect(screen.getByText('Crear nueva versión borrador')).toBeInTheDocument();
    expect(screen.getByText(/Vas a generar una nueva versión borrador basada en la/i)).toBeInTheDocument();
    expect(screen.getByText('Versión 5')).toBeInTheDocument();
    expect(
      screen.getByText(/Tus canales continuarán atendiendo a los usuarios con la versión publicada actual/i)
    ).toBeInTheDocument();

    const cancelBtn = screen.getByRole('button', { name: /Cancelar/i });
    fireEvent.click(cancelBtn);
    expect(onClose).toHaveBeenCalledTimes(1);

    const confirmBtn = screen.getByRole('button', { name: /Crear borrador/i });
    fireEvent.click(confirmBtn);
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('shows loading state when isCreating is true', () => {
    render(
      <FlowCreateDraftModal
        isOpen={true}
        currentVersionNumber={2}
        isCreating={true}
        onConfirm={vi.fn()}
        onClose={vi.fn()}
      />
    );

    expect(screen.getByText('Creando borrador...')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Cancelar/i })).toBeDisabled();
  });

  it('does not render when isOpen is false', () => {
    const { container } = render(
      <FlowCreateDraftModal
        isOpen={false}
        currentVersionNumber={1}
        isCreating={false}
        onConfirm={vi.fn()}
        onClose={vi.fn()}
      />
    );

    expect(container.firstChild).toBeNull();
  });
});
