import React, { useState, useEffect } from 'react';
import { api } from '../../services/api';
import { useAuth } from '../../context/useAuth';
import type { Channel, AgentMetricsData, User as UserType } from '../../types';
import {
  Download,
  Users,
  Filter,
  RefreshCw,
  Radio,
  AlertCircle,
  Calendar,
} from 'lucide-react';

interface AgentMetricsTabProps {
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

export const AgentMetricsTab: React.FC<AgentMetricsTabProps> = ({ onOpenConversation: _onOpenConversation }) => {
  const { user: authUser, hasRole, hasPermission } = useAuth();
  const isSupervisorOrAdmin = hasRole('super_admin') || hasRole('admin') || hasRole('supervisor') || hasPermission('conversations.view_all');
  const isAgentOnly = !isSupervisorOrAdmin;

  const [channels, setChannels] = useState<Channel[]>([]);
  const [users, setUsers] = useState<UserType[]>([]);
  const [selectedChannelId, setSelectedChannelId] = useState<string>('');
  const [selectedAgentId, setSelectedAgentId] = useState<string>('');
  const [onlineOnly, setOnlineOnly] = useState<boolean>(false);
  const [quickRange, setQuickRange] = useState<QuickRange>('today');
  const [customFrom, setCustomFrom] = useState<string>('');
  const [customTo, setCustomTo] = useState<string>('');
  const [isCustomDate, setIsCustomDate] = useState<boolean>(false);
  const [refreshTrigger, setRefreshTrigger] = useState<number>(0);
  const [lastUpdatedTime, setLastUpdatedTime] = useState<string>('');

  const [data, setData] = useState<AgentMetricsData | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  // Initialize channels and users
  useEffect(() => {
    let mounted = true;
    api.getChannels()
      .then((res) => {
        if (mounted) setChannels(res.data || []);
      })
      .catch(console.error);

    if (!isAgentOnly) {
      api.getUsers()
        .then((res) => {
          if (mounted) setUsers(res.data || []);
        })
        .catch(console.error);
    }

    return () => {
      mounted = false;
    };
  }, [isAgentOnly]);

  // Fetch agent metrics
  useEffect(() => {
    let mounted = true;

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

    const effectiveAgentId = isAgentOnly
      ? (authUser?.id ? String(authUser.id) : undefined)
      : (selectedAgentId || undefined);

    const effectiveOnlineOnly = isAgentOnly ? false : onlineOnly;

    api.getAgentMetrics({
      from,
      to,
      channel_id: selectedChannelId || undefined,
      agent_id: effectiveAgentId,
      online_only: effectiveOnlineOnly,
    })
      .then((res) => {
        if (!mounted) return;
        setData(res.data);
        setError(null);
        const now = new Date();
        setLastUpdatedTime(now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
      })
      .catch((err) => {
        console.error('Error fetching agent metrics:', err);
        if (mounted) setError('No se pudieron cargar las métricas de agentes.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });

    return () => {
      mounted = false;
    };
  }, [isAgentOnly, authUser?.id, selectedChannelId, selectedAgentId, onlineOnly, quickRange, customFrom, customTo, isCustomDate, refreshTrigger]);

  const handleQuickRangeChange = (range: QuickRange) => {
    setIsCustomDate(false);
    setQuickRange(range);
    setLoading(true);
  };

  const handleCustomDateApply = () => {
    if (customFrom && customTo) {
      setIsCustomDate(true);
      setLoading(true);
      setRefreshTrigger((prev) => prev + 1);
    }
  };

  const currentDates = isCustomDate && customFrom && customTo
    ? { from: customFrom, to: customTo }
    : computeRangeDates(quickRange);

  const effectiveAgentId = isAgentOnly
    ? (authUser?.id ? String(authUser.id) : undefined)
    : (selectedAgentId || undefined);

  const effectiveOnlineOnly = isAgentOnly ? false : onlineOnly;

  const exportSummaryUrl = api.getAgentsExportSummaryUrl({
    from: currentDates.from,
    to: currentDates.to,
    channel_id: selectedChannelId || undefined,
    agent_id: effectiveAgentId,
    online_only: effectiveOnlineOnly,
  });

  const exportConversationsUrl = api.getAgentsExportConversationsUrl({
    from: currentDates.from,
    to: currentDates.to,
    channel_id: selectedChannelId || undefined,
    agent_id: effectiveAgentId,
  });

  const trackingStartedDate = data?.presence_tracking_started_at?.slice(0, 10);
  const presenceTrackingNotice = !trackingStartedDate
    ? 'El tiempo conectado empezará a registrarse cuando un agente use Intera. No hay historial anterior.'
    : currentDates.from < trackingStartedDate
      ? `El registro de tiempo conectado está disponible desde ${new Date(`${trackingStartedDate}T12:00:00`).toLocaleDateString()}. Las fechas anteriores no tienen historial.`
      : null;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }} data-testid="agent-metrics-tab">
      {/* Top Filter and Action Bar */}
      <div
        className="card"
        style={{
          padding: '1.25rem',
          display: 'flex',
          flexDirection: 'column',
          gap: '1rem',
          background: 'var(--card-bg)',
          borderRadius: '12px',
        }}
      >
        <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: '1rem' }}>
          {/* Quick Date Pills */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', flexWrap: 'wrap' }}>
            <span style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', marginRight: '0.25rem' }}>
              Período:
            </span>
            <button
              className={`btn btn-sm ${!isCustomDate && quickRange === 'today' ? 'btn-primary' : 'btn-secondary'}`}
              onClick={() => handleQuickRangeChange('today')}
              style={{ fontSize: '0.8rem', padding: '0.35rem 0.65rem' }}
            >
              Hoy
            </button>
            <button
              className={`btn btn-sm ${!isCustomDate && quickRange === '7d' ? 'btn-primary' : 'btn-secondary'}`}
              onClick={() => handleQuickRangeChange('7d')}
              style={{ fontSize: '0.8rem', padding: '0.35rem 0.65rem' }}
            >
              Última Semana
            </button>
            <button
              className={`btn btn-sm ${!isCustomDate && quickRange === '30d' ? 'btn-primary' : 'btn-secondary'}`}
              onClick={() => handleQuickRangeChange('30d')}
              style={{ fontSize: '0.8rem', padding: '0.35rem 0.65rem' }}
            >
              Últimos 30 días
            </button>
            <button
              className={`btn btn-sm ${!isCustomDate && quickRange === 'month' ? 'btn-primary' : 'btn-secondary'}`}
              onClick={() => handleQuickRangeChange('month')}
              style={{ fontSize: '0.8rem', padding: '0.35rem 0.65rem' }}
            >
              Mes actual
            </button>
          </div>

          {/* Export Buttons */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', flexWrap: 'wrap' }}>
            {lastUpdatedTime && (
              <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginRight: '0.25rem' }}>
                Última actualización: <strong>{lastUpdatedTime}</strong>
              </span>
            )}
            <a
              href={exportSummaryUrl}
              download="metricas_agentes_resumen.csv"
              className="btn btn-primary btn-sm"
              style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.8rem', padding: '0.4rem 0.75rem' }}
              data-testid="export-agents-summary-btn"
            >
              <Download size={14} /> Descargar por Agente
            </a>
            <a
              href={exportConversationsUrl}
              download="conversaciones_detalle_agentes.csv"
              className="btn btn-primary btn-sm"
              style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.8rem', padding: '0.4rem 0.75rem' }}
              data-testid="export-conversations-detail-btn"
            >
              <Download size={14} /> Descargar por Conversación
            </a>
          </div>
        </div>

        {/* Filters Row: Channel, Agent, Online Only, Custom Date */}
        <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '1rem', borderTop: '1px solid var(--border)', paddingTop: '0.85rem' }}>
          {/* Agent selection or restricted agent badge */}
          {isAgentOnly ? (
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: '0.4rem',
                fontSize: '0.85rem',
                fontWeight: 600,
                color: 'var(--text-main)',
                background: 'rgba(0,0,0,0.05)',
                padding: '0.35rem 0.75rem',
                borderRadius: '8px',
              }}
              data-testid="agent-readonly-badge"
            >
              <Users size={15} style={{ color: 'var(--primary)' }} />
              <span>{authUser?.name || 'Mi Perfil'}</span>
            </div>
          ) : (
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
              <Users size={15} style={{ color: 'var(--text-muted)' }} />
              <select
                className="form-control"
                value={selectedAgentId}
                onChange={(e) => {
                  setLoading(true);
                  setSelectedAgentId(e.target.value);
                }}
                style={{ fontSize: '0.85rem', padding: '0.35rem 0.7rem', borderRadius: '8px', minWidth: '160px' }}
                data-testid="agent-select"
              >
                <option value="">Todos los Agentes</option>
                {users.map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.name}
                  </option>
                ))}
              </select>
            </div>
          )}

          {/* Channel dropdown */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
            <Filter size={15} style={{ color: 'var(--text-muted)' }} />
            <select
              className="form-control"
              value={selectedChannelId}
              onChange={(e) => {
                setLoading(true);
                setSelectedChannelId(e.target.value);
              }}
              style={{ fontSize: '0.85rem', padding: '0.35rem 0.7rem', borderRadius: '8px', minWidth: '160px' }}
              data-testid="channel-select"
            >
              <option value="">Todos los Canales</option>
              {channels.map((ch) => (
                <option key={ch.id} value={ch.id}>
                  {ch.name} ({ch.type.toUpperCase()})
                </option>
              ))}
            </select>
          </div>

          {/* Online only toggle (hidden for restricted agents) */}
          {!isAgentOnly && (
            <label style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.85rem', cursor: 'pointer', margin: 0 }}>
              <input
                type="checkbox"
                checked={onlineOnly}
                onChange={(e) => {
                  setLoading(true);
                  setOnlineOnly(e.target.checked);
                }}
                data-testid="online-only-checkbox"
              />
              <span style={{ color: 'var(--text-main)', fontWeight: 500 }}>Sólo agentes en línea</span>
            </label>
          )}

          {/* Custom Date Range Inputs */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', marginLeft: 'auto', flexWrap: 'wrap' }}>
            <Calendar size={14} style={{ color: 'var(--text-muted)' }} />
            <input
              type="date"
              className="form-control"
              value={customFrom}
              onChange={(e) => setCustomFrom(e.target.value)}
              style={{ fontSize: '0.8rem', padding: '0.3rem 0.5rem', width: '130px' }}
            />
            <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>al</span>
            <input
              type="date"
              className="form-control"
              value={customTo}
              onChange={(e) => setCustomTo(e.target.value)}
              style={{ fontSize: '0.8rem', padding: '0.3rem 0.5rem', width: '130px' }}
            />
            <button
              className="btn btn-secondary btn-sm"
              onClick={handleCustomDateApply}
              disabled={!customFrom || !customTo}
              style={{ fontSize: '0.8rem', padding: '0.3rem 0.6rem' }}
            >
              Aplicar
            </button>
          </div>

          {/* Refresh button */}
          <button
            className="btn btn-secondary btn-sm"
            onClick={() => {
              setLoading(true);
              setRefreshTrigger((p) => p + 1);
            }}
            style={{ padding: '0.35rem 0.6rem' }}
            title="Refrescar métricas"
          >
            <RefreshCw size={14} className={loading ? 'animate-spin' : ''} />
          </button>
        </div>
      </div>

      {/* Loading Skeleton */}
      {loading && !data && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem', padding: '2rem 0' }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '1rem' }}>
            {[1, 2, 3, 4].map((i) => (
              <div key={i} className="card" style={{ height: '100px', background: 'var(--border)', opacity: 0.4 }} />
            ))}
          </div>
          <div className="card" style={{ height: '240px', background: 'var(--border)', opacity: 0.3 }} />
        </div>
      )}

      {/* Error Alert */}
      {error && (
        <div className="alert alert-danger" style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {/* Content */}
      {data && (
        <>
          {presenceTrackingNotice && (
            <div className="alert alert-info" role="note" style={{ margin: 0 }}>
              {presenceTrackingNotice}
            </div>
          )}

          {/* Tarjetas KPI Consolidadas */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '1rem' }}>
            {/* KPI 1: Conversaciones con Agentes */}
            <div className="card" style={{ padding: '1.25rem', borderRadius: '12px', borderLeft: '4px solid #5d8f82' }}>
              <span style={{ fontSize: '0.8rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase' }}>
                Conversaciones con Agentes
              </span>
              <div style={{ fontSize: '2rem', fontWeight: 800, color: 'var(--text-main)', marginTop: '0.2rem' }}>
                {data.kpis.total_assigned.toLocaleString()}
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                <span>En curso: <strong style={{ color: '#5d8f82' }}>{data.kpis.total_active}</strong></span>
                <span>•</span>
                <span>Cerradas: <strong style={{ color: '#5d8f82' }}>{data.kpis.total_closed}</strong></span>
              </div>
            </div>

            {/* KPI 2: Espera Promedio 1ª Respuesta */}
            <div className="card" style={{ padding: '1.25rem', borderRadius: '12px', borderLeft: '4px solid #5d8f82' }}>
              <span style={{ fontSize: '0.8rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase' }}>
                Tiempo 1ª Respuesta (FRT)
              </span>
              <div style={{ fontSize: '2rem', fontWeight: 800, color: '#5d8f82', marginTop: '0.2rem' }}>
                {data.kpis.avg_first_response_formatted}
              </div>
              <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                Promedio de respuesta inicial por operador
              </span>
            </div>

            {/* KPI 3: Tiempo Medio de Respuesta */}
            <div className="card" style={{ padding: '1.25rem', borderRadius: '12px', borderLeft: '4px solid #06b6d4' }}>
              <span style={{ fontSize: '0.8rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase' }}>
                Tiempo Medio de Respuesta
              </span>
              <div style={{ fontSize: '2rem', fontWeight: 800, color: '#5d8f82', marginTop: '0.2rem' }}>
                {data.kpis.avg_response_time_formatted}
              </div>
              <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                Velocidad media de interacción en curso
              </span>
            </div>

            {/* KPI 4: Duración Media de Atención */}
            <div className="card" style={{ padding: '1.25rem', borderRadius: '12px', borderLeft: '4px solid #5d8f82' }}>
              <span style={{ fontSize: '0.8rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase' }}>
                Duración Media de Atención
              </span>
              <div style={{ fontSize: '2rem', fontWeight: 800, color: '#5d8f82', marginTop: '0.2rem' }}>
                {data.kpis.avg_attention_duration_formatted}
              </div>
              <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                Tiempo desde asignación hasta cierre
              </span>
            </div>

            {/* KPI 5: Presencia conectada estimada */}
            <div className="card" style={{ padding: '1.25rem', borderRadius: '12px', borderLeft: '4px solid #f59e0b' }}>
              <span style={{ fontSize: '0.8rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase' }}>
                Tiempo conectado · equipo
              </span>
              <div style={{ fontSize: '2rem', fontWeight: 800, color: 'var(--text-main)', marginTop: '0.2rem' }}>
                {data.kpis.total_connected_formatted ?? 'Sin datos'}
              </div>
              <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                Suma por agente con Intera abierta en pantalla; no mide atención activa.
              </span>
            </div>
          </div>

          {/* Tabla de Rendimiento por Operador */}
          <div className="card" style={{ padding: '1.25rem', borderRadius: '12px' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '1rem' }}>
              <div>
                <h3 style={{ fontSize: '1.05rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                  Rendimiento y Balance por Operador
                </h3>
                <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                  Detalle individual de asignaciones, resoluciones y presencia en Intera
                </span>
              </div>
              <span className="badge badge-secondary" style={{ fontSize: '0.8rem', padding: '0.35rem 0.65rem' }}>
                {data.agents.length} Operadores
              </span>
            </div>

            {data.agents.length === 0 ? (
              <p style={{ textAlign: 'center', color: 'var(--text-muted)', padding: '2.5rem 0', margin: 0 }}>
                No se encontraron agentes con los filtros seleccionados.
              </p>
            ) : (
              <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '0.875rem' }}>
                  <thead>
                    <tr style={{ borderBottom: '2px solid var(--border)', color: 'var(--text-muted)', fontSize: '0.75rem', textTransform: 'uppercase' }}>
                      <th style={{ padding: '0.75rem 0.5rem' }}>Agente</th>
                      <th style={{ padding: '0.75rem 0.5rem' }}>Canales</th>
                      <th style={{ padding: '0.75rem 0.5rem' }}>Último Acceso</th>
                      <th style={{ padding: '0.75rem 0.5rem', textAlign: 'center' }}>En Curso</th>
                      <th style={{ padding: '0.75rem 0.5rem', textAlign: 'center' }}>Cerradas (% Total)</th>
                      <th style={{ padding: '0.75rem 0.5rem', textAlign: 'center' }}>Esperando Resp.</th>
                      <th style={{ padding: '0.75rem 0.5rem', textAlign: 'right' }}>1ª Respuesta</th>
                      <th style={{ padding: '0.75rem 0.5rem', textAlign: 'right' }}>Duración Atención</th>
                      <th
                        style={{ padding: '0.75rem 0.5rem', textAlign: 'right' }}
                        title="Tiempo estimado con Intera abierta en pantalla y reportando presencia; no equivale a tiempo de atención activa."
                      >
                        Tiempo Conectado
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.agents.map((agent) => {
                      // Status dot color
                      let statusColor = '#80918c'; // offline
                      let statusText = 'Desconectado';
                      if (agent.presence_status === 'online') {
                        statusColor = '#5d8f82';
                        statusText = 'En Línea';
                      } else if (agent.presence_status === 'busy') {
                        statusColor = '#f97316';
                        statusText = 'Ocupado';
                      }

                      return (
                        <tr
                          key={agent.agent_id}
                          style={{ borderBottom: '1px solid var(--border)' }}
                          data-testid={`agent-row-${agent.agent_id}`}
                        >
                          {/* Agente con Avatar y Status */}
                          <td style={{ padding: '0.85rem 0.5rem' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '0.65rem' }}>
                              <div style={{ position: 'relative' }}>
                                <div
                                  style={{
                                    width: '34px',
                                    height: '34px',
                                    borderRadius: '50%',
                                    background: 'var(--primary)',
                                    color: '#ffffff',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    fontSize: '0.85rem',
                                    fontWeight: 700,
                                  }}
                                >
                                  {agent.name.charAt(0).toUpperCase()}
                                </div>
                                <span
                                  style={{
                                    position: 'absolute',
                                    bottom: '-1px',
                                    right: '-1px',
                                    width: '10px',
                                    height: '10px',
                                    borderRadius: '50%',
                                    background: statusColor,
                                    border: '2px solid var(--card-bg)',
                                  }}
                                  title={statusText}
                                />
                              </div>
                              <div>
                                <span style={{ fontWeight: 600, color: 'var(--text-main)', display: 'block' }}>
                                  {agent.name}
                                </span>
                                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                                  {agent.email}
                                </span>
                              </div>
                            </div>
                          </td>

                          {/* Canales */}
                          <td style={{ padding: '0.85rem 0.5rem' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', flexWrap: 'wrap' }}>
                              {agent.channels.length === 0 ? (
                                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>—</span>
                              ) : (
                                agent.channels.map((ch) => {
                                  const isWa = ch.type.toLowerCase() === 'whatsapp';
                                  return (
                                    <span
                                      key={ch.id}
                                      className="badge"
                                      style={{
                                        fontSize: '0.7rem',
                                        padding: '0.2rem 0.45rem',
                                        background: isWa ? '#eaf1ee' : '#eaf1ee',
                                        color: isWa ? '#263c38' : '#456c61',
                                        borderRadius: '8px',
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        gap: '0.2rem',
                                      }}
                                    >
                                      <Radio size={10} />
                                      {ch.type.toUpperCase()}
                                    </span>
                                  );
                                })
                              )}
                            </div>
                          </td>

                          {/* Último Acceso */}
                          <td style={{ padding: '0.85rem 0.5rem', fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                            {agent.last_seen_formatted}
                          </td>

                          {/* En Curso */}
                          <td style={{ padding: '0.85rem 0.5rem', textAlign: 'center' }}>
                            <span
                              className="badge"
                              style={{
                                background: agent.active_conversations > 0 ? '#eaf1ee' : 'var(--border)',
                                color: agent.active_conversations > 0 ? '#5d8f82' : 'var(--text-muted)',
                                fontWeight: 700,
                                padding: '0.25rem 0.6rem',
                                borderRadius: '12px',
                              }}
                            >
                              {agent.active_conversations}
                            </span>
                          </td>

                          {/* Cerradas con Porcentaje */}
                          <td style={{ padding: '0.85rem 0.5rem', textAlign: 'center' }}>
                            <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '0.2rem' }}>
                              <span style={{ fontWeight: 700, color: 'var(--text-main)' }}>
                                {agent.closed_conversations} <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>({agent.closed_percentage}%)</span>
                              </span>
                              {/* Micro Progress Bar */}
                              <div style={{ width: '60px', height: '4px', background: '#d9e3de', borderRadius: '2px', overflow: 'hidden' }}>
                                <div
                                  style={{
                                    width: `${Math.min(100, agent.closed_percentage)}%`,
                                    height: '100%',
                                    background: '#5d8f82',
                                  }}
                                />
                              </div>
                            </div>
                          </td>

                          {/* Esperando Respuesta */}
                          <td style={{ padding: '0.85rem 0.5rem', textAlign: 'center' }}>
                            <span
                              className="badge"
                              style={{
                                background: agent.waiting_response_count > 0 ? '#fef9c3' : '#eaf1ee',
                                color: agent.waiting_response_count > 0 ? '#ca8a04' : '#61726d',
                                fontWeight: 700,
                                padding: '0.25rem 0.6rem',
                                borderRadius: '12px',
                              }}
                            >
                              {agent.waiting_response_count}
                            </span>
                          </td>

                          {/* Tiempo 1ª Respuesta */}
                          <td style={{ padding: '0.85rem 0.5rem', textAlign: 'right', fontWeight: 600, color: 'var(--text-main)' }}>
                            {agent.avg_first_response_formatted}
                          </td>

                          {/* Duración Media Atención */}
                          <td style={{ padding: '0.85rem 0.5rem', textAlign: 'right', fontWeight: 600, color: 'var(--text-main)' }}>
                            {agent.avg_attention_duration_formatted}
                          </td>

                          {/* Tiempo Conectado */}
                          <td
                            style={{ padding: '0.85rem 0.5rem', textAlign: 'right', fontWeight: 600, color: 'var(--text-main)' }}
                            title="Estimado por los pulsos mientras Intera está abierta en pantalla; no mide atención activa."
                          >
                            {agent.connected_formatted ?? '—'}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
};
