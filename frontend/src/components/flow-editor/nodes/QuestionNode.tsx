import React, { memo } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import { HelpCircle, AlertTriangle } from 'lucide-react';
import type { EditorNodeData } from '../adapter';
import { NodeHeaderActions } from './NodeHeaderActions';

export const QuestionNode: React.FC<NodeProps<any>> = memo(({ data, selected }) => {
  const nodeData = data as EditorNodeData;
  const config = nodeData.configuration || {};
  const text = config.text || '';
  const variable = config.variable || '';
  const inputType = config.input_type || 'text';
  const choicesCount = Array.isArray(config.choices) ? config.choices.length : 0;

  return (
    <div
      className={`custom-node custom-node-question ${selected ? 'selected' : ''} ${
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
            <HelpCircle size={14} />
          </div>
          <span className="node-title">{nodeData.name || 'Pregunta'}</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          <span className="node-tag badge-warning" style={{ background: '#f3e8ff', color: '#7e22ce' }}>PREGUNTA</span>
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
            ¿Qué deseas preguntar?
          </div>
        )}

        <div className="node-meta-chips">
          {variable ? (
            <span className="node-meta-chip" style={{ background: '#eaf1ee', color: '#263c38', fontWeight: 600 }}>
              ${variable}
            </span>
          ) : (
            <span className="node-meta-chip" style={{ background: '#fee2e2', color: '#991b1b' }}>
              Sin variable
            </span>
          )}

          <span className="node-meta-chip">
            {inputType === 'choice' ? `Opciones (${choicesCount})` : `Entrada: ${inputType}`}
          </span>
        </div>

        {nodeData.isCollapsed && (
          <div className="node-collapsed-notice">
            Rama contraída ({nodeData.collapsedCount || 0} bloques ocultos)
          </div>
        )}

        {nodeData.hasError && (
          <div className="node-error-badge">
            <AlertTriangle size={12} /> Revisar pregunta, variable o salida
          </div>
        )}
      </div>

      {/* Output handle */}
      <Handle
        type="source"
        position={Position.Bottom}
        id="output"
        isConnectable={!nodeData.isReadOnly}
      />
    </div>
  );
});

QuestionNode.displayName = 'QuestionNode';
