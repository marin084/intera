import React, { useState, useEffect, useCallback } from 'react';
import { api } from '../../services/api';
import type { Channel, UsersAndSessionsData, Tag } from '../../types';
import {
  Download,
  Calendar,
  Radio,
  Filter,
  RefreshCw,
  Users,
  MessageSquare,
  UserCheck,
  PieChart,
  Activity,
  AlertCircle,
  Tag as TagIcon,
} from 'lucide-react';

interface UsersAndSessionsTabProps {
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

export const UsersAndSessionsTab: React.FC<UsersAndSessionsTabProps> = () => {
  const [channels, setChannels] = useState<Channel[]>([]);
  const [selectedChannelId, setSelectedChannelId] = useState<string>('');
  const [tags, setTags] = useState<Tag[]>([]);
  const [selectedTagId, setSelectedTagId] = useState<string>('');
  const [quickRange, setQuickRange] = useState<QuickRange>('30d');
  const [customFrom, setCustomFrom] = useState<string>('');
  const [customTo, setCustomTo] = useState<string>('');
  const [isCustomDate, setIsCustomDate] = useState<boolean>(false);
  const [refreshTrigger, setRefreshTrigger] = useState<number>(0);

  const [data, setData] = useState<UsersAndSessionsData | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  // Initialize channels and tags
  useEffect(() => {
    let mounted = true;
    api.getChannels()
      .then((res) => {
        if (mounted) setChannels(res.data || []);
      })
      .catch(console.error);

    if (typeof api.getTags === 'function') {
      api.getTags()
        .then((res) => {
          if (mounted) setTags(res.data || []);
        })
        .catch(console.error);
    }

    return () => {
      mounted = false;
    };
  }, []);

  // Fetch Users and Sessions Metrics
  useEffect(() => {
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

    api.getUsersAndSessions({
      from,
      to,
      channel_id: selectedChannelId || undefined,
      tag_id: selectedTagId || undefined,
    })
      .then((res) => {
        if (active) {
          setData(res.data);
          setError(null);
        }
      })
      .catch((err: any) => {
        if (active) {
          setError(err.message || 'Error al cargar las métricas de usuarios y sesiones.');
          setData(null);
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [selectedChannelId, selectedTagId, quickRange, isCustomDate, customFrom, customTo, refreshTrigger]);

  const handleRefresh = useCallback(() => {
    setLoading(true);
    setRefreshTrigger((prev) => prev + 1);
  }, []);

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

  // Export CSV URL
  const exportDates = isCustomDate && customFrom && customTo
    ? { from: customFrom, to: customTo }
    : computeRangeDates(quickRange);

  const exportUrl = api.getUsersAndSessionsExportUrl({
    from: exportDates.from,
    to: exportDates.to,
    channel_id: selectedChannelId || undefined,
    tag_id: selectedTagId || undefined,
  });

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1.5rem' }} data-testid="users-and-sessions-tab">
      {/* Filters Bar & Export Button */}
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
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '0.75rem' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', color: 'var(--text-muted)', fontSize: '0.875rem', fontWeight: 600 }}>
            <Filter size={16} /> Filtros de Segmentación de Sesiones
          </div>

          <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
            <a
              href={exportUrl}
              download
              className="btn btn-primary btn-sm"
              data-testid="export-users-btn"
              style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', textDecoration: 'none', fontWeight: 600 }}
              title="Descargar listado completo de contactos y sesiones en formato CSV"
            >
              <Download size={14} /> Descargar usuarios
            </a>
            <button
              className="btn btn-secondary btn-sm"
              onClick={handleRefresh}
              disabled={loading}
              title="Recargar métricas"
              data-testid="refresh-sessions-btn"
            >
              <RefreshCw size={14} className={loading ? 'animate-spin' : ''} />
              Actualizar
            </button>
          </div>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: '1rem' }}>
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

          {/* Tag Selector */}
          <div>
            <label style={{ display: 'block', fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', marginBottom: '0.35rem' }}>
              <TagIcon size={13} style={{ display: 'inline', marginRight: '4px' }} /> Etiqueta / Tema
            </label>
            <select
              value={selectedTagId}
              onChange={(e) => {
                setLoading(true);
                setSelectedTagId(e.target.value);
              }}
              className="form-control"
              data-testid="analytics-tag-filter"
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
              <option value="">Todas las etiquetas (Sin filtro)</option>
              {tags.map((tag) => (
                <option key={tag.id} value={tag.id}>
                  {tag.name}
                </option>
              ))}
            </select>
          </div>

          {/* Quick Date Range Buttons */}
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

          {data?.date_range && (
            <div style={{ marginLeft: 'auto', fontSize: '0.8rem', color: 'var(--text-muted)' }}>
              Mostrando métricas del <strong>{data.date_range.from_formatted || data.date_range.from.slice(0, 10)}</strong> al{' '}
              <strong>{data.date_range.to_formatted || data.date_range.to.slice(0, 10)}</strong>
            </div>
          )}
        </div>
      </div>

      {/* Error Message */}
      {error && (
        <div
          data-testid="sessions-error"
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
      {loading && (
        <div data-testid="sessions-loading" style={{ padding: '3rem', textAlign: 'center', color: 'var(--text-muted)' }}>
          <Activity className="animate-spin" size={32} style={{ margin: '0 auto 0.75rem', color: 'var(--primary)' }} />
          <p>Calculando volúmenes de sesiones, mensajes y automatización...</p>
        </div>
      )}

      {/* Main Dashboard Content */}
      {!loading && data && (
        <>
          {/* Top KPI Cards (Totales Generales, Sesiones con Agentes, Cantidad de Mensajes) */}
          <div
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))',
              gap: '1.25rem',
            }}
            data-testid="kpi-cards-container"
          >
            {/* Card 1: Totales Generales */}
            <div
              className="card"
              data-testid="kpi-totales-generales"
              style={{
                padding: '1.5rem',
                background: 'var(--bg-card)',
                borderRadius: 'var(--radius)',
                boxShadow: 'var(--shadow)',
                display: 'flex',
                flexDirection: 'column',
                gap: '1rem',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <span style={{ fontSize: '0.85rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                  Totales Generales
                </span>
                <div style={{ width: '38px', height: '38px', borderRadius: '10px', background: 'var(--primary-light)', color: 'var(--primary)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                  <Users size={20} />
                </div>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem', borderTop: '1px solid var(--border)', paddingTop: '0.75rem' }}>
                <div>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Sesiones Totales</span>
                  <div style={{ fontSize: '1.85rem', fontWeight: 800, color: 'var(--text-main)', marginTop: '0.2rem' }}>
                    {data.kpis.total_sessions.toLocaleString()}
                  </div>
                </div>

                <div>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Usuarios Únicos</span>
                  <div style={{ fontSize: '1.85rem', fontWeight: 800, color: '#263c38', marginTop: '0.2rem' }}>
                    {data.kpis.unique_contacts.toLocaleString()}
                  </div>
                </div>
              </div>
            </div>

            {/* Card 2: Sesiones con Agentes */}
            <div
              className="card"
              data-testid="kpi-sesiones-agentes"
              style={{
                padding: '1.5rem',
                background: 'var(--bg-card)',
                borderRadius: 'var(--radius)',
                boxShadow: 'var(--shadow)',
                display: 'flex',
                flexDirection: 'column',
                gap: '1rem',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <span style={{ fontSize: '0.85rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                  Sesiones con Agentes
                </span>
                <div style={{ width: '38px', height: '38px', borderRadius: '10px', background: '#eaf1ee', color: '#5d8f82', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                  <UserCheck size={20} />
                </div>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem', borderTop: '1px solid var(--border)', paddingTop: '0.75rem' }}>
                <div>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Atendidas por Humanos</span>
                  <div style={{ fontSize: '1.85rem', fontWeight: 800, color: '#263c38', marginTop: '0.2rem' }}>
                    {data.kpis.agent_sessions.toLocaleString()}
                  </div>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    ({data.kpis.agent_session_percentage}% del total)
                  </span>
                </div>

                <div>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Cerradas por Agentes</span>
                  <div style={{ fontSize: '1.85rem', fontWeight: 800, color: '#5d8f82', marginTop: '0.2rem' }}>
                    {data.kpis.closed_by_agents.toLocaleString()}
                  </div>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    Resueltas en Inbox
                  </span>
                </div>
              </div>
            </div>

            {/* Card 3: Cantidad de Mensajes */}
            <div
              className="card"
              data-testid="kpi-cantidad-mensajes"
              style={{
                padding: '1.5rem',
                background: 'var(--bg-card)',
                borderRadius: 'var(--radius)',
                boxShadow: 'var(--shadow)',
                display: 'flex',
                flexDirection: 'column',
                gap: '1rem',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <div>
                  <span style={{ fontSize: '0.85rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                    Cantidad de Mensajes
                  </span>
                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    {data.kpis.message_counts.total_messages.toLocaleString()} mensajes totales
                  </div>
                </div>
                <div style={{ width: '38px', height: '38px', borderRadius: '10px', background: '#eaf1ee', color: '#5d8f82', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                  <MessageSquare size={20} />
                </div>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '0.5rem', borderTop: '1px solid var(--border)', paddingTop: '0.75rem' }}>
                <div>
                  <span style={{ fontSize: '0.7rem', color: 'var(--text-muted)', display: 'block' }}>Por Usuarios</span>
                  <div style={{ fontSize: '1.35rem', fontWeight: 700, color: 'var(--text-main)', marginTop: '0.15rem' }}>
                    {data.kpis.message_counts.user_messages.toLocaleString()}
                  </div>
                </div>

                <div>
                  <span style={{ fontSize: '0.7rem', color: 'var(--text-muted)', display: 'block' }}>Por Bot</span>
                  <div style={{ fontSize: '1.35rem', fontWeight: 700, color: 'var(--primary)', marginTop: '0.15rem' }}>
                    {data.kpis.message_counts.bot_messages.toLocaleString()}
                  </div>
                </div>

                <div>
                  <span style={{ fontSize: '0.7rem', color: 'var(--text-muted)', display: 'block' }}>Por Agentes</span>
                  <div style={{ fontSize: '1.35rem', fontWeight: 700, color: '#5d8f82', marginTop: '0.15rem' }}>
                    {data.kpis.message_counts.agent_messages.toLocaleString()}
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* Bottom Visual Distribution Section */}
          <div
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(420px, 1fr))',
              gap: '1.25rem',
            }}
          >
            {/* Chart 1: % de Sesiones con Agentes (Bot Only vs Agent) */}
            <div
              className="card"
              data-testid="chart-agent-sessions"
              style={{
                padding: '1.5rem',
                background: 'var(--bg-card)',
                borderRadius: 'var(--radius)',
                boxShadow: 'var(--shadow)',
                display: 'flex',
                flexDirection: 'column',
                gap: '1.25rem',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid var(--border)', paddingBottom: '0.75rem' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                  <PieChart size={18} style={{ color: 'var(--primary)' }} />
                  <h3 style={{ fontSize: '1.05rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                    % de Sesiones con Agentes
                  </h3>
                </div>
                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                  Automatización vs. Humano
                </span>
              </div>

              {/* Visual representation */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                {/* Stacked bar visual */}
                <div
                  style={{
                    width: '100%',
                    height: '24px',
                    borderRadius: '8px',
                    overflow: 'hidden',
                    display: 'flex',
                    background: '#d9e3de',
                  }}
                >
                  <div
                    style={{
                      width: `${data.kpis.bot_only_percentage}%`,
                      background: 'linear-gradient(90deg, #5d8f82, #5d8f82)',
                      transition: 'width 0.5s ease',
                    }}
                    title={`Sólo Bots: ${data.kpis.bot_only_percentage}% (${data.kpis.bot_only_sessions})`}
                  />
                  <div
                    style={{
                      width: `${data.kpis.agent_session_percentage}%`,
                      background: 'linear-gradient(90deg, #5d8f82, #5d8f82)',
                      transition: 'width 0.5s ease',
                    }}
                    title={`Agentes: ${data.kpis.agent_session_percentage}% (${data.kpis.agent_sessions})`}
                  />
                </div>

                {/* Legend & Details */}
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
                  <div
                    style={{
                      padding: '0.85rem',
                      borderRadius: 'var(--radius)',
                      background: 'var(--bg-main)',
                      border: '1px solid var(--border)',
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.35rem' }}>
                      <span style={{ width: '10px', height: '10px', borderRadius: '50%', background: '#5d8f82' }} />
                      <span style={{ fontSize: '0.85rem', fontWeight: 600, color: 'var(--text-main)' }}>Sólo Bots (100% Automático)</span>
                    </div>
                    <div style={{ fontSize: '1.4rem', fontWeight: 800, color: 'var(--primary)' }}>
                      {data.kpis.bot_only_percentage}%
                    </div>
                    <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                      {data.kpis.bot_only_sessions.toLocaleString()} sesiones resueltas por bot
                    </span>
                  </div>

                  <div
                    style={{
                      padding: '0.85rem',
                      borderRadius: 'var(--radius)',
                      background: 'var(--bg-main)',
                      border: '1px solid var(--border)',
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.35rem' }}>
                      <span style={{ width: '10px', height: '10px', borderRadius: '50%', background: '#5d8f82' }} />
                      <span style={{ fontSize: '0.85rem', fontWeight: 600, color: 'var(--text-main)' }}>Con Intervención de Agentes</span>
                    </div>
                    <div style={{ fontSize: '1.4rem', fontWeight: 800, color: '#5d8f82' }}>
                      {data.kpis.agent_session_percentage}%
                    </div>
                    <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                      {data.kpis.agent_sessions.toLocaleString()} sesiones derivadas a operadores
                    </span>
                  </div>
                </div>
              </div>
            </div>

            {/* Chart 2: Distribución por Canal (Webchat vs WhatsApp) */}
            <div
              className="card"
              data-testid="chart-channel-distribution"
              style={{
                padding: '1.5rem',
                background: 'var(--bg-card)',
                borderRadius: 'var(--radius)',
                boxShadow: 'var(--shadow)',
                display: 'flex',
                flexDirection: 'column',
                gap: '1.25rem',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid var(--border)', paddingBottom: '0.75rem' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                  <Radio size={18} style={{ color: 'var(--primary)' }} />
                  <h3 style={{ fontSize: '1.05rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                    Distribución por Canal
                  </h3>
                </div>
                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                  Volumen según punto de contacto
                </span>
              </div>

              {data.channel_distribution.length === 0 ? (
                <p style={{ textAlign: 'center', color: 'var(--text-muted)', padding: '2rem 0', margin: 0 }}>
                  Sin sesiones registradas en los canales activos.
                </p>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                  {data.channel_distribution.map((ch) => {
                    const isWa = ch.channel_type.toLowerCase() === 'whatsapp';
                    const barColor = isWa ? '#25d366' : 'var(--primary)';

                    return (
                      <div
                        key={ch.channel_id}
                        data-testid={`channel-distribution-row-${ch.channel_type}`}
                        style={{ display: 'flex', flexDirection: 'column', gap: '0.35rem' }}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: '0.875rem' }}>
                          <span style={{ fontWeight: 600, color: 'var(--text-main)', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                            <Radio size={14} style={{ color: barColor }} />
                            <span>{ch.channel_name} ({ch.channel_type.toUpperCase()})</span>
                          </span>

                          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                            <span style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>
                              {ch.count.toLocaleString()} sesiones
                            </span>
                            <span style={{ fontWeight: 700, fontSize: '0.9rem', minWidth: '45px', textAlign: 'right', color: 'var(--text-main)' }}>
                              {ch.percentage}%
                            </span>
                          </div>
                        </div>

                        <div
                          style={{
                            width: '100%',
                            height: '10px',
                            background: '#d9e3de',
                            borderRadius: '9999px',
                            overflow: 'hidden',
                          }}
                        >
                          <div
                            style={{
                              height: '100%',
                              width: `${ch.percentage}%`,
                              background: barColor,
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

            {/* Chart 3: Temas más mencionados (Etiquetas / Tags) */}
            <div
              className="card"
              data-testid="chart-topics-distribution"
              style={{
                padding: '1.5rem',
                background: 'var(--bg-card)',
                borderRadius: 'var(--radius)',
                boxShadow: 'var(--shadow)',
                display: 'flex',
                flexDirection: 'column',
                gap: '1.25rem',
                gridColumn: '1 / -1',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid var(--border)', paddingBottom: '0.75rem', flexWrap: 'wrap', gap: '0.5rem' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                  <TagIcon size={18} style={{ color: 'var(--primary)' }} />
                  <h3 style={{ fontSize: '1.05rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                    Temas más mencionados
                  </h3>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '1rem', fontSize: '0.8rem' }}>
                  <span
                    data-testid="topics-untagged-count"
                    style={{
                      background: 'var(--bg-hover, #eaf1ee)',
                      color: 'var(--text-muted)',
                      padding: '0.2rem 0.5rem',
                      borderRadius: '4px',
                      fontWeight: 600,
                    }}
                  >
                    Sin tema: {data.untagged_count ?? 0} ({data.untagged_percentage ?? 0}%)
                  </span>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    Conversaciones clasificadas por etiquetas
                  </span>
                </div>
              </div>

              {(!data.topics_distribution || data.topics_distribution.length === 0) ? (
                <p style={{ textAlign: 'center', color: 'var(--text-muted)', padding: '2rem 0', margin: 0 }}>
                  Aún no se han etiquetado conversaciones en el período seleccionado.
                </p>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                  {data.topics_distribution.map((topic) => {
                    const barColor = topic.color || '#5d8f82';

                    return (
                      <div
                        key={topic.tag_id}
                        data-testid={`topic-row-${topic.tag_id}`}
                        style={{ display: 'flex', flexDirection: 'column', gap: '0.35rem' }}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: '0.875rem' }}>
                          <span style={{ fontWeight: 600, color: 'var(--text-main)', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                            <span
                              style={{
                                width: '10px',
                                height: '10px',
                                borderRadius: '50%',
                                backgroundColor: barColor,
                                display: 'inline-block',
                              }}
                            />
                            <span>{topic.name}</span>
                          </span>

                          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                            <span style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>
                              {topic.count.toLocaleString()} conversaciones
                            </span>
                            <span style={{ fontWeight: 700, fontSize: '0.9rem', minWidth: '45px', textAlign: 'right', color: 'var(--text-main)' }}>
                              {topic.percentage}%
                            </span>
                          </div>
                        </div>

                        <div
                          style={{
                            width: '100%',
                            height: '10px',
                            background: '#d9e3de',
                            borderRadius: '9999px',
                            overflow: 'hidden',
                          }}
                        >
                          <div
                            style={{
                              height: '100%',
                              width: `${topic.percentage}%`,
                              background: barColor,
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
          </div>
        </>
      )}
    </div>
  );
};
