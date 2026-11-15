import React, { memo } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import { Variable as VariableIcon, AlertTriangle } from 'lucide-react';
import type { EditorNodeData } from '../adapter';
import { NodeHeaderActions } from './NodeHeaderActions';

export const SetVariableNode: React.FC<NodeProps<any>> = memo(({ data, selected }) => {
  const nodeData = data as EditorNodeData;
  const config = nodeData.configuration || {};
  const variable = config.variable || '';
  const operation = config.operation || 'set';
  const value = config.value !== undefined ? String(config.value) : '';

  const opSymbols: Record<string, string> = {
    set: '=',
    increment: '+=',
    decrement: '-=',
    clear: 'CLEAR',
  };

  const opSymbol = opSymbols[operation] || '=';

  return (
    <div
      className={`custom-node custom-node-action ${selected ? 'selected' : ''} ${
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
            <VariableIcon size={14} />
          </div>
          <span className="node-title">{nodeData.name || 'Establecer Variable'}</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          <span
            style={{
              padding: '0.15rem 0.4rem',
              borderRadius: '4px',
              fontSize: '0.65rem',
              fontWeight: 700,
              background: '#eaf1ee',
              color: '#263c38',
            }}
          >
            VARIABLE
          </span>
          <NodeHeaderActions nodeKey={nodeData.node_key} nodeData={nodeData} />
        </div>
      </div>

      <div className="node-body">
        <div
          style={{
            background: '#eef2ff',
            border: '1px solid #c7d2fe',
            borderRadius: '4px',
            padding: '0.35rem 0.5rem',
            fontSize: '0.75rem',
            fontFamily: 'monospace',
          }}
        >
          <span style={{ color: '#263c38', fontWeight: 700 }}>${variable || '?'}</span>{' '}
          <span style={{ color: '#61726d' }}>{opSymbol}</span>{' '}
          {operation !== 'clear' && (
            <span style={{ color: '#35564f', fontWeight: 600 }}>"{value}"</span>
          )}
        </div>

        {nodeData.isCollapsed && (
          <div className="node-collapsed-notice">
            Ramas contraídas ({nodeData.collapsedCount || 0} bloques)
          </div>
        )}

        {nodeData.hasError && (
          <div className="node-error-badge">
            <AlertTriangle size={12} /> Requiere nombre de variable válido
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

SetVariableNode.displayName = 'SetVariableNode';
