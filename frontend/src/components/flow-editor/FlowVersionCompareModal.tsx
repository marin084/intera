import React, { useState, useEffect } from 'react';
import { GitCompare, X, RefreshCw, AlertCircle, ArrowRight, FileText } from 'lucide-react';
import { api } from '../../services/api';
import type { FlowVersion, FlowComparisonResult } from '../../types';

interface FlowVersionCompareModalProps {
  flowId: string;
  versions: FlowVersion[];
  initialSourceVersionId?: string;
  initialTargetVersionId?: string;
  isOpen: boolean;
  onClose: () => void;
}

export const FlowVersionCompareModal: React.FC<FlowVersionCompareModalProps> = (props) => {
  if (!props.isOpen) return null;
  return <FlowVersionCompareModalContent {...props} />;
};

const FlowVersionCompareModalContent: React.FC<FlowVersionCompareModalProps> = ({
  flowId,
  versions,
  initialSourceVersionId,
  initialTargetVersionId,
  onClose,
}) => {
  const defaultSrcId = initialSourceVersionId || (versions.length >= 2 ? versions[versions.length - 1]?.id : versions[0]?.id) || '';
  const defaultTgtId = initialTargetVersionId || (versions.length >= 2 ? versions[0]?.id : versions[0]?.id) || '';

  const [sourceVersionId, setSourceVersionId] = useState<string>(defaultSrcId);
  const [targetVersionId, setTargetVersionId] = useState<string>(defaultTgtId);
  const [loading, setLoading] = useState(() => Boolean(defaultSrcId && defaultTgtId && defaultSrcId !== defaultTgtId));
  const [rawDiff, setDiff] = useState<FlowComparisonResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  const isSameVersion = Boolean(sourceVersionId && targetVersionId && sourceVersionId === targetVersionId);
  const effectiveError = isSameVersion ? 'Selecciona dos versiones distintas para comparar.' : error;
  const diff = isSameVersion ? null : rawDiff;

  const handleSourceChange = (newSrcId: string) => {
    setSourceVersionId(newSrcId);
    if (newSrcId && targetVersionId && newSrcId !== targetVersionId) {
      setLoading(true);
    }
  };

  const handleTargetChange = (newTgtId: string) => {
    setTargetVersionId(newTgtId);
    if (sourceVersionId && newTgtId && sourceVersionId !== newTgtId) {
      setLoading(true);
    }
  };

  useEffect(() => {
    if (!sourceVersionId || !targetVersionId || sourceVersionId === targetVersionId) {
      return;
    }

    let ignore = false;
    api.compareFlowVersions(flowId, sourceVersionId, targetVersionId)
      .then((res) => {
        if (!ignore) {
          setDiff(res.data);
          setError(null);
          setLoading(false);
        }
      })
      .catch((err: any) => {
        if (!ignore) {
          setError(err.message || 'Error al comparar versiones');
          setDiff(null);
          setLoading(false);
        }
      });

    return () => {
      ignore = true;
    };
  }, [flowId, sourceVersionId, targetVersionId]);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [onClose]);

  return (
    <div className="modal-overlay flow-panel-overlay" style={{ zIndex: 1100 }}>
      <div className="modal-content side-panel-wide flow-side-panel">
        {/* Header */}
        <div className="flow-side-panel-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            <div style={{ background: '#eaf1ee', padding: '0.5rem', borderRadius: '8px', color: '#5d8f82' }}>
              <GitCompare size={20} />
            </div>
            <div>
              <h3 style={{ margin: 0, fontSize: '1.2rem', fontWeight: 600 }}>Comparador Semántico de Versiones</h3>
              <p style={{ margin: 0, fontSize: '0.8rem', color: '#61726d' }}>
                Diferencias estructurales y conversacionales entre versiones
              </p>
            </div>
          </div>
          <button type="button" className="btn btn-secondary btn-sm" onClick={onClose}>
            <X size={16} />
          </button>
        </div>

        <div className="flow-side-panel-body flow-version-compare-body">
        {/* Version selectors bar */}
        <div
          style={{
            background: '#f4f7f5',
            border: '1px solid var(--border)',
            borderRadius: '8px',
            padding: '0.75rem 1rem',
            margin: '1rem 0',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: '1rem',
          }}
        >
          <div style={{ flex: 1 }}>
            <label style={{ display: 'block', fontSize: '0.75rem', fontWeight: 600, color: '#61726d', marginBottom: '0.25rem' }}>
              Versión Base (Origen)
            </label>
            <select
              className="form-select"
              style={{ width: '100%', fontSize: '0.85rem' }}
              value={sourceVersionId}
              onChange={(e) => handleSourceChange(e.target.value)}
            >
              {versions.map((v) => (
                <option key={v.id} value={v.id}>
                  v{v.version_number} — {v.status === 'published' ? 'Publicada' : 'Borrador'} {v.is_active ? '(Activa)' : ''}
                </option>
              ))}
            </select>
          </div>

          <div style={{ color: '#80918c', display: 'flex', alignItems: 'center', paddingTop: '1rem' }}>
            <ArrowRight size={20} />
          </div>

          <div style={{ flex: 1 }}>
            <label style={{ display: 'block', fontSize: '0.75rem', fontWeight: 600, color: '#61726d', marginBottom: '0.25rem' }}>
              Versión Comparada (Destino)
            </label>
            <select
              className="form-select"
              style={{ width: '100%', fontSize: '0.85rem' }}
              value={targetVersionId}
              onChange={(e) => handleTargetChange(e.target.value)}
            >
              {versions.map((v) => (
                <option key={v.id} value={v.id}>
                  v{v.version_number} — {v.status === 'published' ? 'Publicada' : 'Borrador'} {v.is_active ? '(Activa)' : ''}
                </option>
              ))}
            </select>
          </div>
        </div>

        {/* Content body */}
        <div style={{ overflowY: 'auto', flex: 1, minHeight: 0, paddingRight: '0.25rem' }}>
          {loading && (
            <div style={{ textAlign: 'center', padding: '3rem 1rem', color: '#61726d' }}>
              <RefreshCw className="spin" size={24} style={{ marginBottom: '0.5rem' }} />
              <p style={{ margin: 0 }}>Analizando diferencias semánticas del grafo...</p>
            </div>
          )}

          {effectiveError && !loading && (
            <div
              style={{
                background: '#fef2f2',
                border: '1px solid #fee2e2',
                borderRadius: '8px',
                padding: '1rem',
                color: '#991b1b',
                display: 'flex',
                gap: '0.5rem',
                alignItems: 'center',
              }}
            >
              <AlertCircle size={18} />
              <span style={{ fontSize: '0.85rem' }}>{effectiveError}</span>
            </div>
          )}

          {diff && !loading && (
            <div>
              {/* Summary Banner */}
              {diff.summary && (
                <div style={{ marginBottom: '1rem', padding: '0.65rem 0.9rem', background: '#f4f7f5', border: '1px solid var(--border)', borderRadius: '6px', fontSize: '0.85rem', color: '#35564f', fontWeight: 600 }}>
                  {diff.summary}
                </div>
              )}

              {/* Summary KPIs */}
              <div
                style={{
                  display: 'grid',
                  gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))',
                  gap: '0.75rem',
                  marginBottom: '1rem',
                }}
              >
                <div style={{ background: '#eaf1ee', border: '1px solid #a9c8bb', borderRadius: '8px', padding: '0.6rem 0.8rem' }}>
                  <div style={{ fontSize: '0.75rem', color: '#263c38', fontWeight: 600 }}>Nodos Nuevos</div>
                  <div style={{ fontSize: '1.25rem', fontWeight: 700, color: '#263c38' }}>+{diff.stats.nodes_added}</div>
                </div>

                <div style={{ background: '#fef2f2', border: '1px solid #fecaca', borderRadius: '8px', padding: '0.6rem 0.8rem' }}>
                  <div style={{ fontSize: '0.75rem', color: '#991b1b', fontWeight: 600 }}>Nodos Eliminados</div>
                  <div style={{ fontSize: '1.25rem', fontWeight: 700, color: '#b91c1c' }}>-{diff.stats.nodes_removed}</div>
                </div>

                <div style={{ background: '#eaf1ee', border: '1px solid #a9c8bb', borderRadius: '8px', padding: '0.6rem 0.8rem' }}>
                  <div style={{ fontSize: '0.75rem', color: '#456c61', fontWeight: 600 }}>Nodos Modificados</div>
                  <div style={{ fontSize: '1.25rem', fontWeight: 700, color: '#5d8f82' }}>~{diff.stats.nodes_modified}</div>
                </div>

                <div style={{ background: '#f4f7f5', border: '1px solid #d9e3de', borderRadius: '8px', padding: '0.6rem 0.8rem' }}>
                  <div style={{ fontSize: '0.75rem', color: '#61726d', fontWeight: 600 }}>Conexiones (+/-)</div>
                  <div style={{ fontSize: '1.25rem', fontWeight: 700, color: '#56736b' }}>
                    +{diff.stats.edges_added} / -{diff.stats.edges_removed}
                  </div>
                </div>

                <div style={{ background: '#faf5ff', border: '1px solid #e9d5ff', borderRadius: '8px', padding: '0.6rem 0.8rem' }}>
                  <div style={{ fontSize: '0.75rem', color: '#6b21a8', fontWeight: 600 }}>Variables</div>
                  <div style={{ fontSize: '1.25rem', fontWeight: 700, color: '#7e22ce' }}>{diff.stats.variables_changed}</div>
                </div>
              </div>

              {/* Highlights feed */}
              <div style={{ marginBottom: '1.25rem' }}>
                <h4 style={{ fontSize: '0.85rem', fontWeight: 700, color: '#56736b', marginBottom: '0.5rem', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                  <FileText size={15} color="#5d8f82" /> Resumen de Cambios Funcionales:
                </h4>
                {diff.highlights.length > 0 ? (
                  <ul style={{ margin: 0, paddingLeft: '1.2rem', fontSize: '0.825rem', color: '#56736b', lineHeight: 1.6 }}>
                    {diff.highlights.map((h, i) => (
                      <li key={i}>{h}</li>
                    ))}
                  </ul>
                ) : (
                  <p style={{ fontSize: '0.825rem', color: '#61726d', margin: 0 }}>
                    No se detectaron diferencias funcionales entre estas dos versiones.
                  </p>
                )}
              </div>

              {/* Detailed Modified Nodes */}
              {diff.nodes?.modified && diff.nodes.modified.length > 0 && (
                <div style={{ marginBottom: '1.25rem' }}>
                  <h4 style={{ fontSize: '0.85rem', fontWeight: 700, color: '#456c61', marginBottom: '0.5rem' }}>
                    Detalle de Bloques Modificados ({diff.nodes.modified.length})
                  </h4>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                    {diff.nodes.modified.map((node) => (
                      <div
                        key={node.node_key}
                        style={{
                          border: '1px solid #a9c8bb',
                          borderRadius: '6px',
                          padding: '0.6rem 0.8rem',
                          background: '#f4f7f5',
                        }}
                      >
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.3rem' }}>
                          <span style={{ fontWeight: 600, fontSize: '0.85rem', color: '#263c38' }}>
                            {node.name} <code style={{ fontSize: '0.75rem', color: '#61726d' }}>({node.node_key})</code>
                          </span>
                          <span className="badge badge-info" style={{ fontSize: '0.7rem' }}>{node.type}</span>
                        </div>
                        <ul style={{ margin: 0, paddingLeft: '1.2rem', fontSize: '0.8rem', color: '#61726d' }}>
                          {node.changes.map((c, idx) => (
                            <li key={idx}>{c}</li>
                          ))}
                        </ul>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Detailed Added Nodes */}
              {diff.nodes?.added && diff.nodes.added.length > 0 && (
                <div style={{ marginBottom: '1.25rem' }}>
                  <h4 style={{ fontSize: '0.85rem', fontWeight: 700, color: '#263c38', marginBottom: '0.5rem' }}>
                    Bloques Nuevos en v{diff.target_version.version_number} ({diff.nodes.added.length})
                  </h4>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
                    {diff.nodes.added.map((node) => (
                      <div
                        key={node.node_key}
                        style={{
                          border: '1px solid #a9c8bb',
                          borderRadius: '6px',
                          padding: '0.5rem 0.8rem',
                          background: '#eaf1ee',
                          display: 'flex',
                          justifyContent: 'space-between',
                          alignItems: 'center',
                        }}
                      >
                        <span style={{ fontSize: '0.85rem', fontWeight: 600, color: '#263c38' }}>
                          {node.name} <code style={{ fontSize: '0.75rem', color: '#65a30d' }}>({node.node_key})</code>
                        </span>
                        <span className="badge badge-success" style={{ fontSize: '0.7rem' }}>{node.type}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Detailed Removed Nodes */}
              {diff.nodes?.removed && diff.nodes.removed.length > 0 && (
                <div style={{ marginBottom: '1rem' }}>
                  <h4 style={{ fontSize: '0.85rem', fontWeight: 700, color: '#b91c1c', marginBottom: '0.5rem' }}>
                    Bloques Eliminados ({diff.nodes.removed.length})
                  </h4>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
                    {diff.nodes.removed.map((node) => (
                      <div
                        key={node.node_key}
                        style={{
                          border: '1px solid #fecaca',
                          borderRadius: '6px',
                          padding: '0.5rem 0.8rem',
                          background: '#fef2f2',
                          display: 'flex',
                          justifyContent: 'space-between',
                          alignItems: 'center',
                        }}
                      >
                        <span style={{ fontSize: '0.85rem', fontWeight: 600, color: '#991b1b' }}>
                          {node.name} <code style={{ fontSize: '0.75rem', color: '#dc2626' }}>({node.node_key})</code>
                        </span>
                        <span className="badge badge-danger" style={{ fontSize: '0.7rem' }}>{node.type}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
        </div>

        {/* Footer */}
        <div className="flow-side-panel-footer">
          <button type="button" className="btn btn-secondary" onClick={onClose}>
            Cerrar Comparación
          </button>
        </div>
      </div>
    </div>
  );
};
