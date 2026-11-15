import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ReactFlowProvider } from '@xyflow/react';
import { FileNode } from '../nodes/FileNode';
import type { EditorNodeData } from '../adapter';

describe('FileNode Component', () => {
  const baseData: EditorNodeData = {
    node_key: 'node_file_1',
    type: 'file',
    name: 'Catálogo de Productos',
    configuration: {
      url: 'https://cdn.conexa.test/catalogo_2026.pdf',
      caption: 'Descarga aquí nuestro catálogo actualizado en PDF',
      file_name: 'catalogo_2026.pdf',
      mime_type: 'application/pdf',
      size_bytes: 2500000,
    },
    isReadOnly: false,
    hasError: false,
  };

  it('renders file node with title, card, filename, badge, and caption', () => {
    const props: any = {
      id: 'node_file_1',
      data: baseData,
      selected: false,
      type: 'file',
    };

    render(
      <ReactFlowProvider>
        <FileNode {...props} />
      </ReactFlowProvider>
    );

    expect(screen.getByText('Catálogo de Productos')).toBeInTheDocument();
    expect(screen.getByText('ARCHIVO')).toBeInTheDocument();
    expect(screen.getByText('catalogo_2026.pdf')).toBeInTheDocument();
    expect(screen.getByText('PDF')).toBeInTheDocument();
    expect(screen.getByText('2.4 MB')).toBeInTheDocument();
    expect(screen.getByText('Descarga aquí nuestro catálogo actualizado en PDF')).toBeInTheDocument();
  });

  it('renders placeholder when file URL is not configured', () => {
    const dataWithoutUrl: EditorNodeData = {
      ...baseData,
      configuration: {
        url: '',
        caption: '',
      },
    };

    const props: any = {
      id: 'node_file_1',
      data: dataWithoutUrl,
      selected: false,
      type: 'file',
    };

    render(
      <ReactFlowProvider>
        <FileNode {...props} />
      </ReactFlowProvider>
    );

    expect(screen.getByText('Sin documento configurado...')).toBeInTheDocument();
  });

  it('allows inline title editing on edit button click', () => {
    const mockUpdateName = vi.fn();
    const dataWithHandler: EditorNodeData = {
      ...baseData,
      onUpdateName: mockUpdateName,
    };

    const props: any = {
      id: 'node_file_1',
      data: dataWithHandler,
      selected: false,
      type: 'file',
    };

    render(
      <ReactFlowProvider>
        <FileNode {...props} />
      </ReactFlowProvider>
    );

    const editBtn = screen.getByTitle('Editar título');
    fireEvent.click(editBtn);

    const input = screen.getByDisplayValue('Catálogo de Productos');
    expect(input).toBeInTheDocument();

    fireEvent.change(input, { target: { value: 'Nuevo Manual PDF' } });
    fireEvent.keyDown(input, { key: 'Enter', code: 'Enter' });

    expect(mockUpdateName).toHaveBeenCalledWith('Nuevo Manual PDF');
  });

  it('renders error notice when hasError is true', () => {
    const dataWithError: EditorNodeData = {
      ...baseData,
      hasError: true,
    };

    const props: any = {
      id: 'node_file_1',
      data: dataWithError,
      selected: false,
      type: 'file',
    };

    render(
      <ReactFlowProvider>
        <FileNode {...props} />
      </ReactFlowProvider>
    );

    expect(screen.getByText(/Revisar URL o conexiones/i)).toBeInTheDocument();
  });
});
