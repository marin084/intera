import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import type { Flow } from '../types';
import { GitBranch, Plus, CheckCircle, Clock, Eye, Layers, Trash2, AlertTriangle, XCircle, BarChart3, Settings, Globe, Radio } from 'lucide-react';
import { useAuth } from '../context/useAuth';
import { ConfirmModal } from '../components/common/ConfirmModal';
import { DiscardChangesPanel } from '../components/common/DiscardChangesPanel';

interface FlowsViewProps {
  onOpenEditor?: (flowId: string, versionId?: string) => void;
  onViewAnalytics?: (flowId: string) => void;
}

const channelApplicabilityDetails: Record<string, { label: string; Icon?: React.ElementType; src?: string }> = {
  webchat: { label: 'Webchat', Icon: Globe },
  whatsapp: { label: 'WhatsApp', src: '/channels/whatsapp.svg' },
  facebook_messenger: { label: 'Messenger', src: '/channels/messenger.svg' },
  instagram: { label: 'Instagram', src: '/channels/instagram.svg' },
  telegram: { label: 'Telegram', src: '/channels/telegram.svg' },
};

const ChannelApplicabilityBadges: React.FC<{ channels?: string[] }> = ({ channels }) => {
  const applicableChannels = channels?.length ? channels : ['webchat'];

  return (
    <div className="flow-channel-badges" aria-label={`Canales aplicables: ${applicableChannels.map((channel) => channelApplicabilityDetails[channel]?.label || channel).join(', ')}`}>
      {applicableChannels.map((channel) => {
        const { label, Icon, src } = channelApplicabilityDetails[channel] || { label: channel, Icon: Radio };
        return (
          <span className="flow-channel-badge" key={channel} title={label} role="img" aria-label={label}>
            {src ? (
              <img className="flow-channel-brand-icon" src={src} alt="" aria-hidden="true" />
            ) : Icon ? (
              <Icon size={14} aria-hidden="true" />
            ) : null}
          </span>
        );
      })}
    </div>
  );
};

