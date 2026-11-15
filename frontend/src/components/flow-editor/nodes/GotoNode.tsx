import React, { memo } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import { CornerDownRight, ExternalLink, AlertTriangle } from 'lucide-react';
import type { EditorNodeData } from '../adapter';
import { NodeHeaderActions } from './NodeHeaderActions';

export const GotoNode: React.FC<NodeProps<any>> = memo(({ data, selected }) => {
  const nodeData = data as EditorNodeData;
  const config = nodeData.configuration || {};
  const targetNodeKey = config.target_node_key || '';

  const handleJump = (e: React.MouseEvent) => {
    e.stopPropagation();
    if (targetNodeKey && (nodeData as any).onFocusBranch) {
      (nodeData as any).onFocusBranch(targetNodeKey);
    }
  };

  return (
    <div
      className={`custom-node custom-node-goto ${selected ? 'selected' : ''} ${
        nodeData.hasError ? 'has-error' : ''
      } ${nodeData.isHighlighted ? 'highlighted-node' : ''}`}
      style={{ borderLeft: '4px solid #5d8f82' }}
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
            <CornerDownRight size={14} />
          </div>
          <span className="node-title">{nodeData.name || 'Ir a Bloque'}</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          <span
            style={{
              padding: '0.15rem 0.4rem',
              borderRadius: '4px',
              fontSize: '0.65rem',
              fontWeight: 700,
              background: '#eaf1ee',
              color: '#456c61',
            }}
          >
            SALTO
          </span>
          <NodeHeaderActions nodeKey={nodeData.node_key} nodeData={nodeData} />
        </div>
      </div>

      <div className="node-body">
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            background: '#faf5ff',
            border: '1px solid #e9d5ff',
            borderRadius: '4px',
            padding: '0.35rem 0.5rem',
            fontSize: '0.75rem',
          }}
        >
          <span style={{ color: '#581c87', fontWeight: 600 }}>
            {targetNodeKey ? `→ ${targetNodeKey}` : 'Sin destino'}
          </span>
          {targetNodeKey && (
            <button
              type="button"
              className="btn btn-sm"
              style={{ padding: '0.1rem 0.35rem', fontSize: '0.7rem', display: 'flex', alignItems: 'center', gap: '0.2rem' }}
              onClick={handleJump}
              title="Centrar y ver bloque de destino en el lienzo"
            >
              <ExternalLink size={11} /> Ver
            </button>
          )}
        </div>

        {nodeData.hasError && (
          <div className="node-error-badge">
            <AlertTriangle size={12} /> Debes seleccionar un bloque de destino válido
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

GotoNode.displayName = 'GotoNode';
