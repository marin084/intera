import React, { memo } from 'react';
import { Handle, Position, useEdges, type NodeProps } from '@xyflow/react';
import { Split, AlertTriangle } from 'lucide-react';
import type { EditorNodeData } from '../adapter';
import { NodeHeaderActions } from './NodeHeaderActions';

export const ConditionNode: React.FC<NodeProps<any>> = memo(({ id, data, selected }) => {
  const nodeData = data as EditorNodeData;
  const config = nodeData.configuration || {};
  const variable = config.variable || '';
  const operator = config.operator || 'equals';
  const value = config.value !== undefined ? String(config.value) : '';
  const isUnary = operator === 'exists' || operator === 'not_exists';

  // 1. Detección reactiva de conexiones en tiempo real
  let edges: any[] = [];
  try {
    // eslint-disable-next-line react-hooks/rules-of-hooks
    edges = useEdges();
  } catch {
    edges = [];
  }
  if ((!edges || edges.length === 0) && Array.isArray((nodeData as any).edges)) {
    edges = (nodeData as any).edges;
  }

  const nodeId = id || nodeData.node_key;
  const outgoing = (edges || []).filter(
    (e) => e && (e.source === nodeId || e.source === nodeData.node_key)
  );

  // 2. Normalización de identificadores de handles
  const isHandleTrue = (e: any) => {
    const handle = e.sourceHandle ?? (e.data as any)?.condition_handle ?? (e as any).source_handle;
    return handle !== null && handle !== undefined && String(handle).trim().toLowerCase() === 'true';
  };

  const isHandleFalse = (e: any) => {
    const handle = e.sourceHandle ?? (e.data as any)?.condition_handle ?? (e as any).source_handle;
    return handle !== null && handle !== undefined && String(handle).trim().toLowerCase() === 'false';
  };

  const hasTrueBranch = outgoing.some(isHandleTrue);
  const hasFalseBranch = outgoing.some(isHandleFalse);
  const isComplete = hasTrueBranch && hasFalseBranch;

  // 3. Eliminar el borde rojo y la advertencia si isComplete === true
  const showBranchWarning = !isComplete;

  return (
    <div
      className={`custom-node custom-node-condition ${selected ? 'selected' : ''} ${
        showBranchWarning ? 'has-error' : ''
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
            <Split size={14} />
          </div>
          <span className="node-title">{nodeData.name || 'Condición'}</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          <span className="node-tag badge-warning">CONDICIÓN</span>
          <NodeHeaderActions nodeKey={nodeData.node_key} nodeData={nodeData} />
        </div>
      </div>

      <div className="node-body">
        <div style={{ background: '#fffbeb', padding: '0.4rem 0.6rem', borderRadius: '4px', border: '1px solid #fef3c7', fontSize: '0.75rem', fontFamily: 'monospace' }}>
          <span style={{ color: '#b45309', fontWeight: 700 }}>${variable || '?'}</span>{' '}
          <span style={{ color: '#61726d' }}>{operator}</span>{' '}
          {!isUnary && <span style={{ color: '#263c38', fontWeight: 600 }}>"{value}"</span>}
        </div>

        {nodeData.isCollapsed && (
          <div className="node-collapsed-notice">
            Ramas contraídas ({nodeData.collapsedCount || 0} bloques ocultos)
          </div>
        )}

        {showBranchWarning && (
          <div className="node-error-badge">
            <AlertTriangle size={12} /> Requiere rama TRUE y rama FALSE
          </div>
        )}
      </div>

      {/* Two explicit condition outputs */}
      <div className="condition-handles-labels">
        <span className="condition-label-true">● TRUE</span>
        <span className="condition-label-false">FALSE ●</span>
      </div>

      <Handle
        type="source"
        position={Position.Bottom}
        id="true"
        className="handle-condition-true"
        style={{ left: '25%' }}
        isConnectable={!nodeData.isReadOnly}
      />

      <Handle
        type="source"
        position={Position.Bottom}
        id="false"
        className="handle-condition-false"
        style={{ left: '75%' }}
        isConnectable={!nodeData.isReadOnly}
      />
    </div>
  );
});

ConditionNode.displayName = 'ConditionNode';
