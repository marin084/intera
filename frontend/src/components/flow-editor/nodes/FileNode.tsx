import React, { memo, useState } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import { FileText, AlertTriangle, Edit2, FileSpreadsheet, Presentation } from 'lucide-react';
import type { EditorNodeData } from '../adapter';
import { NodeHeaderActions } from './NodeHeaderActions';

function formatBytes(bytes?: number | null): string {
  if (!bytes || bytes <= 0) return '';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function getFileMeta(fileName: string, mimeType?: string) {
  const ext = fileName ? fileName.split('.').pop()?.toLowerCase() || '' : '';

  if (ext === 'pdf' || mimeType?.includes('pdf')) {
    return { ext: 'PDF', color: '#ef4444', bgColor: '#fef2f2', borderColor: '#fecaca', icon: FileText };
  }
  if (ext === 'doc' || ext === 'docx' || mimeType?.includes('word')) {
    return { ext: ext.toUpperCase() || 'DOC', color: '#5d8f82', bgColor: '#eaf1ee', borderColor: '#a9c8bb', icon: FileText };
  }
  if (ext === 'xls' || ext === 'xlsx' || ext === 'csv' || mimeType?.includes('sheet') || mimeType?.includes('excel') || mimeType?.includes('csv')) {
    return { ext: ext.toUpperCase() || 'XLS', color: '#5d8f82', bgColor: '#eaf1ee', borderColor: '#a9c8bb', icon: FileSpreadsheet };
  }
  if (ext === 'ppt' || ext === 'pptx' || mimeType?.includes('presentation') || mimeType?.includes('powerpoint')) {
    return { ext: ext.toUpperCase() || 'PPT', color: '#f97316', bgColor: '#fff7ed', borderColor: '#fed7aa', icon: Presentation };
  }
  if (ext === 'txt' || mimeType?.includes('text/plain')) {
    return { ext: 'TXT', color: '#61726d', bgColor: '#f4f7f5', borderColor: '#d9e3de', icon: FileText };
  }

  return { ext: ext ? ext.toUpperCase() : 'DOC', color: '#5d8f82', bgColor: '#f0fdfa', borderColor: '#99f6e4', icon: FileText };
}

export const FileNode: React.FC<NodeProps<any>> = memo(({ id, data, selected }) => {
  const nodeData = data as EditorNodeData;
  const config = nodeData.configuration || {};
  const url = config.url || '';
  const caption = config.caption || '';
  const fileName = config.file_name || (url ? url.split('/').pop() : '') || '';
  const mimeType = config.mime_type || '';
  const sizeBytes = config.size_bytes;

  const [isEditingTitle, setIsEditingTitle] = useState(false);
  const [tempTitle, setTempTitle] = useState('');

  const currentTitle = nodeData.name || 'Documento';
  const fileMeta = getFileMeta(fileName, mimeType);
  const IconComponent = fileMeta.icon;
  const formattedSize = formatBytes(sizeBytes);

  const handleStartEdit = () => {
    if (nodeData.isReadOnly) return;
    setTempTitle(currentTitle);
    setIsEditingTitle(true);
  };

  const handleTitleSubmit = () => {
    setIsEditingTitle(false);
    const trimmed = tempTitle.trim() || 'Documento';
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
      className={`custom-node custom-node-file ${selected ? 'selected' : ''} ${
        nodeData.hasError ? 'has-error' : ''
      } ${nodeData.isHighlighted ? 'highlighted-node' : ''} ${
        nodeData.isCollapsed ? 'node-collapsed' : ''
      }`}
      data-testid={`file-node-${id || nodeData.node_key}`}
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
          <div className="node-icon-badge" style={{ backgroundColor: '#ccfbf1', color: '#5d8f82' }}>
            <FileText size={14} />
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
          <span className="node-tag badge-info" style={{ backgroundColor: '#ccfbf1', color: '#0f766e' }}>
            ARCHIVO
          </span>
          <NodeHeaderActions nodeKey={nodeData.node_key} nodeData={nodeData} />
        </div>
      </div>

      <div className="node-body">
        {url ? (
          <div
            className="node-file-card"
            data-testid="node-file-card"
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '0.5rem',
              padding: '0.5rem 0.6rem',
              margin: '0.35rem 0',
              backgroundColor: '#f4f7f5',
              border: `1px solid ${fileMeta.borderColor}`,
              borderRadius: '6px',
            }}
          >
            <div
              style={{
                width: '28px',
                height: '28px',
                borderRadius: '5px',
                backgroundColor: fileMeta.bgColor,
                color: fileMeta.color,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                flexShrink: 0,
              }}
            >
              <IconComponent size={16} />
            </div>

            <div style={{ flex: 1, minWidth: 0 }}>
              <div
                style={{
                  fontSize: '0.75rem',
                  fontWeight: 600,
                  color: '#35564f',
                  overflow: 'hidden',
                  textOverflow: 'ellipsis',
                  whiteSpace: 'nowrap',
                }}
                title={fileName || 'Documento'}
              >
                {fileName || 'Documento adjunto'}
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', marginTop: '1px' }}>
                <span
                  style={{
                    fontSize: '0.65rem',
                    fontWeight: 700,
                    color: fileMeta.color,
                    backgroundColor: fileMeta.bgColor,
                    padding: '0px 4px',
                    borderRadius: '3px',
                  }}
                >
                  {fileMeta.ext}
                </span>
                {formattedSize && (
                  <span style={{ fontSize: '0.65rem', color: '#61726d' }}>
                    {formattedSize}
                  </span>
                )}
              </div>
            </div>
          </div>
        ) : (
          <div
            className="node-file-placeholder"
            data-testid="node-file-placeholder"
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
            <FileText size={18} color="#80918c" />
            <span style={{ fontSize: '0.7rem', fontStyle: 'italic' }}>Sin documento configurado...</span>
          </div>
        )}

        {caption ? (
          <div
            className="node-preview-text"
            data-testid="node-file-caption"
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

FileNode.displayName = 'FileNode';
