import React, { useState, useEffect } from 'react';
import {
  History,
  X,
  CheckCircle,
  Clock,
  Lock,
  RotateCcw,
  GitCompare,
  CopyPlus,
  Trash2,
  UploadCloud,
  Eye,
  Calendar,
  User,
  Layers,
} from 'lucide-react';
import type { FlowVersion, FlowPublication } from '../../types';

interface FlowVersionHistoryDrawerProps {
  flowName?: string;
  activeVersionId: string | null;
  currentVersionId: string | null;
  versions: FlowVersion[];
  publications?: FlowPublication[];
  isOpen: boolean;
  canPublish: boolean;
  canEdit: boolean;
  onClose: () => void;
  onSelectVersion: (versionId: string) => void;
  onCloneVersion: (versionId: string) => void;
  onPublishVersion: (versionId: string) => void;
  onRollbackVersion: (version: FlowVersion) => void;
  onCompareVersions: (sourceVersionId: string, targetVersionId: string) => void;
  onDeleteVersion?: (versionId: string) => void;
}

export const FlowVersionHistoryDrawer: React.FC<FlowVersionHistoryDrawerProps> = ({
  flowName,
  activeVersionId,
  currentVersionId,
  versions,
  publications = [],
  isOpen,
  canPublish,
  canEdit,
  onClose,
  onSelectVersion,
  onCloneVersion,
  onPublishVersion,
  onRollbackVersion,
  onCompareVersions,
  onDeleteVersion,
}) => {
  const [activeTab, setActiveTab] = useState<'versions' | 'audit'>('versions');

  useEffect(() => {
    if (!isOpen) return;
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const sortedVersions = [...versions].sort((a, b) => b.version_number - a.version_number);
  const activeVersion = versions.find((v) => v.id === activeVersionId);

  return (
    <div
      style={{
        position: 'fixed',
        top: 0,
        right: 0,
        bottom: 0,
        width: '460px',
        maxWidth: '100vw',
        background: '#ffffff',
        boxShadow: '-4px 0 24px rgba(0, 0, 0, 0.15)',
        zIndex: 1040,
        display: 'flex',
        flexDirection: 'column',
        animation: 'slideInRight 0.25s ease-out',
      }}
    >
      {/* Header */}
      <div
        style={{
          padding: '1.2rem 1.25rem',
          borderBottom: '1px solid var(--border)',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          background: '#f4f7f5',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
          <div style={{ background: '#eaf1ee', padding: '0.5rem', borderRadius: '8px', color: '#263c38' }}>
            <History size={20} />
          </div>
          <div>
            <h3 style={{ margin: 0, fontSize: '1.1rem', fontWeight: 700, color: '#263c38' }}>
              Historial de Versiones
            </h3>
            <p style={{ margin: 0, fontSize: '0.75rem', color: '#61726d' }}>
              {flowName ? flowName : 'Flujo conversacional'}
            </p>
          </div>
        </div>

        <button
          type="button"
          className="btn btn-secondary btn-sm"
          onClick={onClose}
          style={{ padding: '0.3rem 0.5rem' }}
        >
          <X size={16} />
        </button>
      </div>

      {/* Production Banner */}
      <div
        style={{
          background: activeVersion ? '#eaf1ee' : '#fffbeb',
          borderBottom: '1px solid var(--border)',
          padding: '0.75rem 1.25rem',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          {activeVersion ? (
            <CheckCircle size={16} color="#5d8f82" />
          ) : (
            <Clock size={16} color="#d97706" />
          )}
          <span style={{ fontSize: '0.8rem', fontWeight: 600, color: activeVersion ? '#263c38' : '#92400e' }}>
            {activeVersion
              ? `Producción Activa: v${activeVersion.version_number}`
              : 'Sin versión publicada en producción'}
          </span>
        </div>

        {/* Tab switch */}
        <div style={{ display: 'flex', background: '#d9e3de', borderRadius: '6px', padding: '2px', gap: '2px' }}>
          <button
            type="button"
            className="btn btn-sm"
            style={{
              padding: '0.2rem 0.6rem',
              fontSize: '0.75rem',
              background: activeTab === 'versions' ? '#ffffff' : 'transparent',
              fontWeight: activeTab === 'versions' ? 600 : 500,
              border: 'none',
              borderRadius: '4px',
            }}
            onClick={() => setActiveTab('versions')}
          >
            Versiones ({versions.length})
          </button>
          <button
            type="button"
            className="btn btn-sm"
            style={{
              padding: '0.2rem 0.6rem',
              fontSize: '0.75rem',
              background: activeTab === 'audit' ? '#ffffff' : 'transparent',
              fontWeight: activeTab === 'audit' ? 600 : 500,
              border: 'none',
              borderRadius: '4px',
            }}
            onClick={() => setActiveTab('audit')}
          >
            Auditoría ({publications.length})
          </button>
        </div>
      </div>

      {/* Body list */}
      <div style={{ flex: 1, overflowY: 'auto', padding: '1rem 1.25rem' }}>
        {activeTab === 'versions' && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.85rem' }}>
            {sortedVersions.map((ver) => {
              const isActive = ver.id === activeVersionId;
              const isCurrent = ver.id === currentVersionId;
              const isPublished = ver.status === 'published';

              return (
                <div
                  key={ver.id}
                  style={{
                    border: isCurrent ? '2px solid #5d8f82' : isActive ? '1px solid #a9c8bb' : '1px solid var(--border)',
                    borderRadius: '8px',
                    padding: '0.9rem',
                    background: isActive ? '#eaf1ee' : isPublished ? '#f4f7f5' : '#ffffff',
                    position: 'relative',
                    boxShadow: isCurrent ? '0 2px 8px rgba(59, 130, 246, 0.15)' : 'none',
                  }}
                >
                  {/* Top line badges */}
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '0.4rem' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                      <span style={{ fontSize: '0.95rem', fontWeight: 700, color: '#263c38' }}>
                        v{ver.version_number}
                      </span>
                      {isActive && (
                        <span className="badge badge-success" style={{ fontSize: '0.7rem', padding: '0.15rem 0.45rem' }}>
                          ● Activa en Producción
                        </span>
                      )}
                      {!isActive && isPublished && (
                        <span className="badge badge-secondary" style={{ fontSize: '0.7rem', padding: '0.15rem 0.45rem', display: 'inline-flex', gap: '0.2rem', alignItems: 'center' }}>
                          <Lock size={10} /> Histórica
                        </span>
                      )}
                      {!isPublished && (
                        <span className="badge badge-warning" style={{ fontSize: '0.7rem', padding: '0.15rem 0.45rem', display: 'inline-flex', gap: '0.2rem', alignItems: 'center' }}>
                          <Clock size={10} /> Borrador
                        </span>
                      )}
                    </div>

                    {isCurrent && (
                      <span style={{ fontSize: '0.7rem', color: '#5d8f82', fontWeight: 600, background: '#eaf1ee', padding: '2px 6px', borderRadius: '4px' }}>
                        En editor
                      </span>
                    )}
                  </div>

                  {/* Notes / Description */}
                  <p style={{ margin: '0 0 0.5rem 0', fontSize: '0.8rem', color: '#61726d' }}>
                    {ver.description || 'Sin notas de versión.'}
                  </p>

                  {/* Meta infos */}
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.75rem', fontSize: '0.725rem', color: '#61726d', marginBottom: '0.75rem' }}>
                    {ver.published_at && (
                      <span style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
                        <Calendar size={12} /> Pub: {new Date(ver.published_at).toLocaleDateString()}
                      </span>
                    )}
                    {ver.published_by && (
                      <span style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
                        <User size={12} /> {ver.published_by}
                      </span>
                    )}
                    <span style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
                      <Layers size={12} /> {ver.nodes_count ?? ver.nodes?.length ?? 0} bloques
                    </span>
                  </div>

                  {/* Action buttons bar */}
                  <div
                    style={{
                      borderTop: '1px solid #d9e3de',
                      paddingTop: '0.6rem',
                      display: 'flex',
                      flexWrap: 'wrap',
                      gap: '0.4rem',
                      alignItems: 'center',
                    }}
                  >
                    {!isCurrent && (
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        style={{ padding: '0.25rem 0.55rem', fontSize: '0.75rem' }}
                        onClick={() => onSelectVersion(ver.id)}
                        title="Cargar esta versión en el editor visual"
                      >
                        <Eye size={13} /> {isPublished ? 'Ver versión' : 'Editar borrador'}
                      </button>
                    )}

                    {canEdit && (
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        style={{ padding: '0.25rem 0.55rem', fontSize: '0.75rem' }}
                        onClick={() => onCloneVersion(ver.id)}
                        title="Crear un nuevo borrador clonando esta versión"
                      >
                        <CopyPlus size={13} /> Clonar
                      </button>
                    )}

                    {activeVersion && activeVersion.id !== ver.id && (
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        style={{ padding: '0.25rem 0.55rem', fontSize: '0.75rem' }}
                        onClick={() => onCompareVersions(ver.id, activeVersion.id)}
                        title="Comparar cambios contra la versión activa en producción"
                      >
                        <GitCompare size={13} /> Diff vs Activa
                      </button>
                    )}

                    {/* Rollback button: for historical published versions that are not currently active */}
                    {isPublished && !isActive && canPublish && (
                      <button
                        type="button"
                        className="btn btn-sm"
                        style={{
                          padding: '0.25rem 0.55rem',
                          fontSize: '0.75rem',
                          background: '#fff1f2',
                          color: '#e11d48',
                          border: '1px solid #fecdd3',
                          fontWeight: 600,
                        }}
                        onClick={() => onRollbackVersion(ver)}
                        title="Restaurar esta versión histórica como activa en producción"
                      >
                        <RotateCcw size={13} /> Restaurar (Rollback)
                      </button>
                    )}

                    {/* Publish draft button */}
                    {!isPublished && canPublish && (
                      <button
                        type="button"
                        className="btn btn-primary btn-sm"
                        style={{ padding: '0.25rem 0.55rem', fontSize: '0.75rem' }}
                        onClick={() => onPublishVersion(ver.id)}
                        title="Validar y publicar este borrador en producción"
                      >
                        <UploadCloud size={13} /> Publicar
                      </button>
                    )}

                    {/* Delete draft button */}
                    {!isPublished && onDeleteVersion && (
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        style={{ padding: '0.25rem 0.4rem', color: '#ef4444', marginLeft: 'auto' }}
                        onClick={() => onDeleteVersion(ver.id)}
                        title="Eliminar este borrador"
                      >
                        <Trash2 size={13} />
                      </button>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}

        {/* Audit / Publication log tab */}
        {activeTab === 'audit' && (
          <div>
            {publications.length === 0 ? (
              <p style={{ textAlign: 'center', color: '#61726d', fontSize: '0.85rem', padding: '2rem 1rem' }}>
                No hay eventos de publicación o rollback registrados aún.
              </p>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
                {publications.map((pub) => {
                  const isRollback = pub.action === 'rollback';
                  return (
                    <div
                      key={pub.id}
                      style={{
                        border: '1px solid var(--border)',
                        borderRadius: '6px',
                        padding: '0.75rem',
                        background: isRollback ? '#fff1f2' : '#eaf1ee',
                        borderLeft: isRollback ? '4px solid #e11d48' : '4px solid #5d8f82',
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.25rem' }}>
                        <span style={{ fontWeight: 700, fontSize: '0.85rem', color: isRollback ? '#9f1239' : '#263c38' }}>
                          {isRollback ? 'Rollback a v' + (pub.version_number ?? '') : 'Publicación v' + (pub.version_number ?? '')}
                        </span>
                        <span style={{ fontSize: '0.7rem', color: '#61726d' }}>
                          {new Date(pub.created_at).toLocaleString()}
                        </span>
                      </div>

                      {pub.notes && (
                        <p style={{ margin: '0 0 0.3rem 0', fontSize: '0.775rem', color: '#56736b' }}>
                          "{pub.notes}"
                        </p>
                      )}

                      <div style={{ fontSize: '0.7rem', color: '#61726d', display: 'flex', gap: '0.5rem' }}>
                        {pub.published_by && <span>Por: {pub.published_by}</span>}
                        {pub.previous_version_number && (
                          <span>(Previa: v{pub.previous_version_number})</span>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Footer */}
      <div
        style={{
          padding: '0.75rem 1.25rem',
          borderTop: '1px solid var(--border)',
          background: '#f4f7f5',
          display: 'flex',
          justifyContent: 'flex-end',
        }}
      >
        <button type="button" className="btn btn-secondary btn-sm" onClick={onClose}>
          Cerrar Historial
        </button>
      </div>
    </div>
  );
};
