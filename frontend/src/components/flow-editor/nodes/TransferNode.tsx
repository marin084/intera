import React, { memo } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import { Headphones, AlertTriangle } from 'lucide-react';
import type { EditorNodeData } from '../adapter';
import { NodeHeaderActions } from './NodeHeaderActions';

export const TransferNode: React.FC<NodeProps<any>> = memo(({ data, selected }) => {
  const nodeData = data as EditorNodeData;
  const message = nodeData.configuration?.message || '';

  return (
    <div
      className={`custom-node custom-node-transfer ${selected ? 'selected' : ''} ${
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
            <Headphones size={14} />
          </div>
          <span className="node-title">{nodeData.name || 'Transferir a agente'}</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          <span className="node-tag badge-info" style={{ background: '#cffafe', color: '#0e7490' }}>TRANSFER</span>
          <NodeHeaderActions nodeKey={nodeData.node_key} nodeData={nodeData} />
        </div>
      </div>

      <div className="node-body">
        <div style={{ fontSize: '0.75rem', color: '#5d8f82', fontWeight: 600, marginBottom: '0.35rem' }}>
          Deriva a cola de atención humana
        </div>

        {message ? (
          <div className="node-preview-text">
            {message}
          </div>
        ) : (
          <div style={{ color: '#80918c', fontStyle: 'italic', fontSize: '0.75rem' }}>
            Mensaje de aviso opcional...
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

TransferNode.displayName = 'TransferNode';
