import React, { memo, useState } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import { ImageIcon, ImageOff, AlertTriangle, Edit2 } from 'lucide-react';
import type { EditorNodeData } from '../adapter';
import { NodeHeaderActions } from './NodeHeaderActions';
import { resolveMediaUrl } from '../../../utils/media';

export const ImageNode: React.FC<NodeProps<any>> = memo(({ id, data, selected }) => {
  const nodeData = data as EditorNodeData;
  const config = nodeData.configuration || {};
  const url = config.url || '';
  const caption = config.caption || '';
  const fileName = config.file_name || '';

  const [isEditingTitle, setIsEditingTitle] = useState(false);
  const [tempTitle, setTempTitle] = useState('');
  const [hasLoadError, setHasLoadError] = useState(false);
  const [lastAttemptedUrl, setLastAttemptedUrl] = useState(url);

  if (url !== lastAttemptedUrl) {
    setLastAttemptedUrl(url);
    setHasLoadError(false);
  }

  const resolvedUrl = resolveMediaUrl(url);

  const currentTitle = nodeData.name || 'Imagen';

  const handleStartEdit = () => {
    if (nodeData.isReadOnly) return;
    setTempTitle(currentTitle);
    setIsEditingTitle(true);
  };

  const handleTitleSubmit = () => {
    setIsEditingTitle(false);
    const trimmed = tempTitle.trim() || 'Imagen';
    if (nodeData.onUpdateName) {
      nodeData.onUpdateName(trimmed);
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      handleTitleSubmit();
    } else if (e.key === 'Escape') {
      setIsEditingTitle(false);
    }
  };

  return (
    <div
      className={`custom-node custom-node-image ${selected ? 'selected' : ''} ${
        nodeData.hasError ? 'has-error' : ''
      } ${nodeData.isHighlighted ? 'highlighted-node' : ''} ${
        nodeData.isCollapsed ? 'node-collapsed' : ''
      }`}
      data-testid={`image-node-${id || nodeData.node_key}`}
    >
      {/* Top Handle (Input) */}
      <Handle
        type="target"
        position={Position.Top}
        id="input"
        isConnectable={!nodeData.isReadOnly}
      />

      <div className="node-header">
        <div className="node-title-group" style={{ flex: 1, minWidth: 0 }}>
          <div className="node-icon-badge" style={{ backgroundColor: '#cffafe', color: '#5d8f82' }}>
            <ImageIcon size={14} />
          </div>

          {isEditingTitle && !nodeData.isReadOnly ? (
            <input
              type="text"
              className="node-title-input"
              value={tempTitle}
              autoFocus
              onChange={(e) => setTempTitle(e.target.value)}
              onBlur={handleTitleSubmit}
              onKeyDown={handleKeyDown}
              onClick={(e) => e.stopPropagation()}
              style={{
                fontSize: '0.8rem',
                fontWeight: 600,
                border: '1px solid #5d8f82',
                borderRadius: '4px',
                padding: '1px 4px',
                outline: 'none',
                width: '100%',
                maxWidth: '130px',
                background: '#ffffff',
                color: '#263c38',
              }}
            />
          ) : (
            <div
              className="node-title-wrapper"
              style={{ display: 'flex', alignItems: 'center', gap: '4px', cursor: nodeData.isReadOnly ? 'default' : 'pointer', minWidth: 0 }}
              onDoubleClick={(e) => {
                e.stopPropagation();
                handleStartEdit();
              }}
              title={nodeData.isReadOnly ? currentTitle : 'Doble clic para editar título'}
            >
              <span
                className="node-title"
                style={{
                  whiteSpace: 'nowrap',
                  overflow: 'hidden',
                  textOverflow: 'ellipsis',
                  display: 'inline-block',
                }}
              >
                {currentTitle}
              </span>
              {!nodeData.isReadOnly && (
                <button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation();
                    handleStartEdit();
                  }}
                  style={{
                    background: 'transparent',
                    border: 'none',
                    padding: 0,
                    cursor: 'pointer',
                    color: '#80918c',
                    display: 'inline-flex',
                    alignItems: 'center',
                  }}
                  title="Editar título"
                >
                  <Edit2 size={10} />
                </button>
              )}
            </div>
          )}
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          <span className="node-tag badge-info" style={{ backgroundColor: '#e0f2fe', color: '#0369a1' }}>
            IMAGEN
          </span>
          <NodeHeaderActions nodeKey={nodeData.node_key} nodeData={nodeData} />
        </div>
      </div>

      <div className="node-body">
        {url ? (
          hasLoadError ? (
            <div
              className="node-image-error"
              data-testid="node-image-error"
              style={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                padding: '0.75rem',
                backgroundColor: '#fef2f2',
                border: '1px dashed #fca5a5',
                borderRadius: '6px',
                margin: '0.35rem 0',
                color: '#b91c1c',
                gap: '0.25rem',
                textAlign: 'center',
              }}
            >
              <ImageOff size={18} color="#ef4444" />
              <span style={{ fontSize: '0.7rem', color: '#b91c1c', fontWeight: 500 }}>
                No se pudo cargar la imagen
              </span>
            </div>
          ) : (
            <div
              className="node-image-container"
              style={{
                margin: '0.35rem 0',
                borderRadius: '6px',
                overflow: 'hidden',
                maxHeight: '8rem',
                backgroundColor: '#f4f7f5',
                border: '1px solid #d9e3de',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <img
                src={resolvedUrl}
                alt={caption || fileName || currentTitle}
                className="node-image-thumbnail max-h-32 w-full object-cover rounded-md"
                onError={() => setHasLoadError(true)}
                style={{
                  display: 'block',
                  width: '100%',
                  maxHeight: '8rem',
                  objectFit: 'cover',
                  borderRadius: '6px',
                }}
              />
            </div>
          )
        ) : (
          <div
            className="node-image-placeholder"
            style={{
              display: 'flex',
              flexDirection: 'column',
              alignItems: 'center',
              justifyContent: 'center',
              padding: '0.75rem',
              backgroundColor: '#f4f7f5',
              border: '1px dashed #d9e3de',
              borderRadius: '6px',
              margin: '0.35rem 0',
              color: '#80918c',
              gap: '0.25rem',
            }}
          >
            <ImageIcon size={18} color="#80918c" />
            <span style={{ fontSize: '0.7rem', fontStyle: 'italic' }}>Sin imagen configurada...</span>
          </div>
        )}

        {caption ? (
          <div
            className="node-preview-text"
            title={caption}
            style={{
              fontSize: '0.75rem',
              color: '#56736b',
              display: '-webkit-box',
              WebkitLineClamp: 2,
              WebkitBoxOrient: 'vertical',
              overflow: 'hidden',
              textOverflow: 'ellipsis',
              marginTop: '0.25rem',
              lineHeight: '1.3',
            }}
          >
            {caption}
          </div>
        ) : null}

        {nodeData.isCollapsed && (
          <div className="node-collapsed-notice">
            Rama contraída ({nodeData.collapsedCount || 0} bloques ocultos)
          </div>
        )}

        {nodeData.hasError && (
          <div className="node-error-badge">
            <AlertTriangle size={12} /> Revisar URL o conexiones
          </div>
        )}
      </div>

      {/* Bottom Handle (Output) */}
      <Handle
        type="source"
        position={Position.Bottom}
        id="output"
        isConnectable={!nodeData.isReadOnly}
      />
    </div>
  );
});

ImageNode.displayName = 'ImageNode';
