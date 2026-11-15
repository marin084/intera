import React, { useState, useEffect, useRef } from 'react';
import { Search, X, MessageSquare, ImageIcon, FileText, HelpCircle, Split, Play, Headphones, CheckCircle2 } from 'lucide-react';
import type { Node } from '@xyflow/react';
import type { EditorNodeData } from './adapter';
import { searchNodes } from './branchVisibility';

interface FlowSearchModalProps {
  nodes: Node<EditorNodeData>[];
  isOpen: boolean;
  onClose: () => void;
  onSelectNode: (nodeKey: string) => void;
}

export const FlowSearchModal: React.FC<FlowSearchModalProps> = ({
  nodes,
  isOpen,
  onClose,
  onSelectNode,
}) => {
  const [query, setQuery] = useState('');
  const [selectedIndex, setSelectedIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);

  const results = searchNodes(nodes, query);

  useEffect(() => {
    if (isOpen) {
      setTimeout(() => {
        inputRef.current?.focus();
        inputRef.current?.select();
      }, 50);
    }
    const handleGlobalKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    window.addEventListener('keydown', handleGlobalKeyDown);
    return () => window.removeEventListener('keydown', handleGlobalKeyDown);
  }, [isOpen, onClose]);

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      onClose();
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      setSelectedIndex((prev) => (prev < results.length - 1 ? prev + 1 : prev));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setSelectedIndex((prev) => (prev > 0 ? prev - 1 : 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (results[selectedIndex]) {
        onSelectNode(results[selectedIndex].id);
        onClose();
      }
    }
  };

  if (!isOpen) return null;

  const getNodeIcon = (type?: string) => {
    switch (type) {
      case 'start':
        return <Play size={13} fill="#5d8f82" color="#5d8f82" />;
      case 'message':
        return <MessageSquare size={13} color="#5d8f82" />;
      case 'image':
        return <ImageIcon size={13} color="#5d8f82" />;
      case 'file':
        return <FileText size={13} color="#5d8f82" />;
      case 'question':
        return <HelpCircle size={13} color="#9333ea" />;
      case 'condition':
        return <Split size={13} color="#d97706" />;
      case 'transfer_to_agent':
        return <Headphones size={13} color="#5d8f82" />;
      case 'end':
        return <CheckCircle2 size={13} color="#61726d" />;
      default:
        return <MessageSquare size={13} />;
    }
  };

  return (
    <div className="flow-search-modal" role="dialog" aria-modal="true" aria-label="Buscar bloques del flujo">
      <div className="flow-search-header">
        <Search size={16} color="#61726d" />
        <input
          ref={inputRef}
          type="text"
          className="flow-search-input"
          placeholder="Buscar bloques por nombre, tipo, texto o clave (↑↓ para navegar)..."
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            setSelectedIndex(0);
          }}
          onKeyDown={handleKeyDown}
        />
        <button
          type="button"
          onClick={onClose}
          style={{
            background: 'none',
            border: 'none',
            cursor: 'pointer',
            color: '#61726d',
            padding: '2px',
          }}
          title="Cerrar búsqueda (Esc)"
        >
          <X size={16} />
        </button>
      </div>

      <div className="flow-search-results">
        {query.trim() === '' ? (
          <div style={{ padding: '1rem', textAlign: 'center', color: '#80918c', fontSize: '0.8rem' }}>
            Escribe para buscar entre los {nodes.length} bloques del flujo
          </div>
        ) : results.length === 0 ? (
          <div style={{ padding: '1rem', textAlign: 'center', color: '#80918c', fontSize: '0.8rem' }}>
            No se encontraron bloques para "{query}"
          </div>
        ) : (
          results.map((node, index) => {
            const isSelected = index === selectedIndex;
            const config = node.data?.configuration || {};
            const preview = config.text || config.message || (config.variable ? `$${config.variable}` : '');

            return (
              <div
                key={node.id}
                className={`flow-search-item ${isSelected ? 'active' : ''}`}
                onClick={() => {
                  onSelectNode(node.id);
                  onClose();
                }}
                onMouseEnter={() => setSelectedIndex(index)}
              >
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', overflow: 'hidden' }}>
                  <div style={{ flexShrink: 0 }}>{getNodeIcon(node.type)}</div>
                  <div className="flow-search-item-info">
                    <span className="flow-search-item-name">{node.data?.name || node.id}</span>
                    <span className="flow-search-item-sub">
                      {node.id} {preview ? `• "${preview.substring(0, 45)}${preview.length > 45 ? '...' : ''}"` : ''}
                    </span>
                  </div>
                </div>

                <span
                  className="badge"
                  style={{
                    fontSize: '0.65rem',
                    padding: '0.15rem 0.4rem',
                    textTransform: 'uppercase',
                    background: '#eaf1ee',
                    color: '#61726d',
                  }}
                >
                  {node.type || 'nodo'}
                </span>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
};
