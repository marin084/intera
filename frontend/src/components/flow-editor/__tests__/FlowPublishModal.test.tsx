import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { FlowPublishModal } from '../FlowPublishModal';

describe('FlowPublishModal', () => {
  it('renders form step with notes, callout and actions', () => {
    const onPublish = vi.fn();
    const onClose = vi.fn();
    const onSuccessDismiss = vi.fn();

    render(
      <FlowPublishModal
        isOpen={true}
        versionNumber={2}
        notes=""
        onChangeNotes={vi.fn()}
        onPublish={onPublish}
        onClose={onClose}
        onSuccessDismiss={onSuccessDismiss}
      />
    );

    expect(screen.getByText('Publicar versión 2')).toBeInTheDocument();
    expect(screen.getByText(/Una versión publicada es/i)).toBeInTheDocument();
    expect(screen.getByText(/Version Pinning garantizado/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Cancelar/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Publicar versión/i })).toBeInTheDocument();
  });

  it('transitions to success step on successful publish and invokes onSuccessDismiss', async () => {
    const onPublish = vi.fn().mockResolvedValue(true);
    const onClose = vi.fn();
    const onSuccessDismiss = vi.fn();

    render(
      <FlowPublishModal
        isOpen={true}
        versionNumber="3"
        notes="Nueva funcionalidad"
        onChangeNotes={vi.fn()}
        onPublish={onPublish}
        onClose={onClose}
        onSuccessDismiss={onSuccessDismiss}
      />
    );

    const publishBtn = screen.getByRole('button', { name: /Publicar versión/i });
    fireEvent.click(publishBtn);

    expect(onPublish).toHaveBeenCalledWith('Nueva funcionalidad');

    await waitFor(() => {
      expect(screen.getByText('¡Versión 3 publicada con éxito!')).toBeInTheDocument();
    });

    expect(
      screen.getByText(/Esta versión ya está activa y atendiendo a los usuarios en tus canales conectados/i)
    ).toBeInTheDocument();

    const continueBtn = screen.getByRole('button', { name: /Continuar en el editor/i });
    fireEvent.click(continueBtn);

    expect(onSuccessDismiss).toHaveBeenCalledTimes(1);
  });

  it('displays error message when publishing fails', async () => {
    const onPublish = vi.fn().mockRejectedValue(new Error('Error de validación del servidor'));

    render(
      <FlowPublishModal
        isOpen={true}
        versionNumber={1}
        notes=""
        onChangeNotes={vi.fn()}
        onPublish={onPublish}
        onClose={vi.fn()}
        onSuccessDismiss={vi.fn()}
      />
    );

    fireEvent.click(screen.getByRole('button', { name: /Publicar versión/i }));

    await waitFor(() => {
      expect(screen.getByText('Error de validación del servidor')).toBeInTheDocument();
    });
  });

  it('does not render when isOpen is false', () => {
    const { container } = render(
      <FlowPublishModal
        isOpen={false}
        versionNumber={1}
        notes=""
        onChangeNotes={vi.fn()}
        onPublish={vi.fn()}
        onClose={vi.fn()}
        onSuccessDismiss={vi.fn()}
      />
    );

    expect(container.firstChild).toBeNull();
  });
});