export const FlowsView: React.FC<FlowsViewProps> = ({ onOpenEditor, onViewAnalytics }) => {
  const { hasPermission } = useAuth();
  const [flows, setFlows] = useState<Flow[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [createFlowDirty, setCreateFlowDirty] = useState(false);
  const [confirmDiscardCreateFlow, setConfirmDiscardCreateFlow] = useState(false);
  const [editFlowDirty, setEditFlowDirty] = useState(false);
  const [confirmDiscardEditFlow, setConfirmDiscardEditFlow] = useState(false);
  const [selectedFlow, setSelectedFlow] = useState<any | null>(null);
  const [flowStatusFilter, setFlowStatusFilter] = useState<'all' | 'published' | 'draft'>('all');
  const [toastMessage, setToastMessage] = useState<{ message: string; type: 'success' | 'error' } | null>(null);
  const [rollbackTarget, setRollbackTarget] = useState<{ flowId: string; versionId: string } | null>(null);

  const showToast = (message: string, type: 'success' | 'error' = 'success') => {
    setToastMessage({ message, type });
    setTimeout(() => {
      setToastMessage((prev) => (prev?.message === message ? null : prev));
    }, 4500);
  };

  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [channelApplicability, setChannelApplicability] = useState<string[]>(['webchat']);
  const [submitting, setSubmitting] = useState(false);

  // Edit Flow Settings state
  const [editingFlow, setEditingFlow] = useState<Flow | null>(null);
  const [editFlowName, setEditFlowName] = useState('');
  const [editFlowDescription, setEditFlowDescription] = useState('');
  const [editFlowApplicability, setEditFlowApplicability] = useState<string[]>([]);
  const [editFlowSubmitting, setEditFlowSubmitting] = useState(false);
  const [editFlowError, setEditFlowError] = useState<string | null>(null);

  // Safe Deletion states
  const [deletingFlow, setDeletingFlow] = useState<Flow | null>(null);
  const [deleteConfirmName, setDeleteConfirmName] = useState('');
  const [isDeleting, setIsDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [deleteConversationsCount, setDeleteConversationsCount] = useState<number | null>(null);
  const [blockingChannels, setBlockingChannels] = useState<string[]>([]);
  const [flowVersionsCount, setFlowVersionsCount] = useState<number | null>(null);
  const [loadingDeleteDetails, setLoadingDeleteDetails] = useState(false);
  const [deletePanelViewport, setDeletePanelViewport] = useState<{ top: number; left: number; width: number; height: number } | null>(null);
  const [actionSuccessMessage, setActionSuccessMessage] = useState<string | null>(null);

  useEffect(() => {
    if (!deletingFlow) {
      setDeletePanelViewport(null);
      return;
    }

    const updateViewport = () => {
      const viewport = window.visualViewport;
      const nextViewport = {
        top: viewport?.offsetTop ?? 0,
        left: viewport?.offsetLeft ?? 0,
        width: viewport?.width ?? window.innerWidth,
        height: viewport?.height ?? window.innerHeight,
      };

      setDeletePanelViewport((current) =>
        current &&
        current.top === nextViewport.top &&
        current.left === nextViewport.left &&
        current.width === nextViewport.width &&
        current.height === nextViewport.height
          ? current
          : nextViewport
      );
    };

    updateViewport();
    const viewport = window.visualViewport;
    viewport?.addEventListener('resize', updateViewport);
    viewport?.addEventListener('scroll', updateViewport);
    window.addEventListener('resize', updateViewport);

    return () => {
      viewport?.removeEventListener('resize', updateViewport);
      viewport?.removeEventListener('scroll', updateViewport);
      window.removeEventListener('resize', updateViewport);
    };
  }, [deletingFlow]);

  const deletePanelViewportStyle: React.CSSProperties = deletePanelViewport
    ? {
        '--flow-delete-viewport-top': `${deletePanelViewport.top}px`,
        '--flow-delete-viewport-left': `${deletePanelViewport.left}px`,
        '--flow-delete-viewport-width': `${deletePanelViewport.width}px`,
        '--flow-delete-viewport-height': `${deletePanelViewport.height}px`,
      } as React.CSSProperties
    : {};

  const discardCreateFlow = () => {
    setConfirmDiscardCreateFlow(false);
    setCreateFlowDirty(false);
    setShowCreateModal(false);
    setName('');
    setDescription('');
    setChannelApplicability(['webchat']);
  };

  const requestCloseCreateFlow = () => {
    if (submitting) return;
    if (createFlowDirty) setConfirmDiscardCreateFlow(true);
    else discardCreateFlow();
  };

  const discardEditFlow = () => {
    setConfirmDiscardEditFlow(false);
    setEditFlowDirty(false);
    setEditingFlow(null);
    setEditFlowError(null);
  };

  const requestCloseEditFlow = () => {
    if (editFlowSubmitting) return;
    if (editFlowDirty) setConfirmDiscardEditFlow(true);
    else discardEditFlow();
  };

  const fetchFlows = React.useCallback(async () => {
    try {
      const res = await api.getFlows();
      setFlows(res.data);
    } catch (err: any) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let isMounted = true;
    api.getFlows()
      .then((res) => {
        if (isMounted) setFlows(res.data);
      })
      .catch((err) => {
        console.error(err);
      })
      .finally(() => {
        if (isMounted) setLoading(false);
      });

    return () => {
      isMounted = false;
    };
  }, []);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        if (showCreateModal) requestCloseCreateFlow();
        if (editingFlow) requestCloseEditFlow();
        else {
          setSelectedFlow(null);
          setDeletingFlow(null);
        }
      }
    };
    window.addEventListener('keydown', handleKeyDown);

    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [showCreateModal, createFlowDirty, submitting, editingFlow, editFlowDirty, editFlowSubmitting]);

  const handleOpenEditor = (flowId: string, versionId?: string) => {
    if (onOpenEditor) {
      onOpenEditor(flowId, versionId);
    } else {
      window.history.pushState(null, '', `/flows/${flowId}/editor${versionId ? `/${versionId}` : ''}`);
      window.dispatchEvent(new PopStateEvent('popstate'));
    }
  };

  const handleViewAnalytics = (flowId: string) => {
    if (onViewAnalytics) {
      onViewAnalytics(flowId);
    } else {
      window.history.pushState(null, '', `/flows/${flowId}/analytics`);
      window.dispatchEvent(new PopStateEvent('popstate'));
    }
  };

  const handleCreateFlow = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);

    try {
      const res = await api.createFlow({
        name,
        description,
        channel_applicability: channelApplicability.length > 0 ? channelApplicability : ['webchat'],
      });
      setShowCreateModal(false);
      setCreateFlowDirty(false);
      setConfirmDiscardCreateFlow(false);
      setName('');
      setDescription('');
      setChannelApplicability(['webchat']);
      fetchFlows();
      if (res.data?.id) {
        handleOpenEditor(res.data.id);
      }
    } catch (err: any) {
      showToast(err.message || 'Error al crear flujo.', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  const handleOpenEditFlow = (flow: Flow) => {
    setEditFlowDirty(false);
    setConfirmDiscardEditFlow(false);
    setEditingFlow(flow);
    setEditFlowName(flow.name);
    setEditFlowDescription(flow.description || '');
    setEditFlowApplicability(flow.channel_applicability?.length ? [...flow.channel_applicability] : ['webchat']);
    setEditFlowError(null);
  };

  const handleUpdateFlow = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingFlow) return;
    setEditFlowSubmitting(true);
    setEditFlowError(null);

    try {
      await api.updateFlow(editingFlow.id, {
        name: editFlowName,
        description: editFlowDescription,
        channel_applicability: editFlowApplicability.length > 0 ? editFlowApplicability : ['webchat'],
      });
      showToast('Ajustes del flujo actualizados exitosamente.', 'success');
      setEditingFlow(null);
      setEditFlowDirty(false);
      setConfirmDiscardEditFlow(false);
      await fetchFlows();
    } catch (err: any) {
      setEditFlowError(err.message || 'Error al actualizar el flujo.');
    } finally {
      setEditFlowSubmitting(false);
    }
  };

  const handleViewFlow = async (flowId: string) => {
    try {
      const res = await api.getFlow(flowId);
      setSelectedFlow(res.data);
    } catch (err: any) {
      showToast(err.message || 'Error al cargar detalles del flujo.', 'error');
    }
  };

  const canCreate = hasPermission('flows.create');
  const canPublish = hasPermission('flows.publish');
  const canEdit = hasPermission('flows.edit');
  const canDelete = hasPermission('flows.delete');

  const handleOpenDeleteModal = async (flow: Flow) => {
    setDeletingFlow(flow);
    setDeleteConfirmName('');
    setDeleteError(null);
    setDeleteConversationsCount(null);
    setBlockingChannels([]);
    setFlowVersionsCount(null);
    setLoadingDeleteDetails(true);

    try {
      const [channelsRes, versionsRes] = await Promise.allSettled([
        api.getChannels(),
        api.getFlowVersions(flow.id),
      ]);

      if (channelsRes.status === 'fulfilled' && channelsRes.value.data) {
        const linked = channelsRes.value.data
          .filter((c: any) => c.entry_flow_id === flow.id)
          .map((c: any) => c.name);
        setBlockingChannels(linked);
      }

      if (versionsRes.status === 'fulfilled' && versionsRes.value.data) {
        setFlowVersionsCount(versionsRes.value.data.length);
      }
    } catch (err: any) {
      console.error(err);
    } finally {
      setLoadingDeleteDetails(false);
    }
  };

  const handleDeleteFlow = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!deletingFlow) return;

    if (blockingChannels.length > 0) {
      setDeleteError(`No se puede eliminar este flujo porque está configurado como flujo de entrada en los siguientes canales: ${blockingChannels.join(', ')}. Desvincula el flujo primero.`);
      return;
    }

    if (deleteConfirmName.trim() !== deletingFlow.name.trim()) {
      setDeleteError('El nombre ingresado no coincide exactamente con el nombre del flujo.');
      return;
    }

    setIsDeleting(true);
    setDeleteError(null);

    try {
      const force = deleteConversationsCount !== null;
      const res = await api.deleteFlow(deletingFlow.id, force);
      setDeletingFlow(null);
      setDeleteConfirmName('');
      setDeleteConversationsCount(null);
      setBlockingChannels([]);
      setActionSuccessMessage(res.message || 'Flujo eliminado exitosamente.');
      setTimeout(() => {
        setActionSuccessMessage(null);
      }, 4000);
      await fetchFlows();
    } catch (err: any) {
      if (err.data?.blocking_channels && err.data.blocking_channels.length > 0) {
        setBlockingChannels(err.data.blocking_channels);
        setDeleteError(null);
      } else if (err.data?.requires_force) {
        setDeleteConversationsCount(err.data.conversations_count || 1);
        setDeleteError(null);
      } else {
        setDeleteError(err.message || 'Error al eliminar el flujo.');
      }
    } finally {
      setIsDeleting(false);
    }
  };

  const handlePublishVersion = async (flowId: string, versionId: string) => {
    try {
      const res = await api.publishFlowVersion(flowId, versionId);
      showToast(res.message, 'success');
      handleViewFlow(flowId);
      fetchFlows();
    } catch (err: any) {
      const errorMsg = err.data?.errors ? err.data.errors.join('\n') : err.message;
      showToast(`No se pudo publicar la versión: ${errorMsg}`, 'error');
    }
  };

  const handleRollbackVersion = async (flowId: string, versionId: string) => {
    setRollbackTarget({ flowId, versionId });
  };

  const executeRollbackVersion = async () => {
    if (!rollbackTarget) return;
    try {
      const res = await api.rollbackFlowVersion(rollbackTarget.flowId, rollbackTarget.versionId);
      showToast(res.message, 'success');
      handleViewFlow(rollbackTarget.flowId);
      fetchFlows();
    } catch (err: any) {
      showToast(`Error al ejecutar rollback: ${err.message}`, 'error');
    } finally {
      setRollbackTarget(null);
    }
  };

  const publishedCount = flows.filter((f) => f.status === 'published').length;
  const draftCount = flows.filter((f) => f.status === 'draft').length;

  const filteredFlows = flows.filter((f) => {
    if (flowStatusFilter === 'published') return f.status === 'published';
    if (flowStatusFilter === 'draft') return f.status === 'draft';
    return true;
  });

  return (
    <div>
      {actionSuccessMessage && (
        <div className="alert alert-success" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '1rem', background: '#eaf1ee', borderColor: '#a9c8bb', color: '#263c38' }}>
          <CheckCircle size={18} color="#5d8f82" />
          <span>{actionSuccessMessage}</span>
        </div>
      )}
      <div className="card">
        <div className="card-header">
          <div>
            <h2 className="card-title">Flujos Conversacionales</h2>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.875rem' }}>
              Definición y versionado inmutable de bots y árboles de decisión.
            </p>
          </div>
          {canCreate && (
            <button className="btn btn-primary" onClick={() => { setCreateFlowDirty(false); setConfirmDiscardCreateFlow(false); setShowCreateModal(true); }}>
              <Plus size={16} /> Crear Flujo
            </button>
          )}
        </div>

        {/* Quick Filter Tabs */}
        {!loading && flows.length > 0 && (
          <div style={{ display: 'flex', gap: '0.5rem', marginBottom: '1rem', paddingBottom: '0.75rem', borderBottom: '1px solid var(--border)' }}>
            <button
              className={`btn btn-sm ${flowStatusFilter === 'all' ? 'btn-primary' : 'btn-secondary'}`}
              onClick={() => setFlowStatusFilter('all')}
              style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
            >
              Todos <span className="badge" style={{ background: flowStatusFilter === 'all' ? 'rgba(255,255,255,0.25)' : '#d9e3de', color: flowStatusFilter === 'all' ? '#ffffff' : '#61726d', fontSize: '0.7rem' }}>{flows.length}</span>
            </button>
            <button
              className={`btn btn-sm ${flowStatusFilter === 'published' ? 'btn-primary' : 'btn-secondary'}`}
              onClick={() => setFlowStatusFilter('published')}
              style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
            >
              Publicados <span className="badge" style={{ background: flowStatusFilter === 'published' ? 'rgba(255,255,255,0.25)' : '#eaf1ee', color: flowStatusFilter === 'published' ? '#ffffff' : '#263c38', fontSize: '0.7rem' }}>{publishedCount}</span>
            </button>
            <button
              className={`btn btn-sm ${flowStatusFilter === 'draft' ? 'btn-primary' : 'btn-secondary'}`}
              onClick={() => setFlowStatusFilter('draft')}
              style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
            >
              Borradores <span className="badge" style={{ background: flowStatusFilter === 'draft' ? 'rgba(255,255,255,0.25)' : '#fef3c7', color: flowStatusFilter === 'draft' ? '#ffffff' : '#b45309', fontSize: '0.7rem' }}>{draftCount}</span>
            </button>
          </div>
        )}

        {loading ? (
          <p style={{ color: 'var(--text-muted)' }}>Cargando flujos...</p>
        ) : flows.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '3.5rem 1rem', color: 'var(--text-muted)' }}>
            <GitBranch size={44} style={{ opacity: 0.35, marginBottom: '0.75rem', color: '#5d8f82' }} />
            <h3 style={{ fontSize: '1.05rem', fontWeight: 600, color: '#35564f', marginBottom: '0.35rem' }}>No hay flujos creados aún</h3>
            <p style={{ fontSize: '0.85rem', maxWidth: '420px', margin: '0 auto 1.25rem' }}>
              Los flujos conversacionales permiten diseñar bots con nodos visuales (mensajes, preguntas, condiciones, peticiones HTTP y transferencias a humanos).
            </p>
            {canCreate && (
              <button className="btn btn-primary" onClick={() => { setCreateFlowDirty(false); setConfirmDiscardCreateFlow(false); setShowCreateModal(true); }}>
                <Plus size={16} /> Crear mi primer Flujo
              </button>
            )}
          </div>
        ) : filteredFlows.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '2.5rem 1rem', color: 'var(--text-muted)' }}>
            <p style={{ fontSize: '0.9rem' }}>No hay flujos con el filtro seleccionado ({flowStatusFilter === 'published' ? 'publicados' : 'borradores'}).</p>
            <button className="btn btn-secondary btn-sm" onClick={() => setFlowStatusFilter('all')}>
              Ver todos los flujos
            </button>
          </div>
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Nombre del Flujo</th>
                  <th>Estado</th>
                  <th>Versión Activa</th>
                  <th>Canales Aplicables</th>
                  <th>Creador</th>
                  <th>Última Actualización</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                {filteredFlows.map((f) => (
                  <tr key={f.id}>
                    <td>
                      <div style={{ fontWeight: 600 }}>{f.name}</div>
                      {f.description && (
                        <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>{f.description}</div>
                      )}
                    </td>
                    <td>
                      {f.status === 'published' ? (
                        <span className="badge badge-success" style={{ display: 'inline-flex', gap: '0.25rem' }}>
                          <CheckCircle size={12} /> Publicado
                        </span>
                      ) : (
                        <span className="badge badge-warning" style={{ display: 'inline-flex', gap: '0.25rem' }}>
                          <Clock size={12} /> Borrador
                        </span>
                      )}
                    </td>
                    <td>
                      {f.active_version_number ? (
                        <span className="badge badge-info">v{f.active_version_number}</span>
                      ) : (
                        <span style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>Sin publicar</span>
                      )}
                    </td>
                    <td>
                      <ChannelApplicabilityBadges channels={f.channel_applicability} />
                    </td>
                    <td style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>{f.created_by || 'Sistema'}</td>
                    <td style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>
                      {new Date(f.updated_at).toLocaleString()}
                    </td>
                    <td>
                      <div style={{ display: 'flex', gap: '0.35rem' }}>
                        <button
                          className="btn btn-primary btn-sm"
                          onClick={() => handleOpenEditor(f.id)}
                          title="Abrir constructor visual de flujo"
                        >
                          <GitBranch size={14} /> {canEdit ? 'Editar flujo' : 'Ver flujo'}
                        </button>
                        {canEdit && (
                          <button
                            className="btn btn-secondary btn-sm"
                            onClick={() => handleOpenEditFlow(f)}
                            title="Configurar nombre, descripción y canales compatibles"
                            data-testid={`edit-settings-${f.id}`}
                          >
                            <Settings size={14} /> Ajustes
                          </button>
                        )}
                        <button
                          className="btn btn-secondary btn-sm"
                          onClick={() => handleViewAnalytics(f.id)}
                          title="Ver métricas y analítica del flujo"
                          data-testid={`view-analytics-${f.id}`}
                        >
                          <BarChart3 size={14} /> Métricas
                        </button>
                        <button className="btn btn-secondary btn-sm" onClick={() => handleViewFlow(f.id)}>
                          <Eye size={14} /> Versiones
                        </button>
                        {canDelete && (
                          <button
                            className="btn btn-secondary btn-sm"
                            onClick={() => handleOpenDeleteModal(f)}
                            title="Eliminar flujo"
                            aria-label={`Eliminar flujo ${f.name}`}
                            style={{ color: '#ef4444', borderColor: '#fecaca' }}
                          >
                            <Trash2 size={14} />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Modal Crear Flujo */}
      {showCreateModal && (
        <div className="modal-overlay flow-panel-overlay" role="dialog" aria-modal="true" aria-labelledby="create-flow-panel-title">
          <div className="modal-content flow-side-panel">
            <div className="flow-side-panel-header">
              <h3 id="create-flow-panel-title" style={{ fontSize: '1.15rem', fontWeight: 600, margin: 0 }}>Crear Nuevo Flujo</h3>
              <button type="button" className="btn btn-secondary btn-sm" aria-label="Cerrar panel" onClick={requestCloseCreateFlow}>✕</button>
            </div>

            <form onSubmit={handleCreateFlow} onChangeCapture={() => setCreateFlowDirty(true)} className="flow-side-panel-form">
              <div className="flow-side-panel-body">
              <div className="form-group">
                <label className="form-label">Nombre del Flujo</label>
                <input
                  type="text"
                  className="form-input"
                  required
                  placeholder="Ej. Flujo de Atención y Calificación"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                />
              </div>

              <div className="form-group">
                <label className="form-label">Descripción</label>
                <textarea
                  className="form-textarea"
                  rows={3}
                  placeholder="Explica el objetivo de este árbol conversacional..."
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                />
              </div>

              <div className="form-group">
                <label className="form-label">Canales Compatibles</label>
                <div style={{ display: 'flex', gap: '1rem', marginTop: '0.4rem', flexWrap: 'wrap' }}>
                  <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer', fontSize: '0.85rem' }}>
                    <input
                      type="checkbox"
                      checked={channelApplicability.includes('webchat')}
                      onChange={(e) => {
                        const next = e.target.checked
                          ? [...channelApplicability, 'webchat']
                          : channelApplicability.filter((c) => c !== 'webchat');
                        setChannelApplicability(next);
                      }}
                    />
                    <span>Webchat</span>
                  </label>
                  <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer', fontSize: '0.85rem' }}>
                    <input
                      type="checkbox"
                      checked={channelApplicability.includes('whatsapp')}
                      onChange={(e) => {
                        const next = e.target.checked
                          ? [...channelApplicability, 'whatsapp']
                          : channelApplicability.filter((c) => c !== 'whatsapp');
                        setChannelApplicability(next);
                      }}
                    />
                    <span>WhatsApp</span>
                  </label>
                  <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer', fontSize: '0.85rem' }}>
                    <input
                      type="checkbox"
                      checked={channelApplicability.includes('facebook_messenger')}
                      onChange={(e) => {
                        const next = e.target.checked
                          ? [...channelApplicability, 'facebook_messenger']
                          : channelApplicability.filter((c) => c !== 'facebook_messenger');
                        setChannelApplicability(next);
                      }}
                    />
                    <span>Messenger</span>
                  </label>
                  <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer', fontSize: '0.85rem' }}>
                    <input
                      type="checkbox"
                      checked={channelApplicability.includes('instagram')}
                      onChange={(e) => {
                        const next = e.target.checked
                          ? [...channelApplicability, 'instagram']
                          : channelApplicability.filter((c) => c !== 'instagram');
                        setChannelApplicability(next);
                      }}
                    />
                    <span>Instagram</span>
                  </label>
                  <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer', fontSize: '0.85rem' }}>
                    <input
                      type="checkbox"
                      checked={channelApplicability.includes('telegram')}
                      onChange={(e) => {
                        const next = e.target.checked
                          ? [...channelApplicability, 'telegram']
                          : channelApplicability.filter((c) => c !== 'telegram');
                        setChannelApplicability(next);
                      }}
                    />
                    <span>Telegram</span>
                  </label>
                </div>
                <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                  Define en qué canales se podrá asignar este flujo como flujo inicial.
                </p>
              </div>

              </div>
              <div className="modal-footer flow-side-panel-footer">
                <button type="button" className="btn btn-secondary" onClick={requestCloseCreateFlow}>
                  Cancelar
                </button>
                <button type="submit" className="btn btn-primary" disabled={submitting}>
                  {submitting ? 'Creando...' : 'Crear Flujo'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Editar Ajustes del Flujo */}
      {editingFlow && (
        <div className="modal-overlay flow-panel-overlay" role="dialog" aria-modal="true" aria-labelledby="edit-flow-panel-title">
          <div className="modal-content flow-side-panel">
            <div className="flow-side-panel-header">
              <h3 id="edit-flow-panel-title" style={{ fontSize: '1.15rem', fontWeight: 600, margin: 0 }}>Ajustes del Flujo</h3>
              <button type="button" className="btn btn-secondary btn-sm" aria-label="Cerrar panel" onClick={requestCloseEditFlow}>✕</button>
            </div>

            <form onSubmit={handleUpdateFlow} onChangeCapture={() => setEditFlowDirty(true)} className="flow-side-panel-form">
              <div className="flow-side-panel-body">
                {editFlowError && (
                  <div className="alert alert-danger" style={{ marginBottom: '1rem', padding: '0.75rem', fontSize: '0.85rem' }}>
                    {editFlowError}
                  </div>
                )}
              <div className="form-group">
                <label className="form-label">Nombre del Flujo</label>
                <input
                  type="text"
                  className="form-input"
                  required
                  value={editFlowName}
                  onChange={(e) => setEditFlowName(e.target.value)}
                />
              </div>

              <div className="form-group">
                <label className="form-label">Descripción</label>
                <textarea
                  className="form-textarea"
                  rows={3}
                  value={editFlowDescription}
                  onChange={(e) => setEditFlowDescription(e.target.value)}
                />
              </div>

              <div className="form-group">
                <label className="form-label">Canales Compatibles</label>
                <div style={{ display: 'flex', gap: '1rem', marginTop: '0.4rem', flexWrap: 'wrap' }}>
                  <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer', fontSize: '0.85rem' }}>
                    <input
                      type="checkbox"
                      checked={editFlowApplicability.includes('webchat')}
                      onChange={(e) => {
                        const next = e.target.checked
                          ? [...editFlowApplicability, 'webchat']
                          : editFlowApplicability.filter((c) => c !== 'webchat');
                        setEditFlowApplicability(next);
                      }}
                    />
                    <span>Webchat</span>
                  </label>
                  <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer', fontSize: '0.85rem' }}>
                    <input
                      type="checkbox"
                      checked={editFlowApplicability.includes('whatsapp')}
                      onChange={(e) => {
                        const next = e.target.checked
                          ? [...editFlowApplicability, 'whatsapp']
                          : editFlowApplicability.filter((c) => c !== 'whatsapp');
                        setEditFlowApplicability(next);
                      }}
                    />
                    <span>WhatsApp</span>
                  </label>
                  <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer', fontSize: '0.85rem' }}>
                    <input
                      type="checkbox"
                      checked={editFlowApplicability.includes('facebook_messenger')}
                      onChange={(e) => {
                        const next = e.target.checked
                          ? [...editFlowApplicability, 'facebook_messenger']
                          : editFlowApplicability.filter((c) => c !== 'facebook_messenger');
                        setEditFlowApplicability(next);
                      }}
                    />
                    <span>Messenger</span>
                  </label>
                  <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer', fontSize: '0.85rem' }}>
                    <input
                      type="checkbox"
                      checked={editFlowApplicability.includes('instagram')}
                      onChange={(e) => {
                        const next = e.target.checked
                          ? [...editFlowApplicability, 'instagram']
                          : editFlowApplicability.filter((c) => c !== 'instagram');
                        setEditFlowApplicability(next);
                      }}
                    />
                    <span>Instagram</span>
                  </label>
                  <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer', fontSize: '0.85rem' }}>
                    <input
                      type="checkbox"
                      checked={editFlowApplicability.includes('telegram')}
                      onChange={(e) => {
                        const next = e.target.checked
                          ? [...editFlowApplicability, 'telegram']
                          : editFlowApplicability.filter((c) => c !== 'telegram');
                        setEditFlowApplicability(next);
                      }}
                    />
                    <span>Telegram</span>
                  </label>
                </div>
                <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                  Canales en los que este flujo puede asignarse como flujo inicial.
                </p>
              </div>

              </div>
              <div className="modal-footer flow-side-panel-footer">
                <button type="button" className="btn btn-secondary" onClick={requestCloseEditFlow}>
                  Cancelar
                </button>
                <button type="submit" className="btn btn-primary" disabled={editFlowSubmitting}>
                  {editFlowSubmitting ? 'Guardando...' : 'Guardar Ajustes'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Detalle de Versiones */}
      {selectedFlow && (
        <div className="modal-overlay flow-panel-overlay" role="dialog" aria-modal="true" aria-labelledby="flow-versions-panel-title">
          <div className="modal-content flow-side-panel">
            <div className="flow-side-panel-header">
              <div>
                <h3 id="flow-versions-panel-title" style={{ fontSize: '1.2rem', fontWeight: 600, margin: 0 }}>{selectedFlow.name}</h3>
                <p style={{ fontSize: '0.8rem', color: 'var(--text-muted)', margin: '0.2rem 0 0' }}>
                  Historial de versiones inmutables del flujo
                </p>
              </div>
              <button className="btn btn-secondary btn-sm" aria-label="Cerrar panel" onClick={() => setSelectedFlow(null)}>✕</button>
            </div>

            <div className="flow-side-panel-body">
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.75rem' }}>
                <Layers size={18} color="#5d8f82" />
                <span style={{ fontWeight: 600, fontSize: '0.9rem' }}>Versiones registradas:</span>
              </div>

              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
                {selectedFlow.versions?.map((ver: any) => (
                  <div
                    key={ver.id}
                    className="flow-version-card"
                    style={{
                      border: '1px solid var(--border)',
                      borderRadius: 'var(--radius)',
                      padding: '1rem',
                      background: ver.status === 'published' ? '#eaf1ee' : '#ffffff',
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                    }}
                  >
                    <div>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                        <span style={{ fontWeight: 700 }}>Versión {ver.version_number}</span>
                        {ver.id === selectedFlow.active_version_id ? (
                          <span className="badge badge-success">Activa</span>
                        ) : ver.status === 'published' ? (
                          <span className="badge badge-secondary">Histórica</span>
                        ) : (
                          <span className="badge badge-warning">Borrador</span>
                        )}
                      </div>
                      <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                        {ver.description || 'Sin notas de versión'}
                      </div>
                      <div style={{ fontSize: '0.75rem', color: '#80918c', marginTop: '0.25rem' }}>
                        {ver.published_at ? `Publicada el ${new Date(ver.published_at).toLocaleString()}` : 'No publicada'}
                      </div>
                    </div>

                    <div className="flow-version-actions" style={{ display: 'flex', gap: '0.4rem', alignItems: 'center' }}>
                      <button
                        className="btn btn-secondary btn-sm"
                        onClick={() => {
                          setSelectedFlow(null);
                          handleOpenEditor(selectedFlow.id, ver.id);
                        }}
                      >
                        {ver.status === 'published' ? 'Ver en constructor' : 'Editar en constructor'}
                      </button>

                      {ver.status !== 'published' && canPublish && (
                        <button
                          className="btn btn-primary btn-sm"
                          onClick={() => handlePublishVersion(selectedFlow.id, ver.id)}
                        >
                          Validar y Publicar
                        </button>
                      )}

                      {ver.status === 'published' && ver.id !== selectedFlow.active_version_id && canPublish && (
                        <button
                          className="btn btn-warning btn-sm"
                          style={{ background: '#fffbeb', color: '#b45309', borderColor: '#fde68a' }}
                          onClick={() => handleRollbackVersion(selectedFlow.id, ver.id)}
                          title="Restaurar esta versión como activa para nuevas conversaciones"
                        >
                          Restaurar (Rollback)
                        </button>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <div className="modal-footer flow-side-panel-footer">
              <button className="btn btn-secondary" onClick={() => setSelectedFlow(null)}>
                Cerrar
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal Eliminación Segura de Flujo */}
      {deletingFlow && (
        <div className="modal-overlay flow-delete-overlay" style={{ zIndex: 1100, ...deletePanelViewportStyle }}>
          <div className="modal-content side-panel-compact flow-delete-dialog">
            <div className="modal-header" style={{ borderBottom: '1px solid #fee2e2' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                <div style={{ background: '#fee2e2', borderRadius: '50%', padding: '0.4rem', display: 'flex' }}>
                  <Trash2 size={18} color="#dc2626" />
                </div>
                <div>
                  <h3 style={{ fontSize: '1.1rem', fontWeight: 600, color: '#991b1b', margin: 0 }}>
                    Eliminar Flujo Conversacional
                  </h3>
                  <span style={{ fontSize: '0.8rem', color: '#61726d' }}>
                    Confirmación de seguridad e integridad referencial
                  </span>
                </div>
              </div>
              <button
                className="btn btn-secondary btn-sm"
                onClick={() => setDeletingFlow(null)}
                disabled={isDeleting}
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleDeleteFlow}>
              <div className="flow-delete-body" style={{ display: 'flex', flexDirection: 'column', gap: '0.85rem' }}>
                {deleteError && (
                  <div className="alert alert-danger" style={{ background: '#fef2f2', borderColor: '#fca5a5', color: '#991b1b', fontSize: '0.85rem' }}>
                    {deleteError}
                  </div>
                )}

                {/* Resumen del Flujo */}
                <div style={{ background: '#f4f7f5', border: '1px solid #d9e3de', borderRadius: 'var(--radius)', padding: '0.75rem', fontSize: '0.85rem' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.35rem' }}>
                    <span style={{ color: '#61726d' }}>Nombre del Flujo:</span>
                    <strong style={{ color: '#35564f' }}>{deletingFlow.name}</strong>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.35rem' }}>
                    <span style={{ color: '#61726d' }}>Versión Activa:</span>
                    <span>
                      {deletingFlow.active_version_number ? (
                        <span className="badge badge-info" style={{ fontSize: '0.75rem' }}>v{deletingFlow.active_version_number}</span>
                      ) : (
                        <span style={{ color: '#80918c' }}>Sin publicar</span>
                      )}
                    </span>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span style={{ color: '#61726d' }}>Versiones Registradas:</span>
                    <span>
                      {loadingDeleteDetails ? (
                        <span style={{ color: '#80918c' }}>Consultando...</span>
                      ) : flowVersionsCount !== null ? (
                        <span className="badge badge-secondary" style={{ fontSize: '0.75rem' }}>{flowVersionsCount} versión(es)</span>
                      ) : (
                        <span style={{ color: '#80918c' }}>Desconocido</span>
                      )}
                    </span>
                  </div>
                </div>

                {/* Canal Vinculado Bloqueado */}
                {blockingChannels.length > 0 && (
                  <div className="alert alert-danger" style={{ background: '#fef2f2', borderColor: '#fca5a5', color: '#991b1b', fontSize: '0.85rem' }}>
                    <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start' }}>
                      <XCircle size={18} style={{ flexShrink: 0, marginTop: '2px', color: '#dc2626' }} />
                      <div>
                        <strong>Eliminación bloqueada:</strong> Este flujo está asignado como flujo de entrada en los siguientes canales:
                        <ul style={{ margin: '0.4rem 0 0.2rem 1.2rem', padding: 0 }}>
                          {blockingChannels.map((chName) => (
                            <li key={chName}><strong>{chName}</strong></li>
                          ))}
                        </ul>
                        <span style={{ fontSize: '0.8rem' }}>
                          Cambia o desvincula el flujo en esos canales antes de poder eliminarlo.
                        </span>
                      </div>
                    </div>
                  </div>
                )}

                {/* Advertencia de conversaciones asociadas */}
                {blockingChannels.length === 0 && deleteConversationsCount !== null && (
                  <div className="alert alert-danger" style={{ background: '#fef2f2', borderColor: '#fca5a5', color: '#991b1b', fontSize: '0.85rem' }}>
                    <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start' }}>
                      <AlertTriangle size={18} style={{ flexShrink: 0, marginTop: '2px', color: '#dc2626' }} />
                      <div>
                        <strong>Advertencia de integridad referencial:</strong> Este flujo tiene <strong>{deleteConversationsCount} conversación(es) activa(s) o asociada(s)</strong>.
                        Si confirmas la eliminación forzada, el flujo se desvinculará de las conversaciones y se destruirá permanentemente su historial.
                      </div>
                    </div>
                  </div>
                )}

                {blockingChannels.length === 0 && (
                  <>
                    <p style={{ margin: 0, fontSize: '0.85rem', color: '#61726d', lineHeight: 1.5 }}>
                      Esta acción es permanente y destruirá de forma atómica todas sus versiones, nodos, conexiones, sesiones de simulación y publicaciones asociadas.
                    </p>

                    <div>
                      <label htmlFor="confirm-flow-name-input" style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, color: '#35564f', marginBottom: '0.4rem' }}>
                        Para confirmar, escribe el nombre exacto del flujo (<span style={{ color: '#dc2626' }}>{deletingFlow.name}</span>):
                      </label>
                      <input
                        id="confirm-flow-name-input"
                        type="text"
                        className="form-control"
                        value={deleteConfirmName}
                        onChange={(e) => setDeleteConfirmName(e.target.value)}
                        placeholder={deletingFlow.name}
                        disabled={isDeleting}
                      />
                    </div>
                  </>
                )}
              </div>

              <div className="modal-footer" style={{ borderTop: '1px solid #d9e3de', paddingTop: '0.75rem' }}>
                <button
                  type="button"
                  className="btn btn-secondary"
                  onClick={() => setDeletingFlow(null)}
                  disabled={isDeleting}
                >
                  Cancelar
                </button>
                {blockingChannels.length === 0 && (
                  <button
                    type="submit"
                    className="btn btn-danger"
                    disabled={isDeleting || deleteConfirmName.trim() !== deletingFlow.name.trim()}
                    style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem' }}
                  >
                    <Trash2 size={15} />
                    <span>{isDeleting ? 'Eliminando...' : 'Eliminar flujo definitivamente'}</span>
                  </button>
                )}
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal de Confirmación para Rollback de Versión */}
      <ConfirmModal
        isOpen={Boolean(rollbackTarget)}
        onClose={() => setRollbackTarget(null)}
        onConfirm={executeRollbackVersion}
        title="¿Restaurar versión del flujo?"
        description="¿Deseas restaurar esta versión como la versión activa para nuevas conversaciones? Las conversaciones existentes mantendrán su versión sin verse afectadas."
        confirmText="Restaurar versión"
        cancelText="Cancelar"
        confirmVariant="primary"
        testId="confirm-rollback-modal"
      />

      {/* Styled Floating Toast */}
      {toastMessage && (
        <div
          data-testid="flows-toast-notification"
          style={{
            position: 'fixed',
            top: '1.5rem',
            right: '1.5rem',
            zIndex: 9999,
            backgroundColor: toastMessage.type === 'error' ? '#fef2f2' : '#eaf1ee',
            color: toastMessage.type === 'error' ? '#991b1b' : '#263c38',
            border: `1px solid ${toastMessage.type === 'error' ? '#fca5a5' : '#a9c8bb'}`,
            borderRadius: '10px',
            padding: '0.85rem 1.25rem',
            boxShadow: '0 10px 15px -3px rgba(0, 0, 0, 0.1)',
            display: 'flex',
            alignItems: 'center',
            gap: '0.5rem',
            maxWidth: '400px',
            animation: 'fadeIn 0.2s ease-out',
          }}
        >
          {toastMessage.type === 'error' ? <AlertTriangle size={18} /> : <CheckCircle size={18} />}
          <span style={{ fontSize: '0.875rem', fontWeight: 500 }}>{toastMessage.message}</span>
        </div>
      )}
      <DiscardChangesPanel
        isOpen={confirmDiscardCreateFlow}
        onContinueEditing={() => setConfirmDiscardCreateFlow(false)}
        onDiscard={discardCreateFlow}
      />
      <DiscardChangesPanel
        isOpen={confirmDiscardEditFlow}
        onContinueEditing={() => setConfirmDiscardEditFlow(false)}
        onDiscard={discardEditFlow}
      />
    </div>
  );
};
