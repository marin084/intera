import React from 'react';
import {
  BaseEdge,
  EdgeLabelRenderer,
  getSmoothStepPath,
  type EdgeProps,
} from '@xyflow/react';
import { Plus, X } from 'lucide-react';

export interface ConexaEdgeData {
  condition_handle?: string | null;
  metadata?: Record<string, any>;
  isReadOnly?: boolean;
  onInsertNode?: (
    edgeId: string,
    source: string,
    target: string,
    sourceHandle?: string | null,
    clientPos?: { clientX: number; clientY: number }
  ) => void;
  onDeleteEdge?: (edgeId: string) => void;
}

export const ConexaEdge: React.FC<EdgeProps<any>> = ({
  id,
  source,
  target,
  sourceHandleId,
  sourceX,
  sourceY,
  targetX,
  targetY,
  sourcePosition,
  targetPosition,
  style = {},
  markerEnd,
  label,
  data,
}) => {
  const [edgePath, labelX, labelY] = getSmoothStepPath({
    sourceX,
    sourceY,
    sourcePosition,
    targetX,
    targetY,
    targetPosition,
    borderRadius: 8,
  });

  const isReadOnly = data?.isReadOnly ?? false;
  const onInsertNode = data?.onInsertNode;
  const onDeleteEdge = data?.onDeleteEdge;

  return (
    <>
      <BaseEdge path={edgePath} markerEnd={markerEnd} style={style} />
      <EdgeLabelRenderer>
        <div
          style={{
            position: 'absolute',
            transform: `translate(-50%, -50%) translate(${labelX}px,${labelY}px)`,
            pointerEvents: 'all',
            display: 'flex',
            alignItems: 'center',
            gap: '5px',
            zIndex: 10,
          }}
          className="nodrag nopan"
        >
          {label && (
            <span
              style={{
                fontSize: '10px',
                fontWeight: 700,
                padding: '2px 6px',
                borderRadius: '4px',
                background: '#ffffff',
                border: '1px solid #d9e3de',
                color: (style as any)?.stroke || '#61726d',
                boxShadow: '0 1px 3px rgba(0,0,0,0.08)',
                letterSpacing: '0.04em',
                userSelect: 'none',
              }}
            >
              {label}
            </span>
          )}
          {!isReadOnly && onInsertNode && (
            <button
              type="button"
              className="conexa-edge-add-btn"
              data-testid={`edge-add-btn-${id}`}
              onClick={(e) => {
                e.stopPropagation();
                onInsertNode(id, source, target, sourceHandleId, {
                  clientX: e.clientX,
                  clientY: e.clientY,
                });
              }}
              title="Insertar bloque entre esta conexión (+)"
              style={{
                width: '20px',
                height: '20px',
                borderRadius: '50%',
                background: '#ffffff',
                border: '1.5px solid #5d8f82',
                color: '#5d8f82',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                cursor: 'pointer',
                boxShadow: '0 1px 4px rgba(0,0,0,0.12)',
                padding: 0,
              }}
            >
              <Plus size={12} strokeWidth={2.5} />
            </button>
          )}
          {!isReadOnly && onDeleteEdge && (
            <button
              type="button"
              className="conexa-edge-del-btn"
              onClick={(e) => {
                e.stopPropagation();
                onDeleteEdge(id);
              }}
              title="Eliminar conexión"
              style={{
                width: '20px',
                height: '20px',
                borderRadius: '50%',
                background: '#ffffff',
                border: '1.5px solid #ef4444',
                color: '#dc2626',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                cursor: 'pointer',
                boxShadow: '0 1px 4px rgba(0,0,0,0.12)',
                padding: 0,
              }}
            >
              <X size={11} strokeWidth={2.5} />
            </button>
          )}
        </div>
      </EdgeLabelRenderer>
    </>
  );
};
