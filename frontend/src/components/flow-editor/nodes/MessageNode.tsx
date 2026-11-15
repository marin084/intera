import React, { memo } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import { MessageSquare, AlertTriangle } from 'lucide-react';
import type { EditorNodeData } from '../adapter';

import { NodeHeaderActions } from './NodeHeaderActions';

export const MessageNode: React.FC<NodeProps<any>> = memo(({ data, selected }) => {
  const nodeData = data as EditorNodeData;
  const text = nodeData.configuration?.text || '';

  return (
    <div
      className={`custom-node custom-node-message ${selected ? 'selected' : ''} ${
        nodeData.hasError ? 'has-error' : ''
      } ${nodeData.isHighlighted ? 'highlighted-node' : ''} ${
        nodeData.isCollapsed ? 'node-collapsed' : ''
      }`}
    >
      {/* Input handle */}
      <Handle
        type="target"
        position={Position.Top}
        id="input"
        isConnectable={!nodeData.isReadOnly}
      />

      <div className="node-header">
        <div className="node-title-group">
          <div className="node-icon-badge">
            <MessageSquare size={14} />
          </div>
          <span className="node-title">{nodeData.name || 'Mensaje'}</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          <span className="node-tag badge-info">MENSAJE</span>
          <NodeHeaderActions nodeKey={nodeData.node_key} nodeData={nodeData} />
        </div>
      </div>

      <div className="node-body">
        {text ? (
          <div className="node-preview-text">
            {text}
          </div>
        ) : (
          <div style={{ color: '#80918c', fontStyle: 'italic', fontSize: '0.75rem' }}>
            Sin texto configurado...
          </div>
        )}

        {nodeData.isCollapsed && (
          <div className="node-collapsed-notice">
            Rama contraída ({nodeData.collapsedCount || 0} bloques ocultos)
          </div>
        )}

        {nodeData.hasError && (
          <div className="node-error-badge">
            <AlertTriangle size={12} /> Revisar configuración o salidas
          </div>
        )}
      </div>

      {/* Single output handle */}
      <Handle
        type="source"
        position={Position.Bottom}
        id="output"
        isConnectable={!nodeData.isReadOnly}
      />
    </div>
  );
});

MessageNode.displayName = 'MessageNode';
