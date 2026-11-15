import React, { memo } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import { CheckCircle2, AlertTriangle } from 'lucide-react';
import type { EditorNodeData } from '../adapter';
import { NodeHeaderActions } from './NodeHeaderActions';

export const EndNode: React.FC<NodeProps<any>> = memo(({ data, selected }) => {
  const nodeData = data as EditorNodeData;
  const message = nodeData.configuration?.message || '';

  return (
    <div
      className={`custom-node custom-node-end ${selected ? 'selected' : ''} ${
        nodeData.hasError ? 'has-error' : ''
      } ${nodeData.isHighlighted ? 'highlighted-node' : ''}`}
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
            <CheckCircle2 size={14} />
          </div>
          <span className="node-title">{nodeData.name || 'Fin de flujo'}</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          <span className="node-tag badge-secondary">FIN</span>
          <NodeHeaderActions nodeKey={nodeData.node_key} nodeData={nodeData} />
        </div>
      </div>

      <div className="node-body">
        <div style={{ fontSize: '0.75rem', color: '#61726d', fontWeight: 600, marginBottom: '0.35rem' }}>
          Cierra la ejecución del flujo
        </div>

        {message ? (
          <div className="node-preview-text">
            {message}
          </div>
        ) : (
          <div style={{ color: '#80918c', fontStyle: 'italic', fontSize: '0.75rem' }}>
            Mensaje de cierre opcional...
          </div>
        )}

        {nodeData.hasError && (
          <div className="node-error-badge">
            <AlertTriangle size={12} /> Nodo terminal no debe tener salidas
          </div>
        )}
      </div>

      {/* Terminal node: NO output handles */}
    </div>
  );
});

EndNode.displayName = 'EndNode';
