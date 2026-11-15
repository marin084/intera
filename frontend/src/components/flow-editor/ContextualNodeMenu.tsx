import React, { useState, useMemo, useEffect, useRef } from 'react';
import {
  MessageSquare,
  ListFilter,
  Image,
  FileText,
  HelpCircle,
  Variable,
  Globe,
  CornerDownRight,
  Headphones,
  Split,
  CheckCircle2,
  X,
  Search,
} from 'lucide-react';
import type { FlowNodeType } from '../../types';

export interface ContextualInsertionTarget {
  type: 'edge' | 'handle' | 'canvas';
  edgeId?: string;
  sourceNodeKey?: string;
  sourceHandle?: string | null;
  targetNodeKey?: string;
  position: { x: number; y: number };
  clientPosition?: { clientX: number; clientY: number };
}

interface ContextualNodeMenuProps {
  isOpen: boolean;
  target: ContextualInsertionTarget | null;
  onClose: () => void;
  onSelectNodeType: (type: FlowNodeType, target: ContextualInsertionTarget) => void;
}

interface MenuItemDef {
  type: FlowNodeType | 'image_soon' | 'file_soon' | 'form_soon';
  name: string;
  description: string;
  icon: React.ComponentType<{ size?: number; className?: string; color?: string }>;
  color: string;
  category: 'bot_response' | 'user_input' | 'actions' | 'conditions' | 'flow_control';
  enabled: boolean;
}

const MENU_ITEMS: MenuItemDef[] = [
  // 1. RESPUESTA DEL BOT
  {
    type: 'message',
    name: 'Mensaje de texto',
    description: 'Envía un mensaje de texto o saludo al usuario.',
    icon: MessageSquare,
    color: '#5d8f82',
    category: 'bot_response',
    enabled: true,
  },
  {
    type: 'image',
    name: 'Imagen',
    description: 'Envío de contenido visual o infografías con pie de foto.',
    icon: Image,
    color: '#5d8f82',
    category: 'bot_response',
    enabled: true,
  },
  {
    type: 'file',
    name: 'Documento / Archivo',
    description: 'Envío de documentos (PDF, Word, Excel, PPT, TXT, CSV).',
    icon: FileText,
    color: '#5d8f82',
    category: 'bot_response',
    enabled: true,
  },
  {
    type: 'menu',
    name: 'Menú con opciones',
    description: 'Ofrece botones interactivos con múltiples salidas.',
    icon: ListFilter,
    color: '#0284c7',
    category: 'bot_response',
    enabled: true,
  },

  // 2. ENTRADA DEL USUARIO
  {
    type: 'question',
    name: 'Pregunta / Captura de texto',
    description: 'Solicita un dato al usuario y lo guarda en una variable.',
    icon: HelpCircle,
    color: '#9333ea',
    category: 'user_input',
    enabled: true,
  },

  // 3. ACCIONES Y VARIABLES
  {
    type: 'set_variable',
    name: 'Establecer variable',
    description: 'Asigna o modifica el valor de una variable.',
    icon: Variable,
    color: '#456c61',
    category: 'actions',
    enabled: true,
  },
  {
    type: 'http_request',
    name: 'Petición HTTP',
    description: 'Llama una API externa y mapea la respuesta a variables.',
    icon: Globe,
    color: '#5d8f82',
    category: 'actions',
    enabled: true,
  },
  {
    type: 'goto',
    name: 'Ir a bloque',
    description: 'Salta directamente a un bloque existente sin duplicarlo.',
    icon: CornerDownRight,
    color: '#5d8f82',
    category: 'actions',
    enabled: true,
  },

  // 4. CONDICIONES
  {
    type: 'condition',
    name: 'Condición inteligente',
    description: 'Evalúa variables (igual, mayor, contiene, etc.) en ramas TRUE/FALSE.',
    icon: Split,
    color: '#d97706',
    category: 'conditions',
    enabled: true,
  },

  // 5. CONTROL DEL FLUJO
  {
    type: 'transfer_to_agent',
    name: 'Transferir a agente',
    description: 'Transfiere la atención a un operador humano.',
    icon: Headphones,
    color: '#5d8f82',
    category: 'flow_control',
    enabled: true,
  },
  {
    type: 'end',
    name: 'Finalizar conversación',
    description: 'Cierra y concluye la conversación del bot.',
    icon: CheckCircle2,
    color: '#61726d',
    category: 'flow_control',
    enabled: true,
  },
];

