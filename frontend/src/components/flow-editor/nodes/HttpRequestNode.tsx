import React, { memo } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import { Globe, AlertTriangle } from 'lucide-react';
import type { EditorNodeData } from '../adapter';
import { NodeHeaderActions } from './NodeHeaderActions';

export const HttpRequestNode: React.FC<NodeProps<any>> = memo(({ data, selected }) => {
  const nodeData = data as EditorNodeData;
  const config = nodeData.configuration || {};
  const method = (config.method || 'GET').toUpperCase();
  const url = config.url || 'https://api.ejemplo.com/...';
  const mappings = config.response_mapping || [];

  const methodColors: Record<string, { bg: string; color: string }> = {
    GET: { bg: '#eaf1ee', color: '#263c38' },
    POST: { bg: '#ddeae4', color: '#456c61' },
    PUT: { bg: '#fef3c7', color: '#b45309' },
    PATCH: { bg: '#fef3c7', color: '#b45309' },
    DELETE: { bg: '#fee2e2', color: '#b91c1c' },
  };

  const style = methodColors[method] || methodColors.GET;

  return (
    <div
      className={`custom-node custom-node-http ${selected ? 'selected' : ''} ${
        nodeData.hasError ? 'has-error' : ''
      } ${nodeData.isHighlighted ? 'highlighted-node' : ''} ${
        nodeData.isCollapsed ? 'node-collapsed' : ''
      }`}
    >
      <Handle
        type="target"
        position={Position.Top}
        id="input"
        isConnectable={!nodeData.isReadOnly}
      />

      <div className="node-header">
        <div className="node-title-group">
          <div className="node-icon-badge" style={{ background: '#eaf1ee', color: '#5d8f82' }}>
            <Globe size={14} />
          </div>
          <span className="node-title">{nodeData.name || 'Petición HTTP'}</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          <span
            style={{
              padding: '0.15rem 0.4rem',
              borderRadius: '4px',
              fontSize: '0.65rem',
              fontWeight: 700,
              background: style.bg,
              color: style.color,
            }}
          >
            {method}
          </span>
          <NodeHeaderActions nodeKey={nodeData.node_key} nodeData={nodeData} />
        </div>
      </div>

      <div className="node-body">
        <div
          style={{
            background: '#f4f7f5',
            border: '1px solid #d9e3de',
            borderRadius: '4px',
            padding: '0.35rem 0.5rem',
            fontSize: '0.75rem',
            fontFamily: 'monospace',
            color: '#56736b',
            overflow: 'hidden',
            textOverflow: 'ellipsis',
            whiteSpace: 'nowrap',
          }}
          title={url}
        >
          {url}
        </div>

        {Array.isArray(mappings) && mappings.length > 0 && (
          <div style={{ marginTop: '0.35rem', fontSize: '0.7rem', color: '#61726d' }}>
            ↳ Extrae {mappings.length} variable{mappings.length > 1 ? 's' : ''}
          </div>
        )}

        {nodeData.isCollapsed && (
          <div className="node-collapsed-notice">
            Ramas contraídas ({nodeData.collapsedCount || 0} bloques)
          </div>
        )}

        {nodeData.hasError && (
          <div className="node-error-badge">
            <AlertTriangle size={12} /> Configuración HTTP incompleta
          </div>
        )}
      </div>

      <Handle
        type="source"
        position={Position.Bottom}
        id="output"
        isConnectable={!nodeData.isReadOnly}
      />
    </div>
  );
});

HttpRequestNode.displayName = 'HttpRequestNode';
