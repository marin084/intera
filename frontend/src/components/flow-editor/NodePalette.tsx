import React from 'react';
import {
  MessageSquare,
  ImageIcon,
  FileText,
  HelpCircle,
  Split,
  Headphones,
  CheckCircle2,
  ListFilter,
  Globe,
  CornerDownRight,
  Variable,
  X,
} from 'lucide-react';
import type { FlowNodeType } from '../../types';

interface NodePaletteProps {
  onAddNode: (type: FlowNodeType) => void;
  isReadOnly?: boolean;
  onClose?: () => void;
}

interface PaletteItemDef {
  type: FlowNodeType;
  name: string;
  description: string;
  icon: React.ComponentType<{ size?: number; className?: string; color?: string }>;
  color: string;
  bgColor: string;
}

const PALETTE_ITEMS: PaletteItemDef[] = [
  {
    type: 'message',
    name: 'Mensaje',
    description: 'Envía un mensaje de texto o saludo al usuario.',
    icon: MessageSquare,
    color: '#5d8f82',
    bgColor: '#eaf1ee',
  },
  {
    type: 'image',
    name: 'Imagen',
    description: 'Envía una imagen o infografía con pie de foto opcional.',
    icon: ImageIcon,
    color: '#5d8f82',
    bgColor: '#ecfeff',
  },
  {
    type: 'file',
    name: 'Documento / Archivo',
    description: 'Envía documentos (PDF, Word, Excel, PPT, TXT, CSV).',
    icon: FileText,
    color: '#5d8f82',
    bgColor: '#f0fdfa',
  },
  {
    type: 'menu',
    name: 'Menú Interactivo',
    description: 'Menú de opciones con botones y ramificaciones.',
    icon: ListFilter,
    color: '#0284c7',
    bgColor: '#e0f2fe',
  },
  {
    type: 'question',
    name: 'Pregunta',
    description: 'Solicita un dato y lo guarda en una variable.',
    icon: HelpCircle,
    color: '#9333ea',
    bgColor: '#faf5ff',
  },
  {
    type: 'condition',
    name: 'Condición',
    description: 'Evalúa variables y bifurca en ramas TRUE y FALSE.',
    icon: Split,
    color: '#d97706',
    bgColor: '#fffbeb',
  },
  {
    type: 'set_variable',
    name: 'Variable',
    description: 'Establece o transforma el valor de una variable.',
    icon: Variable,
    color: '#456c61',
    bgColor: '#eef2ff',
  },
  {
    type: 'http_request',
    name: 'Petición HTTP',
    description: 'Consulta una API externa y mapea datos a variables.',
    icon: Globe,
    color: '#5d8f82',
    bgColor: '#eaf1ee',
  },
  {
    type: 'goto',
    name: 'Ir a Bloque',
    description: 'Salta directamente a otro bloque del flujo.',
    icon: CornerDownRight,
    color: '#5d8f82',
    bgColor: '#eaf1ee',
  },
  {
    type: 'transfer_to_agent',
    name: 'Transferir',
    description: 'Transfiere la conversación a un agente humano.',
    icon: Headphones,
    color: '#5d8f82',
    bgColor: '#ecfeff',
  },
  {
    type: 'end',
    name: 'Fin de Flujo',
    description: 'Cierra y finaliza la conversación del bot.',
    icon: CheckCircle2,
    color: '#61726d',
    bgColor: '#eaf1ee',
  },
];

export const NodePalette: React.FC<NodePaletteProps> = ({ onAddNode, isReadOnly = false, onClose }) => {
  const handleDragStart = (e: React.DragEvent, type: FlowNodeType) => {
    if (isReadOnly) return;
    e.dataTransfer.setData('application/conexa-node-type', type);
    e.dataTransfer.effectAllowed = 'move';
  };

  return (
    <aside className="node-palette">
      <div className="node-palette-header">
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.25rem' }}>
          <div className="node-palette-title" style={{ margin: 0 }}>Catálogo de Bloques</div>
          {onClose && (
            <button
              type="button"
              className="btn btn-sm node-palette-close-btn"
              data-testid="close-palette-btn"
              onClick={onClose}
              title="Cerrar catálogo de bloques"
              aria-label="Cerrar catálogo de bloques"
              style={{
                padding: '0.2rem',
                border: 'none',
                background: 'transparent',
                color: '#61726d',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                borderRadius: '4px',
              }}
            >
              <X size={16} />
            </button>
          )}
        </div>
        <div className="node-palette-subtitle">
          {isReadOnly
            ? 'Modo solo lectura'
            : 'Arrastra al lienzo o haz clic para añadir'}
        </div>
      </div>

      <div className="node-palette-list">
        {PALETTE_ITEMS.map((item) => {
          const Icon = item.icon;
          return (
            <div
              key={item.type}
              className={`palette-item ${isReadOnly ? 'disabled' : ''}`}
              draggable={!isReadOnly}
              onDragStart={(e) => handleDragStart(e, item.type)}
              onClick={() => {
                if (!isReadOnly) onAddNode(item.type);
              }}
              title={isReadOnly ? 'Versión en solo lectura' : `Añadir ${item.name}`}
            >
              <div
                className="palette-icon"
                style={{ backgroundColor: item.bgColor, color: item.color }}
              >
                <Icon size={18} />
              </div>
              <div className="palette-item-info">
                <span className="palette-item-name">{item.name}</span>
                <span className="palette-item-desc">{item.description}</span>
              </div>
            </div>
          );
        })}
      </div>
    </aside>
  );
};
