import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { PropertiesPanel } from '../PropertiesPanel';
import type { Node } from '@xyflow/react';
import type { EditorNodeData } from '../adapter';
import type { Flow } from '../../../types';

describe('PropertiesPanel', () => {
  const mockUpdateNodeData = vi.fn();
  const mockDuplicateNode = vi.fn();
  const mockDeleteNode = vi.fn();

  const baseProps = {
    flow: {
      id: 'f-1',
      name: 'Test Flow',
      status: 'draft' as const,
      description: '',
      channel_applicability: ['webchat'],
      active_version_id: null,
      created_at: '',
      updated_at: '',
    } as Flow,
    version: {
      id: 'v-1',
      flow_id: 'f-1',
      version_number: 1,
      status: 'draft' as const,
      description: null,
      published_at: null,
      updated_at: '2026-10-04T12:00:00Z',
    },
    availableVariables: ['nombre', 'email', 'cliente'],
    isReadOnly: false,
    onUpdateNodeData: mockUpdateNodeData,
    onDuplicateNode: mockDuplicateNode,
    onDeleteNode: mockDeleteNode,
  };

  it('renders flow overview when no node is selected', () => {
    render(<PropertiesPanel {...baseProps} selectedNode={null} />);
    expect(screen.getByText('Detalles del Flujo')).toBeInTheDocument();
    expect(screen.getByText('Test Flow')).toBeInTheDocument();
    expect(screen.getByText(/Borrador/i)).toBeInTheDocument();
    expect(screen.getByText('{{nombre}}')).toBeInTheDocument();
    expect(screen.getByText('{{cliente}}')).toBeInTheDocument();
  });

  it('renders Message node inspector and updates text', () => {
    const messageNode: Node<EditorNodeData> = {
      id: 'msg_1',
      type: 'message',
      position: { x: 100, y: 100 },
      data: {
        node_key: 'msg_1',
        type: 'message',
        name: 'Mensaje de Bienvenida',
        configuration: { text: 'Hola {{nombre}}' },
      },
    };

    render(<PropertiesPanel {...baseProps} selectedNode={messageNode} />);

    expect(screen.getByText('MESSAGE')).toBeInTheDocument();
    const textarea = screen.getByPlaceholderText(/Escribe el texto que enviará el bot/i);
    expect(textarea).toHaveValue('Hola {{nombre}}');

    fireEvent.change(textarea, { target: { value: 'Nuevo texto del mensaje' } });
    expect(mockUpdateNodeData).toHaveBeenCalledWith('msg_1', {
      configuration: { text: 'Nuevo texto del mensaje' },
    });
  });

  it('renders Question node inspector and modifies variable and choices', () => {
    const questionNode: Node<EditorNodeData> = {
      id: 'q_cliente',
      type: 'question',
      position: { x: 200, y: 200 },
      data: {
        node_key: 'q_cliente',
        type: 'question',
        name: 'Preguntar si es cliente',
        configuration: {
          text: '¿Eres cliente actualmente?',
          variable: 'cliente',
          input_type: 'choice',
          choices: ['si', 'no'],
          validation_message: 'Por favor responde si o no',
        },
      },
    };

    render(<PropertiesPanel {...baseProps} selectedNode={questionNode} />);

    expect(screen.getByText('QUESTION')).toBeInTheDocument();
    const varInput = screen.getByPlaceholderText(/ej. nombre_cliente/i);
    expect(varInput).toHaveValue('cliente');

    // Change variable name
    fireEvent.change(varInput, { target: { value: 'es_cliente_vip' } });
    expect(mockUpdateNodeData).toHaveBeenCalledWith('q_cliente', {
      configuration: expect.objectContaining({ variable: 'es_cliente_vip' }),
    });

    // Verify existing choices inputs rendered
    const choiceInputs = screen.getAllByDisplayValue(/^(si|no)$/);
    expect(choiceInputs).toHaveLength(2);

    // Add new choice
    const addInput = screen.getByPlaceholderText(/Nueva opción/i);
    fireEvent.change(addInput, { target: { value: 'tal_vez' } });
    const addButton = screen.getByRole('button', { name: /añadir/i });
    fireEvent.click(addButton);

    expect(mockUpdateNodeData).toHaveBeenCalledWith('q_cliente', {
      configuration: expect.objectContaining({
        choices: ['si', 'no', 'tal_vez'],
      }),
    });
  });

  it('renders Country input type and contact field options in Question node', () => {
    const questionNode: Node<EditorNodeData> = {
      id: 'q_pais',
      type: 'question',
      position: { x: 200, y: 200 },
      data: {
        node_key: 'q_pais',
        type: 'question',
        name: 'Pregunta de País',
        configuration: {
          text: '¿De qué país nos visitas?',
          variable: 'pais_origen',
          input_type: 'text',
          contact_field: 'none',
        },
      },
    };

    render(<PropertiesPanel {...baseProps} selectedNode={questionNode} />);

    // Verify option "País (Country)" is present in Tipo de Respuesta Esperada
    const countryInputTypeOption = screen.getByRole('option', { name: /País \(Country\)/i });
    expect(countryInputTypeOption).toBeInTheDocument();

    // Open collapsible section "Almacenamiento y Avanzado"
    const advancedToggle = screen.getByText('Almacenamiento y Avanzado');
    fireEvent.click(advancedToggle);

    // Verify option "País" is present in Guardar respuesta también en contacto
    const countryContactOption = screen.getByRole('option', { name: 'País' });
    expect(countryContactOption).toBeInTheDocument();

    // Change input_type to country
    const inputTypeSelect = screen.getByDisplayValue(/Texto Libre/i);
    fireEvent.change(inputTypeSelect, { target: { value: 'country' } });
    expect(mockUpdateNodeData).toHaveBeenCalledWith('q_pais', {
      configuration: expect.objectContaining({
        input_type: 'country',
      }),
    });

    // Change contact_field to country
    const contactFieldSelect = screen.getByDisplayValue('No guardar');
    fireEvent.change(contactFieldSelect, { target: { value: 'country' } });
    expect(mockUpdateNodeData).toHaveBeenCalledWith('q_pais', {
      configuration: expect.objectContaining({
        contact_field: 'country',
        sync_contact_field: 'country',
      }),
    });
  });

  it('renders Condition node inspector and allows setting variable, operator and value', () => {
    const conditionNode: Node<EditorNodeData> = {
      id: 'cond_1',
      type: 'condition',
      position: { x: 300, y: 300 },
      data: {
        node_key: 'cond_1',
        type: 'condition',
        name: 'Evaluar cliente',
        configuration: {
          variable: 'cliente',
          operator: 'equals',
          value: 'si',
        },
      },
    };

    render(<PropertiesPanel {...baseProps} selectedNode={conditionNode} />);

    expect(screen.getByText('CONDITION')).toBeInTheDocument();
    expect(screen.getByText('Conexiones requeridas:')).toBeInTheDocument();
    expect(screen.getByText('TRUE')).toBeInTheDocument();
    expect(screen.getByText('FALSE')).toBeInTheDocument();

    // Change operator
    const selects = screen.getAllByRole('combobox');
    const operatorSelect = selects.find((s) => (s as HTMLSelectElement).value === 'equals');
    expect(operatorSelect).toBeDefined();

    if (operatorSelect) {
      fireEvent.change(operatorSelect, { target: { value: 'contains' } });
      expect(mockUpdateNodeData).toHaveBeenCalledWith('cond_1', {
        configuration: expect.objectContaining({ operator: 'contains' }),
      });
    }

    // Change value
    const valueInput = screen.getByPlaceholderText('Ej. si');
    fireEvent.change(valueInput, { target: { value: 'vip' } });
    expect(mockUpdateNodeData).toHaveBeenCalledWith('cond_1', {
      configuration: expect.objectContaining({ value: 'vip' }),
    });
  });

  it('protects Start node: cannot be duplicated or deleted', () => {
    const startNode: Node<EditorNodeData> = {
      id: 'start_1',
      type: 'start',
      position: { x: 50, y: 50 },
      data: {
        node_key: 'start_1',
        type: 'start',
        name: 'Inicio del Flujo',
        configuration: {},
      },
    };

    render(<PropertiesPanel {...baseProps} selectedNode={startNode} />);

    expect(screen.getByText('START')).toBeInTheDocument();
    expect(screen.getByText(/Este es el punto de inicio de la conversación/i)).toBeInTheDocument();

    // Duplicate and delete buttons should not be present for Start node
    expect(screen.queryByRole('button', { name: /duplicar/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /eliminar/i })).not.toBeInTheDocument();
  });

  it('triggers delete and duplicate callbacks on standard nodes', () => {
    const messageNode: Node<EditorNodeData> = {
      id: 'msg_test',
      type: 'message',
      position: { x: 100, y: 100 },
      data: {
        node_key: 'msg_test',
        type: 'message',
        name: 'Test Message',
        configuration: { text: 'Test' },
      },
    };

    render(<PropertiesPanel {...baseProps} selectedNode={messageNode} />);

    const duplicateBtn = screen.getByRole('button', { name: /duplicar/i });
    fireEvent.click(duplicateBtn);
    expect(mockDuplicateNode).toHaveBeenCalledWith('msg_test');

    const deleteBtn = screen.getByRole('button', { name: /eliminar/i });
    fireEvent.click(deleteBtn);
    expect(mockDeleteNode).toHaveBeenCalledWith('msg_test');
  });

  it('renders collapse toggle button and triggers onToggleCollapse callback', () => {
    const mockToggleCollapse = vi.fn();
    const { rerender } = render(
      <PropertiesPanel
        {...baseProps}
        selectedNode={null}
        isCollapsed={false}
        onToggleCollapse={mockToggleCollapse}
      />
    );

    const toggleBtn = screen.getByTestId('properties-toggle-btn');
    expect(toggleBtn).toBeInTheDocument();
    expect(toggleBtn).toHaveAttribute('title', 'Ocultar panel');

    fireEvent.click(toggleBtn);
    expect(mockToggleCollapse).toHaveBeenCalledTimes(1);

    const panel = screen.getByTestId('properties-panel');
    expect(panel).not.toHaveClass('collapsed');

    // Rerender as collapsed
    rerender(
      <PropertiesPanel
        {...baseProps}
        selectedNode={null}
        isCollapsed={true}
        onToggleCollapse={mockToggleCollapse}
      />
    );

    expect(screen.getByTestId('properties-panel')).toHaveClass('collapsed');
    expect(screen.getByTestId('properties-toggle-btn')).toHaveAttribute('title', 'Mostrar panel');
  });

  it('renders close button on selected node and triggers onDeselectNode', () => {
    const mockDeselectNode = vi.fn();
    const node: Node<EditorNodeData> = {
      id: 'node_1',
      type: 'message',
      position: { x: 0, y: 0 },
      data: {
        node_key: 'node_1',
        type: 'message',
        name: 'Nodo 1',
        configuration: { text: 'Hola' },
      },
    };

    render(
      <PropertiesPanel
        {...baseProps}
        selectedNode={node}
        onDeselectNode={mockDeselectNode}
      />
    );

    const closeBtn = screen.getByTestId('properties-close-btn');
    expect(closeBtn).toBeInTheDocument();
    fireEvent.click(closeBtn);
    expect(mockDeselectNode).toHaveBeenCalledTimes(1);
  });

  it('renders Menu node options with visibility toggle and hidden badges', () => {
    const menuNode: Node<EditorNodeData> = {
      id: 'menu_test',
      type: 'menu',
      position: { x: 100, y: 100 },
      data: {
        node_key: 'menu_test',
        type: 'menu',
        name: 'Menú Comercial',
        configuration: {
          text: 'Selecciona:',
          options: [
            { id: '1', text: 'Ventas', is_hidden: false },
            { id: '2', text: 'Soporte', is_hidden: false },
            { id: '9', text: 'Varios (Oculto)', is_hidden: true },
          ],
        },
      },
    };

    render(<PropertiesPanel {...baseProps} selectedNode={menuNode} />);

    expect(screen.getByText('MENU')).toBeInTheDocument();
    expect(screen.getByText(/Opciones del Menú \(3\)/i)).toBeInTheDocument();

    // Check visible options inputs
    expect(screen.getByDisplayValue('Ventas')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Soporte')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Varios (Oculto)')).toBeInTheDocument();

    // Check hidden badge is rendered on option 9 (index 2)
    const hiddenBadge = screen.getByTestId('badge-hidden-2');
    expect(hiddenBadge).toBeInTheDocument();
    expect(hiddenBadge).toHaveTextContent('Oculta');

    // Check visibility toggle buttons and tooltips
    const toggleBtn0 = screen.getByTestId('toggle-visibility-0');
    expect(toggleBtn0).toHaveAttribute('title', 'Ocultar en chat (mantiene conexión en lienzo)');

    const toggleBtn2 = screen.getByTestId('toggle-visibility-2');
    expect(toggleBtn2).toHaveAttribute('title', 'Mostrar en chat');

    // Click toggle to hide Ventas (index 0)
    fireEvent.click(toggleBtn0);
    expect(mockUpdateNodeData).toHaveBeenCalledWith('menu_test', {
      configuration: expect.objectContaining({
        options: [
          { id: '1', text: 'Ventas', is_hidden: true },
          { id: '2', text: 'Soporte', is_hidden: false },
          { id: '9', text: 'Varios (Oculto)', is_hidden: true },
        ],
      }),
    });

    // Click toggle to unhide Varios (index 2)
    fireEvent.click(toggleBtn2);
    expect(mockUpdateNodeData).toHaveBeenCalledWith('menu_test', {
      configuration: expect.objectContaining({
        options: [
          { id: '1', text: 'Ventas', is_hidden: false },
          { id: '2', text: 'Soporte', is_hidden: false },
          { id: '9', text: 'Varios (Oculto)', is_hidden: false },
        ],
      }),
    });
  });

  it('renders Menu node options with agent availability toggle (hide_if_no_agents)', () => {
    const menuNode: Node<EditorNodeData> = {
      id: 'menu_agent_test',
      type: 'menu',
      position: { x: 100, y: 100 },
      data: {
        node_key: 'menu_agent_test',
        type: 'menu',
        name: 'Menú con Asesores',
        configuration: {
          text: 'Selecciona atención:',
          options: [
            { id: '1', text: 'Autoatención', hide_if_no_agents: false },
            { id: '2', text: 'Hablar con un agente humano', hide_if_no_agents: true },
          ],
        },
      },
    };

    render(<PropertiesPanel {...baseProps} selectedNode={menuNode} />);

    // Agent badge shown on option 2 (index 1)
    const agentBadge = screen.getByTestId('badge-agent-1');
    expect(agentBadge).toBeInTheDocument();
    expect(agentBadge).toHaveTextContent('Solo con agente');

    // Agent toggle buttons
    const agentToggleBtn0 = screen.getByTestId('option-agent-toggle-0');
    expect(agentToggleBtn0).toHaveAttribute('title', 'Ocultar si no hay agentes conectados');

    const agentToggleBtn1 = screen.getByTestId('option-agent-toggle-1');
    expect(agentToggleBtn1).toHaveAttribute('title', 'Visible solo si hay agentes en línea (activo)');

    // Toggle option 0 to be agent-only
    fireEvent.click(agentToggleBtn0);
    expect(mockUpdateNodeData).toHaveBeenCalledWith('menu_agent_test', {
      configuration: expect.objectContaining({
        options: [
          { id: '1', text: 'Autoatención', hide_if_no_agents: true },
          { id: '2', text: 'Hablar con un agente humano', hide_if_no_agents: true },
        ],
      }),
    });
  });

  it('renders Image node inspector and updates URL and caption', () => {
    const imageNode: Node<EditorNodeData> = {
      id: 'img_1',
      type: 'image',
      position: { x: 100, y: 100 },
      data: {
        node_key: 'img_1',
        type: 'image',
        name: 'Imagen Informativa',
        configuration: {
          url: '',
          caption: 'Instructivo',
        },
      },
    };

    render(<PropertiesPanel {...baseProps} selectedNode={imageNode} />);

    expect(screen.getByTestId('image-properties-section')).toBeInTheDocument();
    expect(screen.getByTestId('image-dropzone')).toBeInTheDocument();

    const urlInput = screen.getByTestId('image-url-input');
    fireEvent.change(urlInput, { target: { value: 'https://cdn.conexa.test/foto.png' } });
    expect(mockUpdateNodeData).toHaveBeenCalledWith('img_1', {
      configuration: expect.objectContaining({
        url: 'https://cdn.conexa.test/foto.png',
      }),
    });

    const captionInput = screen.getByTestId('image-caption-input');
    fireEvent.change(captionInput, { target: { value: 'Nueva descripción' } });
    expect(mockUpdateNodeData).toHaveBeenCalledWith('img_1', {
      configuration: expect.objectContaining({
        caption: 'Nueva descripción',
      }),
    });
  });

  it('inserts dynamic variables into image caption', () => {
    const imageNode: Node<EditorNodeData> = {
      id: 'img_vars',
      type: 'image',
      position: { x: 100, y: 100 },
      data: {
        node_key: 'img_vars',
        type: 'image',
        name: 'Imagen con Variable',
        configuration: {
          caption: 'Hola ',
        },
      },
    };

    render(<PropertiesPanel {...baseProps} selectedNode={imageNode} />);

    const chipNombre = screen.getByTitle('Insertar {{nombre}}');
    fireEvent.click(chipNombre);

    expect(mockUpdateNodeData).toHaveBeenCalledWith('img_vars', {
      configuration: expect.objectContaining({
        caption: 'Hola {{nombre}}',
      }),
    });
  });

  it('handles file upload and updates node data with attachment metadata', async () => {
    const imageNode: Node<EditorNodeData> = {
      id: 'img_upload',
      type: 'image',
      position: { x: 100, y: 100 },
      data: {
        node_key: 'img_upload',
        type: 'image',
        name: 'Subir Comprobante',
        configuration: {},
      },
    };

    const mockUpload = vi.fn().mockResolvedValue({
      url: 'https://storage.conexa.test/flows/f-1/uuid.png',
      file_name: 'recibo.png',
      mime_type: 'image/png',
      size_bytes: 10240,
    });

    render(
      <PropertiesPanel
        {...baseProps}
        selectedNode={imageNode}
        onUploadAttachment={mockUpload}
      />
    );

    const fileInput = screen.getByTestId('image-file-input') as HTMLInputElement;
    const file = new File(['dummy content'], 'recibo.png', { type: 'image/png' });

    fireEvent.change(fileInput, { target: { files: [file] } });

    expect(mockUpload).toHaveBeenCalledWith(file);

    // Wait for promise resolution
    await vi.waitFor(() => {
      expect(mockUpdateNodeData).toHaveBeenCalledWith('img_upload', {
        configuration: expect.objectContaining({
          url: 'https://storage.conexa.test/flows/f-1/uuid.png',
          file_name: 'recibo.png',
          mime_type: 'image/png',
        }),
      });
    });
  });

  it('displays preview when image URL exists and allows removing image', () => {
    const imageNode: Node<EditorNodeData> = {
      id: 'img_preview',
      type: 'image',
      position: { x: 100, y: 100 },
      data: {
        node_key: 'img_preview',
        type: 'image',
        name: 'Imagen con Preview',
        configuration: {
          url: 'https://cdn.conexa.test/infografia.png',
          file_name: 'infografia.png',
        },
      },
    };

    render(<PropertiesPanel {...baseProps} selectedNode={imageNode} />);

    expect(screen.getByTestId('properties-image-preview')).toBeInTheDocument();
    expect(screen.getByText('infografia.png')).toBeInTheDocument();

    const removeBtn = screen.getByTestId('remove-image-btn');
    fireEvent.click(removeBtn);

    expect(mockUpdateNodeData).toHaveBeenCalledWith('img_preview', {
      configuration: expect.objectContaining({
        url: '',
        file_name: '',
        mime_type: '',
      }),
    });
  });

  describe('File Node Properties', () => {
    it('renders file properties section with dropzone, url, file_name and caption inputs', () => {
      const fileNode: Node<EditorNodeData> = {
        id: 'file_node_1',
        type: 'file',
        position: { x: 100, y: 100 },
        data: {
          node_key: 'file_node_1',
          type: 'file',
          name: 'Enviar Documento',
          configuration: {
            url: '',
            file_name: '',
            caption: '',
          },
        },
      };

      render(<PropertiesPanel {...baseProps} selectedNode={fileNode} />);

      expect(screen.getByTestId('file-properties-section')).toBeInTheDocument();
      expect(screen.getByTestId('file-dropzone')).toBeInTheDocument();
      expect(screen.getByTestId('file-url-input')).toBeInTheDocument();
      expect(screen.getByTestId('file-name-input')).toBeInTheDocument();
      expect(screen.getByTestId('file-caption-input')).toBeInTheDocument();
    });

    it('handles document upload and updates node configuration', async () => {
      const mockUpload = vi.fn().mockResolvedValue({
        url: 'https://storage.conexa.test/flows/f-1/catalogo.pdf',
        file_name: 'catalogo.pdf',
        mime_type: 'application/pdf',
        size_bytes: 1048576,
      });

      const fileNode: Node<EditorNodeData> = {
        id: 'file_upload_1',
        type: 'file',
        position: { x: 100, y: 100 },
        data: {
          node_key: 'file_upload_1',
          type: 'file',
          name: 'Documento PDF',
          configuration: {},
        },
      };

      render(
        <PropertiesPanel
          {...baseProps}
          selectedNode={fileNode}
          onUploadAttachment={mockUpload}
        />
      );

      const fileInput = screen.getByTestId('file-file-input') as HTMLInputElement;
      const file = new File(['%PDF-1.4 dummy'], 'catalogo.pdf', { type: 'application/pdf' });

      fireEvent.change(fileInput, { target: { files: [file] } });

      expect(mockUpload).toHaveBeenCalledWith(file);

      await vi.waitFor(() => {
        expect(mockUpdateNodeData).toHaveBeenCalledWith('file_upload_1', {
          configuration: expect.objectContaining({
            url: 'https://storage.conexa.test/flows/f-1/catalogo.pdf',
            file_name: 'catalogo.pdf',
            mime_type: 'application/pdf',
            size_bytes: 1048576,
          }),
        });
      });
    });

    it('rejects files larger than 10MB client-side before calling upload API', () => {
      const mockUpload = vi.fn();
      const fileNode: Node<EditorNodeData> = {
        id: 'file_large_1',
        type: 'file',
        position: { x: 100, y: 100 },
        data: {
          node_key: 'file_large_1',
          type: 'file',
          name: 'Documento Pesado',
          configuration: {},
        },
      };

      render(
        <PropertiesPanel
          {...baseProps}
          selectedNode={fileNode}
          onUploadAttachment={mockUpload}
        />
      );

      const fileInput = screen.getByTestId('file-file-input') as HTMLInputElement;
      const bigFile = new File(['x'.repeat(100)], 'decretos_gigante.pdf', { type: 'application/pdf' });
      Object.defineProperty(bigFile, 'size', { value: 11 * 1024 * 1024 });

      fireEvent.change(fileInput, { target: { files: [bigFile] } });

      expect(mockUpload).not.toHaveBeenCalled();
      expect(screen.getByTestId('upload-error')).toHaveTextContent('El archivo supera el tamaño máximo permitido (10 MB).');
    });

    it('formats 413 error from server gracefully', async () => {
      const mockUpload = vi.fn().mockRejectedValue({
        response: { status: 413, data: 'Payload Too Large' },
      });

      const fileNode: Node<EditorNodeData> = {
        id: 'file_413_1',
        type: 'file',
        position: { x: 100, y: 100 },
        data: {
          node_key: 'file_413_1',
          type: 'file',
          name: 'Documento 413',
          configuration: {},
        },
      };

      render(
        <PropertiesPanel
          {...baseProps}
          selectedNode={fileNode}
          onUploadAttachment={mockUpload}
        />
      );

      const fileInput = screen.getByTestId('file-file-input') as HTMLInputElement;
      const file = new File(['%PDF dummy'], 'decretos.pdf', { type: 'application/pdf' });
      Object.defineProperty(file, 'size', { value: 5.1 * 1024 * 1024 });

      fireEvent.change(fileInput, { target: { files: [file] } });

      await vi.waitFor(() => {
        expect(screen.getByTestId('upload-error')).toHaveTextContent(
          'El archivo es demasiado pesado para el servidor. Tamaño máximo: 10 MB.'
        );
      });
    });

    it('displays preview when document URL exists and allows removing document', () => {
      const fileNode: Node<EditorNodeData> = {
        id: 'file_preview_1',
        type: 'file',
        position: { x: 100, y: 100 },
        data: {
          node_key: 'file_preview_1',
          type: 'file',
          name: 'Documento con Preview',
          configuration: {
            url: 'https://cdn.conexa.test/manual.docx',
            file_name: 'manual_usuario.docx',
            size_bytes: 524288,
          },
        },
      };

      render(<PropertiesPanel {...baseProps} selectedNode={fileNode} />);

      expect(screen.getByTestId('properties-file-preview')).toBeInTheDocument();
      expect(screen.getByText('manual_usuario.docx')).toBeInTheDocument();

      const removeBtn = screen.getByTestId('remove-file-btn');
      fireEvent.click(removeBtn);

      expect(mockUpdateNodeData).toHaveBeenCalledWith('file_preview_1', {
        configuration: expect.objectContaining({
          url: '',
          file_name: '',
          mime_type: '',
          size_bytes: null,
        }),
      });
    });

    it('updates url, file_name, and caption when typed manually', () => {
      const fileNode: Node<EditorNodeData> = {
        id: 'file_input_1',
        type: 'file',
        position: { x: 100, y: 100 },
        data: {
          node_key: 'file_input_1',
          type: 'file',
          name: 'Documento Manual',
          configuration: {
            url: '',
            file_name: '',
            caption: '',
          },
        },
      };

      render(<PropertiesPanel {...baseProps} selectedNode={fileNode} />);

      const urlInput = screen.getByTestId('file-url-input');
      fireEvent.change(urlInput, { target: { value: 'https://s3.amazonaws.com/files/guia.pdf' } });
      expect(mockUpdateNodeData).toHaveBeenCalledWith('file_input_1', {
        configuration: expect.objectContaining({ url: 'https://s3.amazonaws.com/files/guia.pdf' }),
      });

      const nameInput = screen.getByTestId('file-name-input');
      fireEvent.change(nameInput, { target: { value: 'Guia_Oficial.pdf' } });
      expect(mockUpdateNodeData).toHaveBeenCalledWith('file_input_1', {
        configuration: expect.objectContaining({ file_name: 'Guia_Oficial.pdf' }),
      });

      const captionInput = screen.getByTestId('file-caption-input');
      fireEvent.change(captionInput, { target: { value: 'Aquí está tu guía {{nombre}}' } });
      expect(mockUpdateNodeData).toHaveBeenCalledWith('file_input_1', {
        configuration: expect.objectContaining({ caption: 'Aquí está tu guía {{nombre}}' }),
      });
    });
  });

  describe('Boolean Variables & Conditions UX ergonomics', () => {
    it('renders dropdown TRUE/FALSE for set_variable when data_type is boolean', () => {
      const setVarNode: Node<EditorNodeData> = {
        id: 'var_1',
        type: 'set_variable',
        position: { x: 100, y: 100 },
        data: {
          node_key: 'var_1',
          type: 'set_variable',
          name: 'Asignar Maratonica',
          configuration: {
            variable: 'MaratonicaMode',
            operation: 'set',
            data_type: 'boolean',
            value: 'TRUE',
          },
        },
      };

      render(<PropertiesPanel {...baseProps} selectedNode={setVarNode} />);

      const boolSelect = screen.getByTestId('set-variable-boolean-value-select') as HTMLSelectElement;
      expect(boolSelect).toBeInTheDocument();
      expect(boolSelect.value).toBe('TRUE');

      const options = Array.from(boolSelect.options).map((o) => o.value);
      expect(options).toEqual(['TRUE', 'FALSE']);

      fireEvent.change(boolSelect, { target: { value: 'FALSE' } });
      expect(mockUpdateNodeData).toHaveBeenCalledWith('var_1', {
        configuration: expect.objectContaining({
          value: 'FALSE',
        }),
      });
    });

    it('defaults value to TRUE when switching set_variable to boolean type', () => {
      const setVarNode: Node<EditorNodeData> = {
        id: 'var_text',
        type: 'set_variable',
        position: { x: 100, y: 100 },
        data: {
          node_key: 'var_text',
          type: 'set_variable',
          name: 'Asignar Texto',
          configuration: {
            variable: 'miVariable',
            operation: 'set',
            data_type: 'text',
            value: '',
          },
        },
      };

      render(<PropertiesPanel {...baseProps} selectedNode={setVarNode} />);

      const typeSelect = screen.getByTestId('set-variable-type-select');
      fireEvent.change(typeSelect, { target: { value: 'boolean' } });

      expect(mockUpdateNodeData).toHaveBeenCalledWith('var_text', {
        configuration: expect.objectContaining({
          data_type: 'boolean',
          type: 'boolean',
          value: 'TRUE',
        }),
      });
    });

    it('auto-detects boolean variable in condition node from flow canvas set_variable node with or without $', () => {
      const conditionNode: Node<EditorNodeData> = {
        id: 'cond_bool',
        type: 'condition',
        position: { x: 300, y: 300 },
        data: {
          node_key: 'cond_bool',
          type: 'condition',
          name: 'Evaluar Maratonica',
          configuration: {
            variable: '$MaratonicaMode',
            operator: 'equals',
            value: 'TRUE',
          },
        },
      };

      const allNodesWithBoolean = [
        {
          node_key: 'var_maratonica',
          name: 'Set Maratonica',
          type: 'set_variable',
          configuration: {
            variable: 'MaratonicaMode',
            data_type: 'boolean',
            value: 'TRUE',
          },
        },
      ];

      render(
        <PropertiesPanel
          {...baseProps}
          selectedNode={conditionNode}
          allNodes={allNodesWithBoolean}
        />
      );

      const boolSelect = screen.getByTestId('condition-boolean-value-select') as HTMLSelectElement;
      expect(boolSelect).toBeInTheDocument();
      expect(boolSelect.value).toBe('TRUE');

      const options = Array.from(boolSelect.options).map((o) => o.value);
      expect(options).toEqual(['TRUE', 'FALSE']);

      fireEvent.change(boolSelect, { target: { value: 'FALSE' } });
      expect(mockUpdateNodeData).toHaveBeenCalledWith('cond_bool', {
        configuration: expect.objectContaining({
          value: 'FALSE',
        }),
      });
    });

    it('allows toggling between Texto and Booleano manually in condition rules as fallback', () => {
      const conditionNode: Node<EditorNodeData> = {
        id: 'cond_manual',
        type: 'condition',
        position: { x: 300, y: 300 },
        data: {
          node_key: 'cond_manual',
          type: 'condition',
          name: 'Evaluar flag externo',
          configuration: {
            variable: 'external_flag',
            operator: 'equals',
            value: 'activado',
          },
        },
      };

      render(<PropertiesPanel {...baseProps} selectedNode={conditionNode} allNodes={[]} />);

      // Initially text input
      expect(screen.getByPlaceholderText('Ej. si')).toBeInTheDocument();

      // Click discrete Booleano toggle button
      const boolToggleBtn = screen.getByTitle('Evaluar como Booleano (TRUE/FALSE)');
      fireEvent.click(boolToggleBtn);

      expect(mockUpdateNodeData).toHaveBeenCalledWith('cond_manual', {
        configuration: expect.objectContaining({
          data_type: 'boolean',
          value: 'TRUE',
          operator: 'equals',
        }),
      });
    });

    it('renders boolean select in multi-rule condition when auto-detected or forced', () => {
      const multiRuleNode: Node<EditorNodeData> = {
        id: 'cond_multi',
        type: 'condition',
        position: { x: 300, y: 300 },
        data: {
          node_key: 'cond_multi',
          type: 'condition',
          name: 'Multi-regla',
          configuration: {
            logic: 'and',
            rules: [
              {
                variable: 'MaratonicaMode',
                operator: 'equals',
                value: 'TRUE',
              },
            ],
          },
        },
      };

      const allNodesWithBoolean = [
        {
          node_key: 'var_maratonica',
          name: 'Set Maratonica',
          type: 'set_variable',
          configuration: {
            variable: '$MaratonicaMode',
            data_type: 'boolean',
            value: 'TRUE',
          },
        },
      ];

      render(
        <PropertiesPanel
          {...baseProps}
          selectedNode={multiRuleNode}
          allNodes={allNodesWithBoolean}
        />
      );

      const ruleBoolSelect = screen.getByTestId('rule-boolean-value-select-0') as HTMLSelectElement;
      expect(ruleBoolSelect).toBeInTheDocument();
      expect(ruleBoolSelect.value).toBe('TRUE');
    });

    it('displays suggestions for variables defined in previous nodes and contact variables', () => {
      const conditionNode: Node<EditorNodeData> = {
        id: 'cond_suggest',
        type: 'condition',
        position: { x: 300, y: 300 },
        data: {
          node_key: 'cond_suggest',
          type: 'condition',
          name: 'Condicion con sugerencias',
          configuration: {
            variable: '',
            operator: 'equals',
            value: '',
          },
        },
      };

      const nodesInFlow = [
        {
          node_key: 'node_set',
          name: 'Modo Maratonica',
          type: 'set_variable',
          configuration: {
            variable: '$MaratonicaMode',
            data_type: 'boolean',
            value: 'TRUE',
          },
        },
        {
          node_key: 'node_question',
          name: 'Pregunta Nombre',
          type: 'question',
          configuration: {
            variable: 'nombre_usuario',
          },
        },
      ];

      render(
        <PropertiesPanel
          {...baseProps}
          selectedNode={conditionNode}
          allNodes={nodesInFlow}
        />
      );

      // Open suggestions dropdown using the discrete button
      const toggleBtn = screen.getByTestId('btn-variable-suggestions-single-rule');
      expect(toggleBtn).toBeInTheDocument();
      fireEvent.click(toggleBtn);

      // Verify suggested variables are listed
      expect(screen.getByTestId('suggestion-option-MaratonicaMode')).toBeInTheDocument();
      expect(screen.getByTestId('suggestion-option-nombre_usuario')).toBeInTheDocument();
      expect(screen.getByTestId('suggestion-option-contact.name')).toBeInTheDocument();
      expect(screen.getByTestId('suggestion-option-contact.email')).toBeInTheDocument();

      // Check badges / indicators
      expect(screen.getByText('MaratonicaMode')).toBeInTheDocument();
      expect(screen.getByText('nombre_usuario')).toBeInTheDocument();
    });

    it('automatically configures rule as boolean when selecting a suggested boolean variable', () => {
      const conditionNode: Node<EditorNodeData> = {
        id: 'cond_pick',
        type: 'condition',
        position: { x: 300, y: 300 },
        data: {
          node_key: 'cond_pick',
          type: 'condition',
          name: 'Condicion Selector',
          configuration: {
            variable: '',
            operator: 'equals',
            value: '',
          },
        },
      };

      const nodesInFlow = [
        {
          node_key: 'node_set_bool',
          name: 'Set Activo',
          type: 'set_variable',
          configuration: {
            variable: 'es_cliente_vip',
            data_type: 'boolean',
            value: 'TRUE',
          },
        },
      ];

      render(
        <PropertiesPanel
          {...baseProps}
          selectedNode={conditionNode}
          allNodes={nodesInFlow}
        />
      );

      const toggleBtn = screen.getByTestId('btn-variable-suggestions-single-rule');
      fireEvent.click(toggleBtn);

      const boolOption = screen.getByTestId('suggestion-option-es_cliente_vip');
      expect(boolOption).toBeInTheDocument();

      fireEvent.mouseDown(boolOption);

      expect(mockUpdateNodeData).toHaveBeenCalledWith('cond_pick', {
        configuration: expect.objectContaining({
          variable: 'es_cliente_vip',
          data_type: 'boolean',
          value: 'TRUE',
          operator: 'equals',
        }),
      });
    });

    it('renders choice dropdown when condition targets a question node with choices', () => {
      const conditionNode: Node<EditorNodeData> = {
        id: 'cond_choice',
        type: 'condition',
        position: { x: 300, y: 300 },
        data: {
          node_key: 'cond_choice',
          type: 'condition',
          name: 'Condicion Pregunta Choice',
          configuration: {
            variable: 'var_OtraPregunta',
            operator: 'equals',
            value: 'Sí',
          },
        },
      };

      const nodesInFlow = [
        {
          node_key: 'q_opciones',
          name: 'Pregunta con Opciones',
          type: 'question',
          configuration: {
            text: '¿Deseas continuar?',
            variable: 'var_OtraPregunta',
            input_type: 'choice',
            choices: ['Sí', 'No'],
          },
        },
      ];

      render(
        <PropertiesPanel
          {...baseProps}
          selectedNode={conditionNode}
          allNodes={nodesInFlow}
        />
      );

      // Verify choice dropdown is rendered
      const choiceSelect = screen.getByTestId('condition-choice-value-select') as HTMLSelectElement;
      expect(choiceSelect).toBeInTheDocument();
      expect(choiceSelect.value).toBe('Sí');

      // Verify options are present
      expect(screen.getByRole('option', { name: 'Sí' })).toBeInTheDocument();
      expect(screen.getByRole('option', { name: 'No' })).toBeInTheDocument();
      expect(screen.getByRole('option', { name: /Ingresar otro valor/i })).toBeInTheDocument();

      // Change option to 'No'
      fireEvent.change(choiceSelect, { target: { value: 'No' } });
      expect(mockUpdateNodeData).toHaveBeenCalledWith('cond_choice', {
        configuration: expect.objectContaining({
          variable: 'var_OtraPregunta',
          value: 'No',
        }),
      });
    });

    it('preselects first choice when selecting a question variable from suggestions', () => {
      const conditionNode: Node<EditorNodeData> = {
        id: 'cond_select_var',
        type: 'condition',
        position: { x: 300, y: 300 },
        data: {
          node_key: 'cond_select_var',
          type: 'condition',
          name: 'Condicion Seleccion',
          configuration: {
            variable: '',
            operator: 'equals',
            value: '',
          },
        },
      };

      const nodesInFlow = [
        {
          node_key: 'q_opciones_2',
          name: 'Pregunta Sí o No',
          type: 'question',
          configuration: {
            text: '¿Aceptas los términos?',
            variable: 'var_terminos',
            input_type: 'choice',
            choices: ['Acepto', 'Rechazo'],
          },
        },
      ];

      render(
        <PropertiesPanel
          {...baseProps}
          selectedNode={conditionNode}
          allNodes={nodesInFlow}
        />
      );

      // Open suggestions
      const toggleBtn = screen.getByTestId('btn-variable-suggestions-single-rule');
      fireEvent.click(toggleBtn);

      const option = screen.getByTestId('suggestion-option-var_terminos');
      expect(option).toBeInTheDocument();

      fireEvent.mouseDown(option);

      expect(mockUpdateNodeData).toHaveBeenCalledWith('cond_select_var', {
        configuration: expect.objectContaining({
          variable: 'var_terminos',
          value: 'Acepto',
        }),
      });
    });

    it('renders choice dropdown and updates value in multi-rule condition mode', () => {
      const multiRuleConditionNode: Node<EditorNodeData> = {
        id: 'cond_multi',
        type: 'condition',
        position: { x: 300, y: 300 },
        data: {
          node_key: 'cond_multi',
          type: 'condition',
          name: 'Condicion Multi Regla',
          configuration: {
            logic: 'and',
            rules: [
              {
                variable: 'var_canal',
                operator: 'equals',
                value: 'WhatsApp',
              },
            ],
          },
        },
      };

      const nodesInFlow = [
        {
          node_key: 'q_canal',
          name: 'Pregunta Canal',
          type: 'question',
          configuration: {
            variable: 'var_canal',
            input_type: 'choice',
            choices: ['WhatsApp', 'Telegram', 'Webchat'],
          },
        },
      ];

      render(
        <PropertiesPanel
          {...baseProps}
          selectedNode={multiRuleConditionNode}
          allNodes={nodesInFlow}
        />
      );

      const ruleChoiceSelect = screen.getByTestId('rule-choice-value-select-0') as HTMLSelectElement;
      expect(ruleChoiceSelect).toBeInTheDocument();
      expect(ruleChoiceSelect.value).toBe('WhatsApp');

      fireEvent.change(ruleChoiceSelect, { target: { value: 'Telegram' } });

      expect(mockUpdateNodeData).toHaveBeenCalledWith('cond_multi', {
        configuration: expect.objectContaining({
          rules: [
            expect.objectContaining({
              variable: 'var_canal',
              value: 'Telegram',
            }),
          ],
        }),
      });
    });

    it('allows toggling between choices dropdown and manual custom text input', () => {
      const conditionNode: Node<EditorNodeData> = {
        id: 'cond_toggle',
        type: 'condition',
        position: { x: 300, y: 300 },
        data: {
          node_key: 'cond_toggle',
          type: 'condition',
          name: 'Condicion Toggle Manual',
          configuration: {
            variable: 'var_opcion',
            operator: 'equals',
            value: 'Op1',
          },
        },
      };

      const nodesInFlow = [
        {
          node_key: 'q_opc',
          name: 'Pregunta Opciones',
          type: 'question',
          configuration: {
            variable: 'var_opcion',
            input_type: 'choice',
            choices: ['Op1', 'Op2'],
          },
        },
      ];

      render(
        <PropertiesPanel
          {...baseProps}
          selectedNode={conditionNode}
          allNodes={nodesInFlow}
        />
      );

      // Initially choice dropdown is shown
      expect(screen.getByTestId('condition-choice-value-select')).toBeInTheDocument();

      // Click "Manual" button to switch to text input
      const manualBtn = screen.getByTitle('Escribir valor manual');
      fireEvent.click(manualBtn);

      // Now text input is rendered
      const textInput = screen.getByTestId('condition-value-input');
      expect(textInput).toBeInTheDocument();

      // Click "Opciones" button to switch back to choice dropdown
      const opcionesBtn = screen.getByTitle('Volver a opciones de la pregunta');
      fireEvent.click(opcionesBtn);

      expect(screen.getByTestId('condition-choice-value-select')).toBeInTheDocument();
    });

    it('suggests system.agents_online and system.agents_count in condition variable suggestions', () => {
      const conditionNode: Node<EditorNodeData> = {
        id: 'cond_sys',
        type: 'condition',
        position: { x: 300, y: 300 },
        data: {
          node_key: 'cond_sys',
          type: 'condition',
          name: 'Condicion Agentes',
          configuration: {
            variable: '',
            operator: 'equals',
            value: '',
          },
        },
      };

      render(
        <PropertiesPanel
          {...baseProps}
          selectedNode={conditionNode}
          allNodes={[]}
        />
      );

      // Open variable suggestions by clicking the button or searching
      const openSuggestionsBtn = screen.getByTestId('btn-variable-suggestions-single-rule');
      fireEvent.click(openSuggestionsBtn);

      // Verify that system.agents_online and system.agents_count are rendered in the dropdown
      expect(screen.getByText('system.agents_online')).toBeInTheDocument();
      expect(screen.getByText('system.agents_count')).toBeInTheDocument();
    });
  });
});