const CATEGORY_LABELS: Record<MenuItemDef['category'], string> = {
  bot_response: 'RESPUESTA DEL BOT',
  user_input: 'ENTRADA DEL USUARIO',
  actions: 'ACCIONES',
  conditions: 'CONDICIONES',
  flow_control: 'CONTROL DEL FLUJO',
};

export const ContextualNodeMenu: React.FC<ContextualNodeMenuProps> = (props) => {
  if (!props.isOpen || !props.target) return null;
  return <ContextualNodeMenuContent {...props} target={props.target} />;
};

const ContextualNodeMenuContent: React.FC<ContextualNodeMenuProps & { target: NonNullable<ContextualNodeMenuProps['target']> }> = ({
  target,
  onClose,
  onSelectNodeType,
}) => {
  const [searchTerm, setSearchTerm] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const timer = setTimeout(() => inputRef.current?.focus(), 50);
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => {
      clearTimeout(timer);
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [onClose]);

  const filteredItems = useMemo(() => {
    const term = searchTerm.toLowerCase().trim();
    if (!term) return MENU_ITEMS;
    return MENU_ITEMS.filter(
      (item) =>
        item.name.toLowerCase().includes(term) ||
        item.description.toLowerCase().includes(term) ||
        CATEGORY_LABELS[item.category].toLowerCase().includes(term)
    );
  }, [searchTerm]);

  const grouped = useMemo(() => {
    const groups: Partial<Record<MenuItemDef['category'], MenuItemDef[]>> = {};
    for (const item of filteredItems) {
      if (!groups[item.category]) groups[item.category] = [];
      groups[item.category]!.push(item);
    }
    return groups;
  }, [filteredItems]);

  const categoriesOrder: MenuItemDef['category'][] = [
    'bot_response',
    'user_input',
    'actions',
    'conditions',
    'flow_control',
  ];

  const isEdgeInsertion = target.type === 'edge';
  const isHandleInsertion = target.type === 'handle';

  const popoverPosition = useMemo(() => {
    if (!target.clientPosition) return null;

    const { clientX, clientY } = target.clientPosition;
    const width = 450;
    const height = 520;
    const margin = 16;

    let left = clientX + 12;
    let top = clientY - 30;

    if (typeof window !== 'undefined') {
      if (left + width > window.innerWidth - margin) {
        left = Math.max(margin, clientX - width - 12);
      }
      if (top + height > window.innerHeight - margin) {
        top = Math.max(margin, window.innerHeight - height - margin);
      }
      if (top < margin) {
        top = margin;
      }
      if (left < margin) {
        left = margin;
      }
    }

    return {
      left: `${left}px`,
      top: `${top}px`,
      width: `${width}px`,
      maxHeight: `min(${height}px, calc(100vh - 32px))`,
    };
  }, [target.clientPosition]);

  return (
    <div
      className="contextual-menu-overlay"
      data-testid="contextual-node-menu-modal"
      onClick={onClose}
      style={{
        position: 'fixed',
        top: 0,
        left: 0,
        right: 0,
        bottom: 0,
        backgroundColor: target.clientPosition ? 'rgba(15, 23, 42, 0.25)' : 'rgba(15, 23, 42, 0.45)',
        display: popoverPosition ? 'block' : 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 1000,
        backdropFilter: 'blur(2px)',
      }}
    >
      <div
        className="contextual-menu-modal"
        onClick={(e) => e.stopPropagation()}
        style={{
          width: popoverPosition ? popoverPosition.width : '490px',
          maxHeight: popoverPosition ? popoverPosition.maxHeight : '85vh',
          ...(popoverPosition
            ? { position: 'absolute' as const, left: popoverPosition.left, top: popoverPosition.top }
            : {}),
          background: '#ffffff',
          borderRadius: '12px',
          boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.08)',
          display: 'flex',
          flexDirection: 'column',
          overflow: 'hidden',
          border: '1px solid #d9e3de',
        }}
      >
        {/* Header */}
        <div
          style={{
            padding: '0.9rem 1.25rem',
            borderBottom: '1px solid #d9e3de',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            background: '#f4f7f5',
          }}
        >
          <div>
            <h3 style={{ fontSize: '0.95rem', fontWeight: 700, margin: 0, color: '#263c38' }}>
              {isEdgeInsertion
                ? 'Insertar bloque en la conexión'
                : isHandleInsertion
                  ? 'Añadir bloque a la salida'
                  : 'Añadir nuevo bloque al flujo'}
            </h3>
            <p style={{ fontSize: '0.75rem', color: '#61726d', margin: '0.15rem 0 0 0' }}>
              {isEdgeInsertion
                ? 'Divide la conexión e inserta el bloque automáticamente'
                : isHandleInsertion
                  ? 'El bloque nuevo quedará conectado a esta salida'
                  : 'Selecciona el tipo de bloque a crear'}
            </p>
          </div>
          <button
            type="button"
            className="btn btn-sm"
            style={{ padding: '0.25rem', border: 'none', background: 'transparent', color: '#61726d' }}
            onClick={onClose}
            title="Cerrar menú"
            aria-label="Cerrar menú"
          >
            <X size={18} />
          </button>
        </div>

        {/* Search */}
        <div style={{ padding: '0.75rem 1.25rem', borderBottom: '1px solid #eaf1ee' }}>
          <div style={{ position: 'relative' }}>
            <Search
              size={15}
              style={{ position: 'absolute', left: '10px', top: '50%', transform: 'translateY(-50%)', color: '#80918c' }}
            />
            <input
              ref={inputRef}
              type="text"
              className="form-control"
              placeholder="Buscar bloques (mensaje, menú, variable, http...)"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              style={{ paddingLeft: '2rem', fontSize: '0.85rem' }}
            />
          </div>
        </div>

        {/* Items list */}
        <div style={{ flex: 1, overflowY: 'auto', padding: '0.75rem 1.25rem' }}>
          {categoriesOrder.map((cat) => {
            const items = grouped[cat];
            if (!items || items.length === 0) return null;

            return (
              <div key={cat} style={{ marginBottom: '1rem' }}>
                <div
                  style={{
                    fontSize: '0.68rem',
                    fontWeight: 700,
                    letterSpacing: '0.05em',
                    color: '#61726d',
                    marginBottom: '0.4rem',
                    textTransform: 'uppercase',
                  }}
                >
                  {CATEGORY_LABELS[cat]}
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr', gap: '0.35rem' }}>
                  {items.map((item) => {
                    const Icon = item.icon;
                    return (
                      <div
                        key={item.type}
                        data-testid={`contextual-node-${item.type}`}
                        onClick={() => {
                          if (item.enabled) {
                            onSelectNodeType(item.type as FlowNodeType, target);
                          }
                        }}
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'space-between',
                          padding: '0.55rem 0.75rem',
                          borderRadius: '8px',
                          border: '1px solid',
                          borderColor: item.enabled ? '#d9e3de' : '#eaf1ee',
                          background: item.enabled ? '#ffffff' : '#f4f7f5',
                          cursor: item.enabled ? 'pointer' : 'not-allowed',
                          opacity: item.enabled ? 1 : 0.6,
                          transition: 'all 0.15s ease',
                        }}
                        onMouseEnter={(e) => {
                          if (item.enabled) {
                            e.currentTarget.style.borderColor = '#93c5fd';
                            e.currentTarget.style.background = '#f0f9ff';
                          }
                        }}
                        onMouseLeave={(e) => {
                          if (item.enabled) {
                            e.currentTarget.style.borderColor = '#d9e3de';
                            e.currentTarget.style.background = '#ffffff';
                          }
                        }}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.65rem' }}>
                          <div
                            style={{
                              width: '32px',
                              height: '32px',
                              borderRadius: '8px',
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'center',
                              background: item.enabled ? `${item.color}15` : '#d9e3de',
                              color: item.color,
                            }}
                          >
                            <Icon size={16} />
                          </div>
                          <div>
                            <div style={{ fontSize: '0.85rem', fontWeight: 600, color: item.enabled ? '#263c38' : '#80918c' }}>
                              {item.name}
                            </div>
                            <div style={{ fontSize: '0.72rem', color: '#61726d' }}>
                              {item.description}
                            </div>
                          </div>
                        </div>

                        {!item.enabled && (
                          <span
                            style={{
                              fontSize: '0.65rem',
                              fontWeight: 600,
                              background: '#eaf1ee',
                              color: '#80918c',
                              padding: '0.15rem 0.4rem',
                              borderRadius: '4px',
                            }}
                          >
                            Próximamente
                          </span>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
};
