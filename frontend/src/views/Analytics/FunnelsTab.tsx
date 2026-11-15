import React, { useState, useEffect, useCallback } from 'react';
import { api } from '../../services/api';
import type {
  Flow,
  Channel,
  FlowAnalyticsData,
  FunnelAvailableStep,
  SequentialStepDefinition,
  SequentialFunnelStep,
  SavedFunnel,
} from '../../types';
import { FunnelContactsModal } from './FunnelContactsModal';
import { ConfirmModal } from '../../components/common/ConfirmModal';
import {
  GitBranch,
  Radio,
  Calendar,
  Filter,
  RefreshCw,
  Users,
  Activity,
  Layers,
  HelpCircle,
  AlertCircle,
  Trash2,
  ArrowUp,
  ArrowDown,
  TrendingDown,
  RotateCcw,
  Sparkles,
  Save,
  CheckCircle,
} from 'lucide-react';

interface FunnelsTabProps {
  initialFlowId?: string;
  onOpenConversation?: (conversationId: string) => void;
}

type QuickRange = 'today' | '7d' | '30d' | 'month';

function formatDateInput(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function computeRangeDates(range: QuickRange): { from: string; to: string } {
  const now = new Date();
  const to = formatDateInput(now);

  if (range === 'today') {
    return { from: to, to };
  }
  if (range === '7d') {
    const past = new Date(now);
    past.setDate(past.getDate() - 7);
    return { from: formatDateInput(past), to };
  }
  if (range === 'month') {
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
    return { from: formatDateInput(firstDay), to };
  }
  const past30 = new Date(now);
  past30.setDate(past30.getDate() - 30);
  return { from: formatDateInput(past30), to };
}

export const FunnelsTab: React.FC<FunnelsTabProps> = ({ initialFlowId, onOpenConversation }) => {
  const [flows, setFlows] = useState<Flow[]>([]);
  const [channels, setChannels] = useState<Channel[]>([]);
  const [selectedFlowId, setSelectedFlowId] = useState<string>(initialFlowId || '');
  const [selectedChannelId, setSelectedChannelId] = useState<string>('');
  const [quickRange, setQuickRange] = useState<QuickRange>('30d');
  const [customFrom, setCustomFrom] = useState<string>('');
  const [customTo, setCustomTo] = useState<string>('');
  const [isCustomDate, setIsCustomDate] = useState<boolean>(false);
  const [refreshTrigger, setRefreshTrigger] = useState<number>(0);

  const [analytics, setAnalytics] = useState<FlowAnalyticsData | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [loadingFlows, setLoadingFlows] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  // Sequential Funnel State
  const [availableSteps, setAvailableSteps] = useState<FunnelAvailableStep[]>([]);
  const [configuredSteps, setConfiguredSteps] = useState<SequentialStepDefinition[]>([]);
  const [hasInitializedSteps, setHasInitializedSteps] = useState<boolean>(false);

  // Saved Funnels State (Funnels CRUD)
  const [savedFunnels, setSavedFunnels] = useState<SavedFunnel[]>([]);
  const [selectedFunnelId, setSelectedFunnelId] = useState<string>('');
  const [isNewFunnelModalOpen, setIsNewFunnelModalOpen] = useState<boolean>(false);
  const [newFunnelName, setNewFunnelName] = useState<string>('');
  const [isSavingFunnel, setIsSavingFunnel] = useState<boolean>(false);
  const [isDeletingFunnel, setIsDeletingFunnel] = useState<boolean>(false);
  const [deleteModalOpen, setDeleteModalOpen] = useState<boolean>(false);
  const [funnelFeedbackMsg, setFunnelFeedbackMsg] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Modal drill-down state (either sequential step or menu option)
  const [modalState, setModalState] = useState<{
    stepNumber?: number | null;
    stepLabel?: string;
    nodeId?: string | null;
    nodeKey?: string | null;
    optionId?: string | null;
    optionTitle: string;
  } | null>(null);

  // Initialize flows and channels
  useEffect(() => {
    let mounted = true;
    api.getFlows()
      .then((res) => {
        if (!mounted) return;
        const flowList = res.data || [];
        setFlows(flowList);
        if (!selectedFlowId && flowList.length > 0) {
          const published = flowList.find((f: Flow) => f.status === 'published') || flowList[0];
          setSelectedFlowId(published.id);
        }
      })
      .catch((err) => console.error(err))
      .finally(() => {
        if (mounted) setLoadingFlows(false);
      });

    api.getChannels()
      .then((res) => {
        if (mounted) setChannels(res.data || []);
      })
      .catch(console.error);

    return () => {
      mounted = false;
    };
  }, [selectedFlowId]);

  // Load Saved Funnels for the selected flow
  useEffect(() => {
    if (!selectedFlowId) return;

    let mounted = true;
    api.getSavedFunnels(selectedFlowId)
      .then((res) => {
        if (!mounted) return;
        setSavedFunnels(res.data || []);
      })
      .catch((err) => {
        console.error('Error fetching saved funnels:', err);
      });

    return () => {
      mounted = false;
    };
  }, [selectedFlowId]);

  // Load Funnel Analytics
  useEffect(() => {
    if (!selectedFlowId) return;

    let active = true;
    let from: string;
    let to: string;

    if (isCustomDate && customFrom && customTo) {
      from = customFrom;
      to = customTo;
    } else {
      const dates = computeRangeDates(quickRange);
      from = dates.from;
      to = dates.to;
    }

    api.getFunnels({
      flow_id: selectedFlowId,
      from,
      to,
      channel_id: selectedChannelId || undefined,
      steps: configuredSteps.length > 0 ? configuredSteps : undefined,
    })
      .then((res) => {
        if (active) {
          setAnalytics(res.data);
          setError(null);

          if (res.data.available_steps) {
            setAvailableSteps(res.data.available_steps);
          }

          // If configured steps is not yet initialized for this flow, use backend defaults
          if (!hasInitializedSteps) {
            if (res.data.configured_steps && res.data.configured_steps.length > 0) {
              setConfiguredSteps(res.data.configured_steps);
            } else if (res.data.default_steps && res.data.default_steps.length > 0) {
              setConfiguredSteps(res.data.default_steps);
            }
            setHasInitializedSteps(true);
          }
        }
      })
      .catch((err: any) => {
        if (active) {
          setError(err.message || 'Error al cargar el embudo del flujo.');
          setAnalytics(null);
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [selectedFlowId, selectedChannelId, quickRange, isCustomDate, customFrom, customTo, refreshTrigger, configuredSteps, hasInitializedSteps]);

  const handleRefresh = useCallback(() => {
    setLoading(true);
    setRefreshTrigger((prev) => prev + 1);
  }, []);

  const handleFlowChange = (flowId: string) => {
    setLoading(true);
    setSelectedFlowId(flowId);
    setSelectedFunnelId('');
    setSavedFunnels([]);
    setConfiguredSteps([]);
    setHasInitializedSteps(false);
  };

  const handleSelectSavedFunnel = (funnelId: string) => {
    setSelectedFunnelId(funnelId);
    if (!funnelId) {
      if (analytics?.default_steps && analytics.default_steps.length > 0) {
        setConfiguredSteps(analytics.default_steps);
      }
      return;
    }

    const found = savedFunnels.find((f) => f.id === funnelId);
    if (found && Array.isArray(found.steps)) {
      setConfiguredSteps(found.steps);
      setHasInitializedSteps(true);
      setLoading(true);
    }
  };

  const handleCreateNewFunnel = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newFunnelName.trim() || !selectedFlowId) return;

    setIsSavingFunnel(true);
    try {
      const stepsToSave = configuredSteps.length > 0
        ? configuredSteps
        : (analytics?.default_steps || []);

      const res = await api.createSavedFunnel({
        name: newFunnelName.trim(),
        flow_id: selectedFlowId,
        steps: stepsToSave,
      });

      const newFunnel = res.data;
      setSavedFunnels((prev) => [...prev, newFunnel].sort((a, b) => a.name.localeCompare(b.name)));
      setSelectedFunnelId(newFunnel.id);
      setConfiguredSteps(newFunnel.steps);
      setIsNewFunnelModalOpen(false);
      setNewFunnelName('');
      setFunnelFeedbackMsg({ type: 'success', text: `Embudo "${newFunnel.name}" creado y guardado.` });
      setTimeout(() => setFunnelFeedbackMsg(null), 4000);
    } catch (err: any) {
      setFunnelFeedbackMsg({ type: 'error', text: err.message || 'Error al guardar el nuevo embudo.' });
    } finally {
      setIsSavingFunnel(false);
    }
  };

  const handleSaveFunnelChanges = async () => {
    if (!selectedFunnelId) return;

    setIsSavingFunnel(true);
    try {
      const res = await api.updateSavedFunnel(selectedFunnelId, {
        steps: configuredSteps,
      });

      const updated = res.data;
      setSavedFunnels((prev) => prev.map((f) => (f.id === updated.id ? updated : f)));
      setFunnelFeedbackMsg({ type: 'success', text: `Cambios guardados en "${updated.name}".` });
      setTimeout(() => setFunnelFeedbackMsg(null), 4000);
    } catch (err: any) {
      setFunnelFeedbackMsg({ type: 'error', text: err.message || 'Error al actualizar el embudo.' });
    } finally {
      setIsSavingFunnel(false);
    }
  };

  const handleConfirmDeleteFunnel = async () => {
    if (!selectedFunnelId) return;

    setIsDeletingFunnel(true);
    try {
      await api.deleteSavedFunnel(selectedFunnelId);
      const deletedId = selectedFunnelId;
      setSavedFunnels((prev) => prev.filter((f) => f.id !== deletedId));
      setSelectedFunnelId('');
      setDeleteModalOpen(false);
      if (analytics?.default_steps && analytics.default_steps.length > 0) {
        setConfiguredSteps(analytics.default_steps);
      }
      setFunnelFeedbackMsg({ type: 'success', text: 'Embudo eliminado exitosamente.' });
      setTimeout(() => setFunnelFeedbackMsg(null), 4000);
    } catch (err: any) {
      setFunnelFeedbackMsg({ type: 'error', text: err.message || 'Error al eliminar el embudo.' });
    } finally {
      setIsDeletingFunnel(false);
    }
  };

  const handleChannelChange = (channelId: string) => {
    setLoading(true);
    setSelectedChannelId(channelId);
  };

  const handleQuickRangeChange = (range: QuickRange) => {
    setLoading(true);
    setIsCustomDate(false);
    setQuickRange(range);
  };

  const handleApplyCustomDates = (e: React.FormEvent) => {
    e.preventDefault();
    if (customFrom && customTo) {
      setLoading(true);
      setIsCustomDate(true);
    }
  };

  // Step configuration handlers
  const handleAddStep = (availableStepId: string) => {
    if (!availableStepId) return;
    const found = availableSteps.find((s) => s.id === availableStepId);
    if (!found) return;

    const newStep: SequentialStepDefinition = {
      id: `step_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
      node_id: found.node_id,
      node_key: found.node_key,
      node_type: found.node_type,
      label: found.label,
      option_id: found.option_id,
      option_title: found.option_title,
      target_node_key: found.target_node_key,
      is_active: true,
    };

    setLoading(true);
    setConfiguredSteps((prev) => [...prev, newStep]);
  };

  const handleToggleStep = (index: number) => {
    setLoading(true);
    setConfiguredSteps((prev) => {
      const copy = [...prev];
      copy[index] = {
        ...copy[index],
        is_active: !copy[index].is_active,
      };
      return copy;
    });
  };

  const handleRemoveStep = (index: number) => {
    setLoading(true);
    setConfiguredSteps((prev) => prev.filter((_, i) => i !== index));
  };

  const handleMoveStep = (index: number, direction: -1 | 1) => {
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= configuredSteps.length) return;

    setLoading(true);
    setConfiguredSteps((prev) => {
      const copy = [...prev];
      const temp = copy[index];
      copy[index] = copy[newIndex];
      copy[newIndex] = temp;
      return copy;
    });
  };

  const handleResetDefaultSteps = () => {
    if (analytics?.default_steps && analytics.default_steps.length > 0) {
      setLoading(true);
      setConfiguredSteps(analytics.default_steps);
    }
  };

  const currentFlow = flows.find((f) => f.id === selectedFlowId);

  // Active dates for modal queries
  const activeDates = isCustomDate && customFrom && customTo
    ? { from: customFrom, to: customTo }
    : computeRangeDates(quickRange);

  const sequentialSteps: SequentialFunnelStep[] = analytics?.sequential_funnel || [];
  const maxStepUsers = sequentialSteps.length > 0 ? Math.max(sequentialSteps[0].users_count, 1) : 1;

  return (
    <div className="funnels-tab" style={{ display: 'flex', flexDirection: 'column', gap: '1.5rem' }} data-testid="funnels-tab">
      {/* Top Filter and Flow Selector Bar */}
      <div
        className="card funnel-filter-card"
        style={{
          padding: '1.25rem',
          background: 'var(--bg-card)',
          borderRadius: 'var(--radius)',
          boxShadow: 'var(--shadow)',
          display: 'flex',
          flexDirection: 'column',
          gap: '1rem',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '0.5rem' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', color: 'var(--text-muted)', fontSize: '0.875rem', fontWeight: 600 }}>
            <Filter size={16} /> Filtros de Recorrido del Embudo
          </div>
          <button
            className="btn btn-secondary btn-sm"
            onClick={handleRefresh}
            disabled={loading}
            title="Recargar embudo"
            data-testid="refresh-funnel-btn"
          >
            <RefreshCw size={14} className={loading ? 'animate-spin' : ''} />
            Actualizar
          </button>
        </div>

        <div className="funnel-filter-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: '1rem' }}>
          {/* Flow Selector */}
          <div>
            <label style={{ display: 'block', fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', marginBottom: '0.35rem' }}>
              <GitBranch size={13} style={{ display: 'inline', marginRight: '4px' }} /> Flujo Conversacional
            </label>
            <select
              value={selectedFlowId}
              onChange={(e) => handleFlowChange(e.target.value)}
              disabled={loadingFlows || flows.length === 0}
              className="form-control"
              data-testid="flow-selector"
              style={{
                width: '100%',
                padding: '0.55rem 0.75rem',
                borderRadius: 'var(--radius)',
                border: '1px solid var(--border)',
                background: 'var(--bg-main)',
                color: 'var(--text-main)',
                fontSize: '0.875rem',
                cursor: 'pointer',
              }}
            >
              {flows.length === 0 ? (
                <option value="">{loadingFlows ? 'Cargando flujos...' : 'Sin flujos disponibles'}</option>
              ) : (
                flows.map((f) => (
                  <option key={f.id} value={f.id}>
                    {f.name} {f.status === 'published' ? '🟢 (Publicado)' : '⚪ (Borrador)'}
                  </option>
                ))
              )}
            </select>
          </div>

          {/* Channel Selector */}
          <div>
            <label style={{ display: 'block', fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', marginBottom: '0.35rem' }}>
              <Radio size={13} style={{ display: 'inline', marginRight: '4px' }} /> Canal de Atención
            </label>
            <select
              value={selectedChannelId}
              onChange={(e) => handleChannelChange(e.target.value)}
              className="form-control"
              data-testid="channel-selector"
              style={{
                width: '100%',
                padding: '0.55rem 0.75rem',
                borderRadius: 'var(--radius)',
                border: '1px solid var(--border)',
                background: 'var(--bg-main)',
                color: 'var(--text-main)',
                fontSize: '0.875rem',
                cursor: 'pointer',
              }}
            >
              <option value="">Todos los Canales (Omnicanal)</option>
              {channels.map((ch) => (
                <option key={ch.id} value={ch.id}>
                  {ch.name} ({ch.type.toUpperCase()})
                </option>
              ))}
            </select>
          </div>

          {/* Date Range Selector */}
          <div>
            <label style={{ display: 'block', fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', marginBottom: '0.35rem' }}>
              <Calendar size={13} style={{ display: 'inline', marginRight: '4px' }} /> Periodo de Consulta
            </label>
            <div style={{ display: 'flex', gap: '0.35rem', flexWrap: 'wrap' }} data-testid="date-range-buttons">
              <button
                type="button"
                className={`btn btn-sm ${!isCustomDate && quickRange === 'today' ? 'btn-primary' : 'btn-secondary'}`}
                onClick={() => handleQuickRangeChange('today')}
                data-testid="quick-range-today"
                style={{ flex: 1, minWidth: '60px', padding: '0.45rem 0.6rem' }}
              >
                Hoy
              </button>
              <button
                type="button"
                className={`btn btn-sm ${!isCustomDate && quickRange === '7d' ? 'btn-primary' : 'btn-secondary'}`}
                onClick={() => handleQuickRangeChange('7d')}
                data-testid="quick-range-7d"
                style={{ flex: 1, minWidth: '70px', padding: '0.45rem 0.6rem' }}
              >
                7 días
              </button>
              <button
                type="button"
                className={`btn btn-sm ${!isCustomDate && quickRange === '30d' ? 'btn-primary' : 'btn-secondary'}`}
                onClick={() => handleQuickRangeChange('30d')}
                data-testid="quick-range-30d"
                style={{ flex: 1, minWidth: '75px', padding: '0.45rem 0.6rem' }}
              >
                30 días
              </button>
              <button
                type="button"
                className={`btn btn-sm ${!isCustomDate && quickRange === 'month' ? 'btn-primary' : 'btn-secondary'}`}
                onClick={() => handleQuickRangeChange('month')}
                data-testid="quick-range-month"
                style={{ flex: 1, minWidth: '85px', padding: '0.45rem 0.6rem' }}
              >
                Mes actual
              </button>
            </div>
          </div>
        </div>

        {/* Custom Date Form */}
        <div style={{ borderTop: '1px solid var(--border)', paddingTop: '0.75rem', display: 'flex', alignItems: 'center', gap: '1rem', flexWrap: 'wrap' }}>
          <form onSubmit={handleApplyCustomDates} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
            <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>Rango personalizado:</span>
            <input
              type="date"
              value={customFrom}
              onChange={(e) => setCustomFrom(e.target.value)}
              aria-label="Fecha inicio"
              className="form-control"
              data-testid="custom-from-input"
              style={{
                padding: '0.35rem 0.5rem',
                fontSize: '0.8rem',
                borderRadius: 'var(--radius)',
                border: '1px solid var(--border)',
                background: 'var(--bg-main)',
              }}
            />
            <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>hasta</span>
            <input
              type="date"
              value={customTo}
              onChange={(e) => setCustomTo(e.target.value)}
              aria-label="Fecha fin"
              className="form-control"
              data-testid="custom-to-input"
              style={{
                padding: '0.35rem 0.5rem',
                fontSize: '0.8rem',
                borderRadius: 'var(--radius)',
                border: '1px solid var(--border)',
                background: 'var(--bg-main)',
              }}
            />
            <button
              type="submit"
              className="btn btn-secondary btn-sm"
              disabled={!customFrom || !customTo}
              data-testid="apply-custom-dates-btn"
            >
              Aplicar
            </button>
          </form>

          {analytics?.date_range && (
            <div style={{ marginLeft: 'auto', fontSize: '0.8rem', color: 'var(--text-muted)' }}>
              Periodo activo: <strong>{analytics.date_range.from_formatted || analytics.date_range.from.slice(0, 10)}</strong> al{' '}
              <strong>{analytics.date_range.to_formatted || analytics.date_range.to.slice(0, 10)}</strong>
            </div>
          )}
        </div>

        {/* Saved Funnels Toolbar Row (Estilo Botmaker) */}
        <div
          className="saved-funnels-toolbar"
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            flexWrap: 'wrap',
            gap: '0.85rem',
            borderTop: '1px solid var(--border)',
            paddingTop: '0.85rem',
          }}
          data-testid="saved-funnels-toolbar"
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.65rem', flexWrap: 'wrap', flex: 1 }}>
            <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.85rem', fontWeight: 700, color: 'var(--text-main)', margin: 0 }}>
              <Layers size={16} style={{ color: 'var(--primary)' }} />
              <span>Funnel:</span>
            </label>

            <select
              value={selectedFunnelId}
              onChange={(e) => handleSelectSavedFunnel(e.target.value)}
              className="form-control saved-funnel-selector"
              data-testid="saved-funnel-selector"
              style={{
                maxWidth: '340px',
                padding: '0.4rem 0.75rem',
                borderRadius: '8px',
                border: '1px solid var(--border)',
                fontSize: '0.85rem',
                fontWeight: 600,
                background: 'var(--bg-main)',
                color: 'var(--text-main)',
                cursor: 'pointer',
              }}
            >
              <option value="">⚙️ Embudo Personalizado / Por Defecto</option>
              {savedFunnels.map((sf) => (
                <option key={sf.id} value={sf.id}>
                  📁 {sf.name} ({sf.steps?.length || 0} pasos)
                </option>
              ))}
            </select>

            {selectedFunnelId && (
              <span className="badge badge-primary" style={{ fontSize: '0.75rem', padding: '0.2rem 0.5rem' }}>
                Guardado
              </span>
            )}
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
            {/* + Nuevo Funnel */}
            <button
              type="button"
              className="btn btn-secondary btn-sm"
              onClick={() => {
                setNewFunnelName('');
                setIsNewFunnelModalOpen(true);
              }}
              data-testid="btn-new-funnel"
              style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.8rem', padding: '0.4rem 0.75rem' }}
            >
              <Sparkles size={14} style={{ color: 'var(--primary)' }} />
              <span>+ Nuevo Funnel</span>
            </button>

            {/* Guardar Cambios */}
            {selectedFunnelId && (
              <button
                type="button"
                className="btn btn-primary btn-sm"
                onClick={handleSaveFunnelChanges}
                disabled={isSavingFunnel}
                data-testid="btn-save-funnel-changes"
                style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.8rem', padding: '0.4rem 0.75rem' }}
              >
                <Save size={14} />
                <span>{isSavingFunnel ? 'Guardando...' : 'Guardar Cambios'}</span>
              </button>
            )}

            {/* Eliminar Funnel */}
            {selectedFunnelId && (
              <button
                type="button"
                className="btn btn-danger btn-sm"
                onClick={() => setDeleteModalOpen(true)}
                disabled={isDeletingFunnel}
                data-testid="btn-delete-funnel"
                style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.8rem', padding: '0.4rem 0.65rem' }}
              >
                <Trash2 size={14} />
                <span>Eliminar Funnel</span>
              </button>
            )}
          </div>
        </div>

        {/* Feedback message banner */}
        {funnelFeedbackMsg && (
          <div
            className={`alert ${funnelFeedbackMsg.type === 'success' ? 'alert-success' : 'alert-danger'}`}
            style={{
              padding: '0.5rem 0.75rem',
              fontSize: '0.8rem',
              borderRadius: '8px',
              display: 'flex',
              alignItems: 'center',
              gap: '0.4rem',
              marginTop: '0.5rem',
            }}
            data-testid="funnel-feedback-alert"
          >
            {funnelFeedbackMsg.type === 'success' ? <CheckCircle size={15} /> : <AlertCircle size={15} />}
            <span>{funnelFeedbackMsg.text}</span>
          </div>
        )}
      </div>

      {/* Error Message */}
      {error && (
        <div
          data-testid="funnel-error"
          style={{
            padding: '1rem',
            background: 'var(--danger-bg)',
            border: '1px solid #fecaca',
            borderRadius: 'var(--radius)',
            color: 'var(--danger)',
            display: 'flex',
            alignItems: 'center',
            gap: '0.5rem',
          }}
        >
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {/* Loading Skeleton */}
      {loading && !analytics && (
        <div data-testid="funnel-loading" style={{ padding: '3rem', textAlign: 'center', color: 'var(--text-muted)' }}>
          <Activity className="animate-spin" size={32} style={{ margin: '0 auto 0.75rem', color: 'var(--primary)' }} />
          <p>Cargando embudo secuencial y analítica del flujo...</p>
        </div>
      )}

      {/* Main Content */}
      {analytics && (
        <>
          {/* Funnel Overview Summary Bar */}
          <div
            className="card funnel-config-card"
            style={{
              padding: '1.25rem 1.5rem',
              background: 'var(--bg-card)',
              borderRadius: 'var(--radius)',
              boxShadow: 'var(--shadow)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              flexWrap: 'wrap',
              gap: '1rem',
            }}
          >
            <div>
              <span style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', textTransform: 'uppercase' }}>
                Flujo Seleccionado
              </span>
              <div style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--text-main)', marginTop: '0.2rem' }}>
                {analytics.flow_name || currentFlow?.name}
              </div>
            </div>

            <div style={{ display: 'flex', gap: '2rem', flexWrap: 'wrap' }}>
              <div>
                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', display: 'block' }}>Sesiones Totales</span>
                <span style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--primary)' }}>
                  {analytics.kpis.total_executions.toLocaleString()}
                </span>
              </div>
              <div>
                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', display: 'block' }}>Contactos Únicos</span>
                <span style={{ fontSize: '1.25rem', fontWeight: 700, color: '#263c38' }}>
                  {analytics.kpis.unique_contacts.toLocaleString()}
                </span>
              </div>
              <div>
                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', display: 'block' }}>Tasa de Resolución</span>
                <span style={{ fontSize: '1.25rem', fontWeight: 700, color: '#263c38' }}>
                  {analytics.kpis.completion_rate}%
                </span>
              </div>
              <div>
                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', display: 'block' }}>Abandono en Menú</span>
                <span style={{ fontSize: '1.25rem', fontWeight: 700, color: '#dc2626' }}>
                  {analytics.kpis.dropoff_rate}%
                </span>
              </div>
            </div>
          </div>

          {/* Sequential Funnel Step Configurator */}
          <div
            className="card"
            data-testid="funnel-steps-configurator"
            style={{
              padding: '1.25rem 1.5rem',
              background: 'var(--bg-card)',
              borderRadius: 'var(--radius)',
              boxShadow: 'var(--shadow)',
              display: 'flex',
              flexDirection: 'column',
              gap: '1rem',
            }}
          >
            <div className="funnel-config-header" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '0.75rem' }}>
              <div className="funnel-config-intro">
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                  <Sparkles size={18} style={{ color: 'var(--primary)' }} />
                  <h3 style={{ fontSize: '1.05rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                    Configuración del Embudo Secuencial (Paso a Paso)
                  </h3>
                </div>
                <p style={{ margin: '0.2rem 0 0', fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                  Encadena las secciones u opciones del bot para medir la retención y la fuga cronológica dentro de cada sesión.
                </p>
              </div>

              <div className="funnel-config-controls" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                {analytics.default_steps && analytics.default_steps.length > 0 && (
                  <button
                    type="button"
                    className="btn btn-secondary btn-sm"
                    onClick={handleResetDefaultSteps}
                    title="Restablecer a la secuencia sugerida por defecto"
                    data-testid="reset-default-steps-btn"
                    style={{ fontSize: '0.8rem', display: 'flex', alignItems: 'center', gap: '0.35rem' }}
                  >
                    <RotateCcw size={13} />
                    Restablecer
                  </button>
                )}

                {/* Add Step Dropdown */}
                <div className="funnel-add-step-control" style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                  <select
                    className="form-control"
                    data-testid="add-step-select"
                    defaultValue=""
                    onChange={(e) => {
                      handleAddStep(e.target.value);
                      e.target.value = '';
                    }}
                    style={{
                      width: '100%',
                      minWidth: 0,
                      fontSize: '0.825rem',
                      padding: '0.4rem 0.75rem',
                      borderRadius: '8px',
                      background: 'var(--primary-bg)',
                      borderColor: 'var(--primary)',
                      color: 'var(--primary)',
                      fontWeight: 600,
                      cursor: 'pointer',
                    }}
                  >
                    <option value="" disabled>
                      + Agregar Paso al Embudo...
                    </option>
                    <optgroup label="Nodos del Flujo">
                      {availableSteps
                        .filter((s) => s.node_type !== 'menu_option')
                        .map((s) => (
                          <option key={s.id} value={s.id}>
                            Nodo: {s.label} ({s.node_type})
                          </option>
                        ))}
                    </optgroup>
                    <optgroup label="Opciones de Menú">
                      {availableSteps
                        .filter((s) => s.node_type === 'menu_option')
                        .map((s) => (
                          <option key={s.id} value={s.id}>
                            🔘 {s.label}
                          </option>
                        ))}
                    </optgroup>
                  </select>
                </div>
              </div>
            </div>

            {/* List of Configured Steps */}
            <div data-testid="funnel-steps-list" style={{ display: 'flex', flexDirection: 'column', gap: '0.65rem' }}>
              {configuredSteps.length === 0 ? (
                <div style={{ padding: '1.5rem', textAlign: 'center', background: 'var(--bg-main)', borderRadius: '8px', color: 'var(--text-muted)', fontSize: '0.875rem' }}>
                  No hay etapas configuradas. Usa el desplegable <strong>«+ Agregar Paso al Embudo»</strong> para encadenar secciones.
                </div>
              ) : (
                configuredSteps.map((step, idx) => (
                  <div
                    key={step.id || `step_${idx}`}
                    data-testid={`funnel-step-item-${idx + 1}`}
                    className="funnel-step-config-row"
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'space-between',
                      padding: '0.65rem 1rem',
                      background: step.is_active ? 'var(--bg-main)' : 'var(--bg-muted, #eaf1ee)',
                      borderRadius: '8px',
                      border: '1px solid var(--border)',
                      opacity: step.is_active ? 1 : 0.6,
                      gap: '0.75rem',
                      flexWrap: 'wrap',
                    }}
                  >
                    <div className="funnel-step-config-main" style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                      <span
                        className="badge badge-primary"
                        data-testid={`step-badge-${idx + 1}`}
                        style={{ fontSize: '0.75rem', padding: '0.25rem 0.55rem', fontWeight: 700 }}
                      >
                        Paso {idx + 1}
                      </span>

                      {/* Active Toggle Switch / Checkbox */}
                      <label style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', cursor: 'pointer', fontSize: '0.8rem', margin: 0 }}>
                        <input
                          type="checkbox"
                          checked={step.is_active}
                          onChange={() => handleToggleStep(idx)}
                          data-testid={`step-toggle-${idx + 1}`}
                          style={{ cursor: 'pointer' }}
                        />
                        <span style={{ color: step.is_active ? 'var(--text-main)' : 'var(--text-muted)' }}>
                          {step.is_active ? 'Activo' : 'Omitido'}
                        </span>
                      </label>

                      <div className="funnel-step-config-label" style={{ display: 'flex', flexDirection: 'column' }}>
                        <strong style={{ fontSize: '0.875rem', color: 'var(--text-main)' }}>
                          {step.label}
                        </strong>
                        <span style={{ fontSize: '0.7rem', color: 'var(--text-muted)' }}>
                          {step.node_key ? `Nodo: ${step.node_key}` : ''} {step.option_id ? `(Opción ${step.option_id})` : ''}
                        </span>
                      </div>
                    </div>

                    <div className="funnel-step-config-actions" style={{ display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        onClick={() => handleMoveStep(idx, -1)}
                        disabled={idx === 0}
                        title="Subir de posición"
                        data-testid={`step-move-up-${idx + 1}`}
                        style={{ padding: '0.25rem 0.45rem' }}
                      >
                        <ArrowUp size={13} />
                      </button>
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        onClick={() => handleMoveStep(idx, 1)}
                        disabled={idx === configuredSteps.length - 1}
                        title="Bajar de posición"
                        data-testid={`step-move-down-${idx + 1}`}
                        style={{ padding: '0.25rem 0.45rem' }}
                      >
                        <ArrowDown size={13} />
                      </button>
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        onClick={() => handleRemoveStep(idx)}
                        title="Eliminar paso del embudo"
                        data-testid={`step-delete-${idx + 1}`}
                        style={{ padding: '0.25rem 0.45rem', color: 'var(--danger)' }}
                      >
                        <Trash2 size={13} />
                      </button>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          {/* Real Graphic Sequential Funnel Visualization */}
          <div
            className="card funnel-visualization-card"
            data-testid="sequential-funnel-visualization"
            style={{
              padding: '1.5rem',
              background: 'var(--bg-card)',
              borderRadius: 'var(--radius)',
              boxShadow: 'var(--shadow)',
              display: 'flex',
              flexDirection: 'column',
              gap: '1.5rem',
            }}
          >
            <div className="funnel-visual-header" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid var(--border)', paddingBottom: '0.85rem' }}>
              <div className="funnel-visual-heading" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                <Layers size={18} style={{ color: 'var(--primary)' }} />
                <h3 style={{ fontSize: '1.15rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                  Visualización del Embudo de Conversión
                </h3>
              </div>
              <span className="badge badge-secondary" style={{ fontSize: '0.8rem', padding: '0.35rem 0.65rem' }}>
                {sequentialSteps.length} {sequentialSteps.length === 1 ? 'etapa activa' : 'etapas activas'}
              </span>
            </div>

            {sequentialSteps.length === 0 ? (
              <div style={{ padding: '2.5rem', textAlign: 'center', color: 'var(--text-muted)' }}>
                <HelpCircle size={36} style={{ margin: '0 auto 0.75rem', opacity: 0.4 }} />
                <p>No hay etapas activas en el embudo. Activa o agrega pasos en el configurador superior.</p>
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                {sequentialSteps.map((step, idx) => {
                  const nextStep = sequentialSteps[idx + 1];
                  const barWidth = Math.max(12, Math.round((step.users_count / maxStepUsers) * 100));

                  return (
                    <React.Fragment key={`seq_step_${step.step_number}`}>
                      {/* Step Card */}
                      <div
                        data-testid={`funnel-step-card-${step.step_number}`}
                        className="funnel-step-metric-card"
                        style={{
                          background: 'var(--bg-main)',
                          borderRadius: '12px',
                          border: '1px solid var(--border)',
                          padding: '1.1rem 1.25rem',
                          display: 'flex',
                          flexDirection: 'column',
                          gap: '0.75rem',
                          boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
                        }}
                      >
                        {/* Step Title & Metrics Header */}
                        <div className="funnel-step-metrics" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '0.5rem' }}>
                          <div className="funnel-step-metric-title" style={{ display: 'flex', alignItems: 'center', gap: '0.65rem' }}>
                            <span
                              style={{
                                background: '#456c61',
                                color: '#ffffff',
                                fontSize: '0.75rem',
                                fontWeight: 700,
                                padding: '0.2rem 0.55rem',
                                borderRadius: '6px',
                              }}
                            >
                              Paso {step.step_number}
                            </span>
                          <h4 className="funnel-step-metric-label" style={{ fontSize: '1rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                              {step.label}
                            </h4>
                          </div>

                          <div className="funnel-step-metric-details" style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                            <span style={{ fontSize: '0.85rem', fontWeight: 600, color: 'var(--text-main)' }}>
                              {step.users_count.toLocaleString()} usuarios únicos
                            </span>
                            <span
                              style={{
                                fontSize: '0.75rem',
                                padding: '0.2rem 0.5rem',
                                borderRadius: '6px',
                                background: '#eaf1ee',
                                color: '#263c38',
                                fontWeight: 600,
                              }}
                            >
                              {step.conversion_from_first}% retención
                            </span>

                            {/* View Contacts Button */}
                            <button
                              type="button"
                              className="btn btn-secondary btn-sm"
                              data-testid={`funnel-step-contacts-btn-${step.step_number}`}
                              onClick={() =>
                                setModalState({
                                  stepNumber: step.step_number,
                                  stepLabel: step.label,
                                  nodeId: step.node_id,
                                  optionId: step.option_id,
                                  optionTitle: step.label,
                                })
                              }
                              style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.75rem', padding: '0.25rem 0.55rem' }}
                            >
                              <Users size={13} />
                              Contactos ({step.users_count})
                            </button>
                          </div>
                        </div>

                        {/* Horizontal Funnel Bar */}
                        <div
                          style={{
                            height: '24px',
                            width: '100%',
                            background: '#d9e3de',
                            borderRadius: '8px',
                            overflow: 'hidden',
                            position: 'relative',
                          }}
                        >
                          <div
                            style={{
                              height: '100%',
                              width: `${barWidth}%`,
                              background: 'linear-gradient(90deg, #456c61 0%, #5d8f82 100%)',
                              borderRadius: '8px',
                              transition: 'width 0.4s ease',
                              display: 'flex',
                              alignItems: 'center',
                              paddingLeft: '0.75rem',
                              color: '#ffffff',
                              fontSize: '0.75rem',
                              fontWeight: 600,
                            }}
                          >
                            {step.conversion_from_first}%
                          </div>
                        </div>
                      </div>

                      {/* Funnel Bridge / Drop-off Connector between steps */}
                      {nextStep && (
                        <div
                          data-testid={`funnel-connector-${step.step_number}`}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            padding: '0.35rem 0',
                            gap: '1rem',
                          }}
                        >
                          <div
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '0.4rem',
                              padding: '0.3rem 0.75rem',
                              borderRadius: '9999px',
                              background: '#eaf1ee',
                              border: '1px solid #a9c8bb',
                              color: '#263c38',
                              fontSize: '0.775rem',
                              fontWeight: 600,
                            }}
                          >
                            <span>↓ {nextStep.conversion_from_previous}% conversión</span>
                          </div>

                          <div
                            data-testid={`dropoff-badge-${nextStep.step_number}`}
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '0.35rem',
                              padding: '0.3rem 0.75rem',
                              borderRadius: '9999px',
                              background: '#fef2f2',
                              border: '1px solid #fecaca',
                              color: '#991b1b',
                              fontSize: '0.775rem',
                              fontWeight: 600,
                            }}
                          >
                            <TrendingDown size={13} />
                            <span>Drop-off: -{nextStep.dropoff_from_previous}% abandono</span>
                          </div>
                        </div>
                      )}
                    </React.Fragment>
                  );
                })}
              </div>
            )}
          </div>
        </>
      )}

      {/* Drill-Down Contacts Modal */}
      {modalState && selectedFlowId && (
        <FunnelContactsModal
          isOpen={true}
          onClose={() => setModalState(null)}
          flowId={selectedFlowId}
          stepNumber={modalState.stepNumber}
          stepLabel={modalState.stepLabel}
          steps={configuredSteps}
          nodeId={modalState.nodeId}
          nodeKey={modalState.nodeKey}
          optionId={modalState.optionId}
          optionTitle={modalState.optionTitle}
          from={activeDates.from}
          to={activeDates.to}
          channelId={selectedChannelId || undefined}
          onOpenConversation={onOpenConversation}
        />
      )}

      {/* New Saved Funnel Modal */}
      {isNewFunnelModalOpen && (
        <div
          className="modal-overlay flow-panel-overlay"
          role="dialog"
          aria-modal="true"
          aria-labelledby="new-funnel-panel-title"
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(0,0,0,0.5)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 1100,
            padding: '1rem',
          }}
          data-testid="new-funnel-modal"
        >
          <div
            className="modal-content flow-side-panel"
            style={{
              width: '100%',
              maxWidth: '440px',
              background: 'var(--bg-card, #fff)',
              padding: '1.5rem',
              borderRadius: '12px',
              boxShadow: '0 20px 25px -5px rgba(0,0,0,0.2)',
              display: 'flex',
              flexDirection: 'column',
              gap: '1rem',
            }}
          >
              <div className="flow-side-panel-header">
              <h3 id="new-funnel-panel-title" style={{ fontSize: '1.1rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                Crear Nuevo Embudo Guardado
              </h3>
              <button
                type="button"
                className="btn btn-ghost btn-sm"
                onClick={() => setIsNewFunnelModalOpen(false)}
                style={{ padding: '0.2rem 0.5rem' }}
                data-testid="close-new-funnel-modal-btn"
              >
                ✕
              </button>
            </div>

            <form id="create-saved-funnel-form" onSubmit={handleCreateNewFunnel} className="flow-side-panel-form">
              <div className="flow-side-panel-body" style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                <p style={{ fontSize: '0.85rem', color: 'var(--text-muted)', margin: 0 }}>
                  Asigna un nombre descriptivo para identificar este embudo de conversión en el flujo seleccionado.
                </p>
              <div>
                <label style={{ display: 'block', fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-main)', marginBottom: '0.35rem' }}>
                  Nombre del Funnel *
                </label>
                <input
                  type="text"
                  className="form-control"
                  value={newFunnelName}
                  onChange={(e) => setNewFunnelName(e.target.value)}
                  placeholder="ej. Ruta Ofrendas a Peticiones"
                  required
                  autoFocus
                  data-testid="new-funnel-name-input"
                  style={{ width: '100%', padding: '0.5rem 0.75rem', borderRadius: '8px', fontSize: '0.875rem' }}
                />
              </div>

              </div>
            </form>
              <div className="flow-side-panel-footer">
                <button
                  type="button"
                  className="btn btn-secondary btn-sm"
                  onClick={() => setIsNewFunnelModalOpen(false)}
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  form="create-saved-funnel-form"
                  className="btn btn-primary btn-sm"
                  disabled={!newFunnelName.trim() || isSavingFunnel}
                  data-testid="confirm-new-funnel-btn"
                >
                  {isSavingFunnel ? 'Creando...' : 'Crear y Guardar'}
                </button>
              </div>
          </div>
        </div>
      )}

      {/* Delete Funnel Confirmation Modal */}
      <ConfirmModal
        isOpen={deleteModalOpen}
        onClose={() => setDeleteModalOpen(false)}
        onConfirm={handleConfirmDeleteFunnel}
        title="Eliminar Embudo Guardado"
        description={`¿Estás seguro de que deseas eliminar permanentemente el embudo "${savedFunnels.find((f) => f.id === selectedFunnelId)?.name || ''}"? Esta acción no se puede deshacer.`}
        confirmText="Eliminar Embudo"
        cancelText="Cancelar"
        confirmVariant="danger"
        isLoading={isDeletingFunnel}
        testId="delete-funnel-confirm-modal"
        confirmTestId="confirm-delete-funnel-btn"
        requiredText={savedFunnels.find((funnel) => funnel.id === selectedFunnelId)?.name}
        requiredTextLabel="embudo"
      />
    </div>
  );
};
