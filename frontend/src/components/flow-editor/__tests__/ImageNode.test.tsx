import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ReactFlowProvider } from '@xyflow/react';
import { ImageNode } from '../nodes/ImageNode';
import type { EditorNodeData } from '../adapter';

describe('ImageNode Component', () => {
  const baseData: EditorNodeData = {
    node_key: 'node_img_1',
    type: 'image',
    name: 'Infografía Pago',
    configuration: {
      url: 'https://cdn.conexa.test/instrucciones.png',
      caption: 'Pasos para pagar con tarjeta de crédito',
      file_name: 'instrucciones.png',
    },
    isReadOnly: false,
    hasError: false,
  };

  it('renders image node with title, thumbnail, and caption', () => {
    const props: any = {
      id: 'node_img_1',
      data: baseData,
      selected: false,
      type: 'image',
    };

    render(
      <ReactFlowProvider>
        <ImageNode {...props} />
      </ReactFlowProvider>
    );

    expect(screen.getByText('Infografía Pago')).toBeInTheDocument();
    expect(screen.getByText('IMAGEN')).toBeInTheDocument();
    expect(screen.getByText('Pasos para pagar con tarjeta de crédito')).toBeInTheDocument();

    const img = screen.getByRole('img');
    expect(img).toBeInTheDocument();
    expect(img).toHaveAttribute('src', 'https://cdn.conexa.test/instrucciones.png');
  });

  it('renders placeholder when image URL is not configured', () => {
    const dataWithoutUrl: EditorNodeData = {
      ...baseData,
      configuration: {
        url: '',
        caption: '',
      },
    };

    const props: any = {
      id: 'node_img_1',
      data: dataWithoutUrl,
      selected: false,
      type: 'image',
    };

    render(
      <ReactFlowProvider>
        <ImageNode {...props} />
      </ReactFlowProvider>
    );

    expect(screen.getByText('Sin imagen configurada...')).toBeInTheDocument();
  });

  it('allows inline title editing on double click or edit button', () => {
    const mockUpdateName = vi.fn();
    const dataWithHandler: EditorNodeData = {
      ...baseData,
      onUpdateName: mockUpdateName,
    };

    const props: any = {
      id: 'node_img_1',
      data: dataWithHandler,
      selected: false,
      type: 'image',
    };

    render(
      <ReactFlowProvider>
        <ImageNode {...props} />
      </ReactFlowProvider>
    );

    const editBtn = screen.getByTitle('Editar título');
    fireEvent.click(editBtn);

    const input = screen.getByDisplayValue('Infografía Pago');
    expect(input).toBeInTheDocument();

    fireEvent.change(input, { target: { value: 'Nuevo Título' } });
    fireEvent.keyDown(input, { key: 'Enter', code: 'Enter' });

    expect(mockUpdateName).toHaveBeenCalledWith('Nuevo Título');
  });
});
