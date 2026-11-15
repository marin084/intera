import React, { memo, useState } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import { ListFilter, AlertTriangle, ChevronDown, ChevronUp, EyeOff, Headset } from 'lucide-react';
import type { EditorNodeData } from '../adapter';
import { NodeHeaderActions } from './NodeHeaderActions';

export const MenuNode: React.FC<NodeProps<any>> = memo(({ data, selected }) => {
  const nodeData = data as EditorNodeData;
  const config = nodeData.configuration || {};
  const text = config.text || config.title || 'Selecciona una opción:';
  const options: Array<{
    id: string;
    text: string;
    target_node_key?: string;
    is_hidden?: boolean;
    visible?: boolean;
    hide_if_no_agents?: boolean;
  }> = config.options || [];
  const [isExpanded, setIsExpanded] = useState(true);

  return (
    <div
      className={`custom-node custom-node-menu ${selected ? 'selected' : ''} ${
        nodeData.hasError ? 'has-error' : ''
      } ${nodeData.isHighlighted ? 'highlighted-node' : ''} ${
        nodeData.isCollapsed ? 'node-collapsed' : ''
      }`}
      style={{ minWidth: options.length > 4 ? `${Math.min(220 + options.length * 28, 480)}px` : '240px' }}
    >
      {/* Top Input Handle */}
      <Handle
        type="target"
        position={Position.Top}
        id="input"
        isConnectable={!nodeData.isReadOnly}
      />

      {/* Header */}
      <div className="node-header">
        <div className="node-title-group">
          <div className="node-icon-badge" style={{ background: '#e0f2fe', color: '#0284c7' }}>
            <ListFilter size={14} />
          </div>
          <span className="node-title">{nodeData.name || 'Menú de Opciones'}</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
          <span className="node-tag badge-info" style={{ fontSize: '0.65rem', padding: '0.1rem 0.4rem' }}>
            {options.length} OPCIONES
          </span>
          <button
            type="button"
            className="btn btn-sm"
            style={{ padding: '0.1rem 0.25rem', border: 'none', background: 'transparent', color: '#61726d' }}
            onClick={(e) => {
              e.stopPropagation();
              setIsExpanded(!isExpanded);
            }}
            title={isExpanded ? 'Vista compacta' : 'Vista detallada'}
          >
            {isExpanded ? <ChevronUp size={13} /> : <ChevronDown size={13} />}
          </button>
          <NodeHeaderActions nodeKey={nodeData.node_key} nodeData={nodeData} />
        </div>
      </div>

      {/* Body */}
      <div className="node-body">
        <p className="node-text-preview" style={{ marginBottom: options.length > 0 ? '0.5rem' : '0' }}>
          {text}
        </p>

        {isExpanded && !nodeData.isCollapsed && options.length > 0 && (
          <div className="menu-options-list" style={{ display: 'flex', flexDirection: 'column', gap: '0.3rem' }}>
            {options.map((opt, idx) => {
              const isHidden = opt.is_hidden === true || opt.visible === false;
              return (
                <div
                  key={opt.id || idx}
                  className={`menu-node-option-item ${isHidden ? 'opacity-60 menu-node-option-hidden' : ''}`}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    background: isHidden ? '#eaf1ee' : '#f4f7f5',
                    border: `1px solid ${isHidden ? '#d9e3de' : '#d9e3de'}`,
                    borderRadius: '4px',
                    padding: '0.25rem 0.5rem',
                    fontSize: '0.75rem',
                    opacity: isHidden ? 0.6 : 1,
                    transition: 'all 0.15s ease',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', overflow: 'hidden' }}>
                    <span
                      style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        width: '16px',
                        height: '16px',
                        borderRadius: '50%',
                        background: isHidden ? '#80918c' : '#0284c7',
                        color: '#ffffff',
                        fontSize: '0.65rem',
                        fontWeight: 700,
                        flexShrink: 0,
                      }}
                    >
                      {idx + 1}
                    </span>
                    <span
                      style={{
                        fontWeight: 600,
                        color: isHidden ? '#61726d' : '#35564f',
                        whiteSpace: 'nowrap',
                        textOverflow: 'ellipsis',
                        overflow: 'hidden',
                      }}
                    >
                      {opt.text || `Opción ${idx + 1}`}
                    </span>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.3rem', flexShrink: 0 }}>
                    {isHidden && (
                      <span
                        className="badge-hidden-pill"
                        data-testid="badge-hidden-option"
                        style={{
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: '2px',
                          fontSize: '0.6rem',
                          fontWeight: 600,
                          padding: '0.05rem 0.3rem',
                          borderRadius: '3px',
                          background: '#d9e3de',
                          color: '#61726d',
                        }}
                        title="Opción oculta en chat (mantiene conexión en lienzo)"
                      >
                        <EyeOff size={10} />
                        Oculta
                      </span>
                    )}
                    {opt.hide_if_no_agents && (
                      <span
                        className="badge-agent-pill"
                        data-testid="badge-agent-option"
                        style={{
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: '2px',
                          fontSize: '0.6rem',
                          fontWeight: 600,
                          padding: '0.05rem 0.3rem',
                          borderRadius: '3px',
                          background: '#e0f2fe',
                          color: '#0369a1',
                        }}
                        title="Visible solo si hay agentes en línea"
                      >
                        <Headset size={10} />
                        Agente
                      </span>
                    )}
                    <span style={{ fontSize: '0.65rem', color: isHidden ? '#80918c' : '#61726d', fontFamily: 'monospace' }}>
                      ●
                    </span>
                  </div>
                </div>
              );
            })}
          </div>
        )}

        {nodeData.isCollapsed && (
          <div className="node-collapsed-notice">
            Ramas del menú contraídas ({nodeData.collapsedCount || 0} bloques)
          </div>
        )}

        {nodeData.hasError && (
          <div className="node-error-badge">
            <AlertTriangle size={12} /> Opciones sin destino o configuración incompleta
          </div>
        )}
      </div>

      {/* Bottom Output Handles for Each Option (CRITICAL: kept functional for hidden options) */}
      {options.map((opt, idx) => {
        const leftPercent = ((idx + 1) / (options.length + 1)) * 100;
        const isHidden = opt.is_hidden === true || opt.visible === false;
        return (
          <Handle
            key={opt.id || idx}
            type="source"
            position={Position.Bottom}
            id={`opt_${opt.id}`}
            style={{
              left: `${leftPercent}%`,
              backgroundColor: isHidden ? '#80918c' : '#0284c7',
              borderColor: '#ffffff',
              width: '10px',
              height: '10px',
            }}
            title={`Opción ${idx + 1}: ${opt.text}${isHidden ? ' (Oculta en chat)' : ''}`}
            isConnectable={!nodeData.isReadOnly}
          />
        );
      })}
    </div>
  );
});

MenuNode.displayName = 'MenuNode';
