import React, { memo } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import { Play, AlertTriangle } from 'lucide-react';
import type { EditorNodeData } from '../adapter';

export const StartNode: React.FC<NodeProps<any>> = memo(({ data, selected }) => {
  const nodeData = data as EditorNodeData;

  return (
    <div
      className={`custom-node custom-node-start ${selected ? 'selected' : ''} ${
        nodeData.hasError ? 'has-error' : ''
      } ${nodeData.isHighlighted ? 'highlighted-node' : ''}`}
    >
      <div className="node-header">
        <div className="node-title-group">
          <div className="node-icon-badge">
            <Play size={14} fill="#5d8f82" />
          </div>
          <span className="node-title">{nodeData.name || 'Inicio'}</span>
        </div>
        <span className="node-tag badge-success">INICIO</span>
      </div>

      <div className="node-body">
        <div style={{ color: '#61726d', fontSize: '0.75rem' }}>
          Punto de entrada único de la conversación.
        </div>

        {nodeData.hasError && (
          <div className="node-error-badge">
            <AlertTriangle size={12} /> Requiere conexión de salida
          </div>
        )}
      </div>

      {/* Exactly one output handle at bottom */}
      <Handle
        type="source"
        position={Position.Bottom}
        id="output"
        isConnectable={!nodeData.isReadOnly}
      />
    </div>
  );
});

StartNode.displayName = 'StartNode';
