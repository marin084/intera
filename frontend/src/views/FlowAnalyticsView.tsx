import React, { useState, useEffect, useCallback } from 'react';
import { api } from '../services/api';
import type { Flow, Channel, FlowAnalyticsData } from '../types';
import {
  BarChart3,
  Users,
  UserCheck,
  Flame,
  CheckCircle,
  TrendingUp,
  Filter,
  Calendar,
  Radio,
  GitBranch,
  ArrowLeft,
  RefreshCw,
  AlertCircle,
  HelpCircle,
  Activity,
  Layers,
} from 'lucide-react';

interface FlowAnalyticsViewProps {
  initialFlowId?: string;
  onBack?: () => void;
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
  // Default '30d'
  const past30 = new Date(now);
  past30.setDate(past30.getDate() - 30);
  return { from: formatDateInput(past30), to };
}

export const FlowAnalyticsView: React.FC<FlowAnalyticsViewProps> = ({ initialFlowId, onBack }) => {
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

  // Initialize flows and channels
  useEffect(() => {
    let mounted = true;
    const fetchMetadata = async () => {
      try {
        setLoadingFlows(true);
        const [flowsRes, channelsRes] = await Promise.all([
          api.getFlows().catch(() => ({ data: [] })),
          api.getChannels().catch(() => ({ data: [] })),
        ]);

        if (!mounted) return;

        const flowList = flowsRes.data || [];
        setFlows(flowList);
        setChannels(channelsRes.data || []);

        if (!selectedFlowId && flowList.length > 0) {
          // Default to first active/published flow or first flow
          const activeFlow = flowList.find((f: Flow) => f.status === 'published') || flowList[0];
          setSelectedFlowId(activeFlow.id);
        }
      } catch (err: any) {
        if (mounted) setError(err.message || 'Error al cargar flujos.');
      } finally {
        if (mounted) setLoadingFlows(false);
      }
    };

    fetchMetadata();
    return () => {
      mounted = false;
    };
  }, [selectedFlowId]);

  // Load analytics when filters change
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

    api.getFlowAnalytics(selectedFlowId, {
      from,
      to,
      channel_id: selectedChannelId || undefined,
    })
      .then((res) => {
        if (active) {
          setAnalytics(res.data);
          setError(null);
        }
      })
      .catch((err: any) => {
        if (active) {
          setError(err.message || 'Error al cargar las métricas del flujo.');
          setAnalytics(null);
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [selectedFlowId, selectedChannelId, quickRange, isCustomDate, customFrom, customTo, refreshTrigger]);

  const loadAnalytics = useCallback(() => {
    setLoading(true);
    setRefreshTrigger((prev) => prev + 1);
  }, []);

  const handleFlowChange = (flowId: string) => {
    setLoading(true);
    setSelectedFlowId(flowId);
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

  const currentFlow = flows.find((f) => f.id === selectedFlowId);

  return (
    <div style={{ padding: '1.5rem', maxWidth: '1400px', margin: '0 auto', display: 'flex', flexDirection: 'column', gap: '1.5rem' }}>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '1rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
          {onBack && (
            <button
              className="btn btn-secondary btn-sm"
              onClick={onBack}
              title="Volver a la lista de flujos"
              data-testid="back-button"
            >
              <ArrowLeft size={16} /> Volver
            </button>
          )}
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <BarChart3 size={24} style={{ color: 'var(--primary)' }} />
              <h1 style={{ fontSize: '1.5rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                Analítica y Métricas de Flujos
              </h1>
            </div>
            <p style={{ margin: '0.25rem 0 0', color: 'var(--text-muted)', fontSize: '0.875rem' }}>
              Desglose detallado de tránsito, embudos de menús y opciones más populares del bot.
            </p>
          </div>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
          <button
            className="btn btn-secondary btn-sm"
            onClick={loadAnalytics}
            disabled={loading}
            title="Recargar métricas"
            data-testid="refresh-metrics-btn"
          >
            <RefreshCw size={14} className={loading ? 'animate-spin' : ''} />
            Actualizar
          </button>
        </div>
      </div>

      {/* Filter Bar */}
      <div
        className="card"
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
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', color: 'var(--text-muted)', fontSize: '0.875rem', fontWeight: 600 }}>
          <Filter size={16} /> Filtros de Segmentación
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: '1rem' }}>
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

        {/* Custom Date Form (collapsible or inline) */}
        <div style={{ borderTop: '1px solid var(--border)', paddingTop: '0.75rem', display: 'flex', alignItems: 'center', gap: '1rem', flexWrap: 'wrap' }}>
          <form onSubmit={handleApplyCustomDates} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
            <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>O rango personalizado:</span>
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
              Mostrando datos del <strong>{analytics.date_range.from_formatted || analytics.date_range.from.slice(0, 10)}</strong> al{' '}
              <strong>{analytics.date_range.to_formatted || analytics.date_range.to.slice(0, 10)}</strong>
            </div>
          )}
        </div>
      </div>

      {/* Error Banner */}
      {error && (
        <div
          data-testid="analytics-error"
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
        <div data-testid="analytics-loading" style={{ display: 'flex', flexDirection: 'column', gap: '1.5rem' }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '1rem' }}>
            {[1, 2, 3, 4].map((i) => (
              <div
                key={i}
                className="card"
                style={{
                  height: '110px',
                  borderRadius: 'var(--radius)',
                  background: 'var(--bg-card)',
                  padding: '1.25rem',
                  display: 'flex',
                  flexDirection: 'column',
                  justifyContent: 'center',
                  gap: '0.5rem',
                }}
              >
                <div style={{ height: '16px', width: '40%', background: '#d9e3de', borderRadius: '4px' }} />
                <div style={{ height: '28px', width: '60%', background: '#d9e3de', borderRadius: '4px' }} />
              </div>
            ))}
          </div>

          <div className="card" style={{ padding: '2rem', textAlign: 'center', color: 'var(--text-muted)' }}>
            <Activity className="animate-spin" size={28} style={{ margin: '0 auto 0.5rem', color: 'var(--primary)' }} />
            <p>Calculando analítica y estadísticas del flujo...</p>
          </div>
        </div>
      )}

      {/* Main Content Dashboard */}
      {!loading && analytics && (
        <>
          {/* KPI Cards Grid */}
          <div
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))',
              gap: '1rem',
            }}
            data-testid="kpi-cards-grid"
          >
            {/* Card 1: Total Executions */}
            <div
              className="card"
              data-testid="kpi-total-executions"
              style={{
                padding: '1.25rem',
                background: 'var(--bg-card)',
                borderRadius: 'var(--radius)',
                boxShadow: 'var(--shadow)',
                display: 'flex',
                alignItems: 'flex-start',
                justifyContent: 'space-between',
              }}
            >
              <div>
                <span style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                  Sesiones / Ejecuciones
                </span>
                <div style={{ fontSize: '2rem', fontWeight: 700, color: 'var(--text-main)', marginTop: '0.25rem' }}>
                  {analytics.kpis.total_executions.toLocaleString()}
                </div>
                <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                  Inicios de flujo en el periodo
                </div>
              </div>
              <div
                style={{
                  width: '42px',
                  height: '42px',
                  borderRadius: '10px',
                  background: 'var(--primary-light)',
                  color: 'var(--primary)',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <Users size={22} />
              </div>
            </div>

            {/* Card 2: Unique Contacts */}
            <div
              className="card"
              data-testid="kpi-unique-contacts"
              style={{
                padding: '1.25rem',
                background: 'var(--bg-card)',
                borderRadius: 'var(--radius)',
                boxShadow: 'var(--shadow)',
                display: 'flex',
                alignItems: 'flex-start',
                justifyContent: 'space-between',
              }}
            >
              <div>
                <span style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                  Contactos Únicos
                </span>
                <div style={{ fontSize: '2rem', fontWeight: 700, color: '#263c38', marginTop: '0.25rem' }}>
                  {analytics.kpis.unique_contacts.toLocaleString()}
                </div>
                <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                  Personas distintas atendidas
                </div>
              </div>
              <div
                style={{
                  width: '42px',
                  height: '42px',
                  borderRadius: '10px',
                  background: '#d1fae5',
                  color: '#5d8f82',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <UserCheck size={22} />
              </div>
            </div>

            {/* Card 3: Top Section */}
            <div
              className="card"
              data-testid="kpi-top-section"
              style={{
                padding: '1.25rem',
                background: 'var(--bg-card)',
                borderRadius: 'var(--radius)',
                boxShadow: 'var(--shadow)',
                display: 'flex',
                alignItems: 'flex-start',
                justifyContent: 'space-between',
              }}
            >
              <div style={{ flex: 1, minWidth: 0, paddingRight: '0.5rem' }}>
                <span style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                  Sección Más Popular
                </span>
                <div
                  style={{
                    fontSize: '1.25rem',
                    fontWeight: 700,
                    color: '#d97706',
                    marginTop: '0.35rem',
                    whiteSpace: 'nowrap',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                  }}
                  title={analytics.kpis.top_section?.title || 'Sin datos'}
                >
                  {analytics.kpis.top_section ? analytics.kpis.top_section.title : 'Sin selecciones'}
                </div>
                <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem', display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                  {analytics.kpis.top_section ? (
                    <>
                      <span className="badge badge-warning" style={{ fontWeight: 600 }}>
                        {analytics.kpis.top_section.percentage}% de clics
                      </span>
                      <span>({analytics.kpis.top_section.clicks_count} clics)</span>
                    </>
                  ) : (
                    'Esperando interacciones de usuarios'
                  )}
                </div>
              </div>
              <div
                style={{
                  width: '42px',
                  height: '42px',
                  borderRadius: '10px',
                  background: '#fef3c7',
                  color: '#d97706',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  flexShrink: 0,
                }}
              >
                <Flame size={22} />
              </div>
            </div>

            {/* Card 4: Resolution & Dropoff Rate */}
            <div
              className="card"
              data-testid="kpi-resolution-rate"
              style={{
                padding: '1.25rem',
                background: 'var(--bg-card)',
                borderRadius: 'var(--radius)',
                boxShadow: 'var(--shadow)',
                display: 'flex',
                alignItems: 'flex-start',
                justifyContent: 'space-between',
              }}
            >
              <div>
                <span style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                  Tasa de Resolución
                </span>
                <div style={{ fontSize: '2rem', fontWeight: 700, color: '#263c38', marginTop: '0.25rem' }}>
                  {analytics.kpis.completion_rate}%
                </div>
                <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                  {analytics.kpis.completed_executions} completadas • Abandono: {analytics.kpis.dropoff_rate}%
                </div>
              </div>
              <div
                style={{
                  width: '42px',
                  height: '42px',
                  borderRadius: '10px',
                  background: '#eaf1ee',
                  color: '#5d8f82',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <CheckCircle size={22} />
              </div>
            </div>
          </div>

          {/* Empty State when no executions exist */}
          {analytics.kpis.total_executions === 0 && (
            <div
              className="card"
              data-testid="analytics-empty-state"
              style={{
                padding: '3rem 1.5rem',
                textAlign: 'center',
                background: 'var(--bg-card)',
                borderRadius: 'var(--radius)',
                boxShadow: 'var(--shadow)',
              }}
            >
              <TrendingUp size={48} style={{ color: '#80918c', margin: '0 auto 1rem' }} />
              <h3 style={{ fontSize: '1.15rem', fontWeight: 600, color: 'var(--text-main)', marginBottom: '0.5rem' }}>
                Sin actividad registrada en este periodo
              </h3>
              <p style={{ color: 'var(--text-muted)', maxWidth: '520px', margin: '0 auto 1.25rem', fontSize: '0.875rem' }}>
                No se registraron interacciones en el flujo <strong>{currentFlow?.name || 'seleccionado'}</strong> para el rango de fechas elegido.
                Prueba ampliando el rango o interactúa con el bot desde Webchat o WhatsApp.
              </p>
              <button
                className="btn btn-secondary btn-sm"
                onClick={() => handleQuickRangeChange('30d')}
              >
                Ver últimos 30 días
              </button>
            </div>
          )}

          {/* Menu Sections Breakdown (Funnel) */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }} data-testid="menu-breakdown-container">
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                <Layers size={18} style={{ color: 'var(--primary)' }} />
                <h2 style={{ fontSize: '1.15rem', fontWeight: 600, margin: 0, color: 'var(--text-main)' }}>
                  Desglose de Tránsito por Menús y Secciones
                </h2>
              </div>
              <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                {analytics.menu_breakdown.length} menú(s) interactivo(s) configurado(s)
              </span>
            </div>

            {analytics.menu_breakdown.length === 0 ? (
              <div
                className="card"
                style={{
                  padding: '2rem',
                  textAlign: 'center',
                  color: 'var(--text-muted)',
                  background: 'var(--bg-card)',
                  borderRadius: 'var(--radius)',
                }}
              >
                <HelpCircle size={32} style={{ margin: '0 auto 0.5rem', opacity: 0.5 }} />
                <p style={{ margin: 0 }}>
                  Este flujo no contiene nodos interactivos de tipo <strong>Menú</strong> en su versión activa.
                </p>
              </div>
            ) : (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(420px, 1fr))', gap: '1.25rem' }}>
                {analytics.menu_breakdown.map((menu) => (
                  <div
                    key={menu.node_id || menu.node_key}
                    className="card"
                    data-testid={`menu-card-${menu.node_key}`}
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
                    {/* Menu Header */}
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid var(--border)', paddingBottom: '0.75rem' }}>
                      <div>
                        <h3 style={{ fontSize: '1rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                          {menu.node_title}
                        </h3>
                        <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                          Identificador: <code>{menu.node_key}</code>
                        </span>
                      </div>
                      <span className="badge badge-secondary" style={{ fontSize: '0.75rem', padding: '0.25rem 0.5rem' }}>
                        {menu.total_selections.toLocaleString()} clics totales
                      </span>
                    </div>

                    {/* Options list */}
                    {menu.options.length === 0 ? (
                      <p style={{ fontSize: '0.85rem', color: 'var(--text-muted)', margin: 0 }}>
                        No hay opciones configuradas en este menú.
                      </p>
                    ) : (
                      <div style={{ display: 'flex', flexDirection: 'column', gap: '0.85rem' }}>
                        {menu.options.map((opt, idx) => {
                          const isTop = opt.percentage > 0 && idx === 0 && opt.clicks_count > 0;
                          return (
                            <div
                              key={opt.option_id}
                              data-testid={`option-row-${opt.option_id}`}
                              style={{ display: 'flex', flexDirection: 'column', gap: '0.35rem' }}
                            >
                              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: '0.875rem' }}>
                                <span style={{ fontWeight: 600, color: 'var(--text-main)', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                                  <span>🔘</span>
                                  <span>{opt.title}</span>
                                  {isTop && (
                                    <span
                                      className="badge badge-warning"
                                      style={{ fontSize: '0.65rem', padding: '0.1rem 0.35rem' }}
                                    >
                                      Más elegida
                                    </span>
                                  )}
                                </span>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                                  <span style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>
                                    {opt.clicks_count.toLocaleString()} clics
                                  </span>
                                  <span
                                    style={{
                                      fontWeight: 700,
                                      fontSize: '0.85rem',
                                      minWidth: '45px',
                                      textAlign: 'right',
                                      color: opt.percentage > 0 ? 'var(--primary)' : 'var(--text-muted)',
                                    }}
                                  >
                                    {opt.percentage}%
                                  </span>
                                </div>
                              </div>

                              {/* Progress bar */}
                              <div
                                style={{
                                  width: '100%',
                                  height: '8px',
                                  background: '#eaf1ee',
                                  borderRadius: '9999px',
                                  overflow: 'hidden',
                                  border: '1px solid #d9e3de',
                                }}
                              >
                                <div
                                  style={{
                                    height: '100%',
                                    width: `${opt.percentage}%`,
                                    background: isTop
                                      ? 'linear-gradient(90deg, #5d8f82, #5d8f82)'
                                      : 'linear-gradient(90deg, #5d8f82, #5d8f82)',
                                    borderRadius: '9999px',
                                    transition: 'width 0.4s ease-out',
                                  }}
                                />
                              </div>
                            </div>
                          );
                        })}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Time Series Activity Table / Trend */}
          {analytics.time_series && analytics.time_series.length > 0 && (
            <div
              className="card"
              data-testid="time-series-card"
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
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                  <TrendingUp size={18} style={{ color: 'var(--primary)' }} />
                  <h3 style={{ fontSize: '1rem', fontWeight: 600, margin: 0, color: 'var(--text-main)' }}>
                    Tendencia de Sesiones en el Tiempo
                  </h3>
                </div>
                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                  Total de días analizados: {analytics.time_series.length}
                </span>
              </div>

              {/* Simple CSS Bar Chart Visualization */}
              <div
                style={{
                  display: 'flex',
                  alignItems: 'flex-end',
                  gap: '4px',
                  height: '100px',
                  padding: '0.5rem 0',
                  borderBottom: '1px solid var(--border)',
                  overflowX: 'auto',
                }}
                data-testid="trend-bars"
              >
                {(() => {
                  const maxExec = Math.max(...analytics.time_series.map((t) => t.executions), 1);
                  return analytics.time_series.map((point) => {
                    const heightPercent = Math.max(Math.round((point.executions / maxExec) * 85), point.executions > 0 ? 8 : 2);
                    return (
                      <div
                        key={point.date}
                        title={`${point.date}: ${point.executions} ejecuciones (${point.completed} completadas)`}
                        style={{
                          flex: 1,
                          minWidth: '12px',
                          display: 'flex',
                          flexDirection: 'column',
                          alignItems: 'center',
                          height: '100%',
                          justifyContent: 'flex-end',
                        }}
                      >
                        <div
                          style={{
                            width: '100%',
                            height: `${heightPercent}%`,
                            background: point.executions > 0 ? 'var(--primary)' : '#d9e3de',
                            borderRadius: '3px 3px 0 0',
                            transition: 'height 0.3s ease',
                          }}
                        />
                      </div>
                    );
                  });
                })()}
              </div>

              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                <span>Inicio: {analytics.time_series[0]?.date}</span>
                <span>Fin: {analytics.time_series[analytics.time_series.length - 1]?.date}</span>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
};

export default FlowAnalyticsView;
