import React from 'react';
import { ChevronDown, ChevronRight, GitBranch } from 'lucide-react';
import type { EditorNodeData } from '../adapter';

interface NodeHeaderActionsProps {
  nodeKey: string;
  nodeData: EditorNodeData;
}

export const NodeHeaderActions: React.FC<NodeHeaderActionsProps> = ({ nodeKey, nodeData }) => {
  const { isCollapsed, collapsedCount, onToggleCollapse, onFocusBranch, isContextInput } = nodeData;

  return (
    <div className="node-header-actions" style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
      {isContextInput && (
        <span
          className="badge"
          style={{
            fontSize: '0.65rem',
            padding: '0.1rem 0.35rem',
            background: '#eaf1ee',
            color: '#61726d',
            border: '1px solid #d9e3de',
          }}
          title="Nodo de contexto de entrada a esta rama"
        >
          Entrada
        </span>
      )}

      {onFocusBranch && (
        <button
          type="button"
          className="node-action-btn"
          onClick={(e) => {
            e.stopPropagation();
            onFocusBranch(nodeKey);
          }}
          title="Enfocar esta rama (Vista de rama)"
          style={{
            background: 'transparent',
            border: 'none',
            cursor: 'pointer',
            padding: '2px',
            borderRadius: '4px',
            color: '#61726d',
            display: 'flex',
            alignItems: 'center',
          }}
        >
          <GitBranch size={13} />
        </button>
      )}

      {onToggleCollapse && (
        <button
          type="button"
          className={`node-action-btn ${isCollapsed ? 'is-collapsed' : ''}`}
          onClick={(e) => {
            e.stopPropagation();
            onToggleCollapse(nodeKey);
          }}
          title={isCollapsed ? 'Expandir rama' : 'Contraer rama'}
          style={{
            background: isCollapsed ? '#eaf1ee' : 'transparent',
            border: isCollapsed ? '1px solid #a9c8bb' : 'none',
            cursor: 'pointer',
            padding: '2px 4px',
            borderRadius: '4px',
            color: isCollapsed ? '#5d8f82' : '#61726d',
            display: 'flex',
            alignItems: 'center',
            fontSize: '0.7rem',
            gap: '2px',
            fontWeight: 600,
          }}
        >
          {isCollapsed ? (
            <>
              <ChevronRight size={13} />
              {collapsedCount !== undefined && collapsedCount > 0 && <span>+{collapsedCount}</span>}
            </>
          ) : (
            <ChevronDown size={13} />
          )}
        </button>
      )}
    </div>
  );
};
