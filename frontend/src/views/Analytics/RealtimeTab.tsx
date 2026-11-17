import React, { useState, useEffect, useCallback, useRef } from 'react';
import { api } from '../../services/api';
import type { Channel, RealtimeData } from '../../types';
import {
  UserCheck,
  AlertTriangle,
  Clock,
  Send,
  Radio,
  RefreshCw,
  Activity,
  Filter,
  TrendingUp,
  TrendingDown,
  AlertCircle,
} from 'lucide-react';

interface RealtimeTabProps {
  onOpenConversation?: (conversationId: string) => void;
}

export const RealtimeTab: React.FC<RealtimeTabProps> = ({ onOpenConversation: _onOpenConversation }) => {
  const [channels, setChannels] = useState<Channel[]>([]);
  const [selectedChannelId, setSelectedChannelId] = useState<string>('');
  const [autoRefresh, setAutoRefresh] = useState<boolean>(true);
  const [lastRefreshedAt, setLastRefreshedAt] = useState<string>('');
  const [refreshCountdown, setRefreshCountdown] = useState<number>(30);

  const [data, setData] = useState<RealtimeData | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [isRefreshing, setIsRefreshing] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const countdownRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Load channels on mount
  useEffect(() => {
    let mounted = true;
    api.getChannels()
      .then((res) => {
        if (mounted) setChannels(res.data || []);
      })
      .catch(console.error);

    return () => {
      mounted = false;
    };
  }, []);

  // Fetch realtime data
  const fetchData = useCallback(() => {
    let mounted = true;

    api.getRealtimeAnalytics({
      channel_id: selectedChannelId || undefined,
    })
      .then((res) => {
        if (!mounted) return;
        setData(res.data);
        setError(null);
        const now = new Date();
        setLastRefreshedAt(now.toLocaleTimeString());
        setRefreshCountdown(30);
      })
      .catch((err) => {
        if (!mounted) return;
        console.error('Error fetching realtime analytics:', err);
        setError('No se pudieron obtener las métricas en tiempo real.');
      })
      .finally(() => {
        if (mounted) {
          setLoading(false);
          setIsRefreshing(false);
        }
      });

    return () => {
      mounted = false;
    };
  }, [selectedChannelId]);

  // Initial fetch and on channel change
  useEffect(() => {
    const cancel = fetchData();
    return () => {
      if (cancel) cancel();
    };
  }, [fetchData]);

  // Handle 30s auto-refresh
  useEffect(() => {
    if (!autoRefresh) {
      if (timerRef.current) clearInterval(timerRef.current);
      if (countdownRef.current) clearInterval(countdownRef.current);
      return;
    }

    countdownRef.current = setInterval(() => {
      setRefreshCountdown((prev) => (prev > 1 ? prev - 1 : 30));
    }, 1000);

    timerRef.current = setInterval(() => {
      setIsRefreshing(true);
      fetchData();
    }, 30000);

    return () => {
      if (timerRef.current) clearInterval(timerRef.current);
      if (countdownRef.current) clearInterval(countdownRef.current);
    };
  }, [autoRefresh, fetchData]);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }} data-testid="realtime-tab">
      {/* Top Filter & Live Control Bar */}
      <div
        className="card"
        style={{
          padding: '1rem 1.25rem',
          display: 'flex',
          flexWrap: 'wrap',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: '1rem',
          background: 'var(--card-bg)',
          borderRadius: '12px',
          boxShadow: '0 1px 3px rgba(0,0,0,0.05)',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: '1rem', flexWrap: 'wrap' }}>
          {/* Channel selector */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.45rem' }}>
            <Filter size={15} style={{ color: 'var(--text-muted)' }} />
            <select
              className="form-control"
              value={selectedChannelId}
              onChange={(e) => {
                setLoading(true);
                setSelectedChannelId(e.target.value);
              }}
              style={{
                fontSize: '0.85rem',
                padding: '0.4rem 0.75rem',
                borderRadius: '8px',
                minWidth: '180px',
              }}
              data-testid="realtime-channel-select"
            >
              <option value="">Todos los Canales</option>
              {channels.map((ch) => (
                <option key={ch.id} value={ch.id}>
                  {ch.name} ({ch.type.toUpperCase()})
                </option>
              ))}
            </select>
          </div>

          {/* Live indicator badge */}
          <div
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '0.45rem',
              padding: '0.35rem 0.75rem',
              borderRadius: '20px',
              background: autoRefresh ? '#eaf1ee' : '#eaf1ee',
              color: autoRefresh ? '#263c38' : '#61726d',
              fontSize: '0.8rem',
              fontWeight: 600,
            }}
          >
            <span
              style={{
                width: '8px',
                height: '8px',
                borderRadius: '50%',
                background: autoRefresh ? '#5d8f82' : '#80918c',
                boxShadow: autoRefresh ? '0 0 8px #5d8f82' : 'none',
              }}
            />
            <span>{autoRefresh ? `En Vivo (Refresco en ${refreshCountdown}s)` : 'Refresco Pausado'}</span>
          </div>
        </div>

        {/* Right side controls */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '1rem' }}>
          {lastRefreshedAt && (
            <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
              Última actualización: <strong>{lastRefreshedAt}</strong>
            </span>
          )}

          {/* Auto refresh toggle button */}
          <button
            className={`btn ${autoRefresh ? 'btn-secondary' : 'btn-outline'}`}
            onClick={() => setAutoRefresh(!autoRefresh)}
            style={{ fontSize: '0.8rem', padding: '0.35rem 0.7rem' }}
            title={autoRefresh ? 'Pausar auto-refresco' : 'Activar auto-refresco'}
          >
            {autoRefresh ? 'Pausar' : 'Reanudar'}
          </button>

          {/* Manual refresh button */}
          <button
            className="btn btn-secondary"
            onClick={() => {
              setIsRefreshing(true);
              fetchData();
            }}
            disabled={isRefreshing}
            style={{ fontSize: '0.8rem', padding: '0.35rem 0.75rem', display: 'flex', alignItems: 'center', gap: '0.4rem' }}
            title="Actualizar datos ahora"
          >
            <RefreshCw size={14} className={isRefreshing ? 'animate-spin' : ''} />
            <span>Refrescar</span>
          </button>
        </div>
      </div>

      {/* Loading Skeleton */}
      {loading && !data && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem', padding: '2rem 0' }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '1rem' }}>
            {[1, 2, 3, 4].map((i) => (
              <div key={i} className="card" style={{ height: '110px', background: 'var(--border)', opacity: 0.4 }} />
            ))}
          </div>
          <div className="card" style={{ height: '200px', background: 'var(--border)', opacity: 0.3 }} />
        </div>
      )}

      {/* Error Banner */}
      {error && (
        <div className="alert alert-danger" style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {/* Dashboard Main Content */}
      {data && (
        <>
          {/* Bloque Superior de Tarjetas KPI */}
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.85rem' }}>
              <Clock size={16} style={{ color: 'var(--primary)' }} />
              <h2 style={{ fontSize: '1rem', fontWeight: 700, margin: 0, color: 'var(--text-main)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                Métricas de la Última Hora
              </h2>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: '1rem' }}>
              {/* Card 1: Visitantes Webchat */}
              <div
                className="card"
                style={{
                  padding: '1.25rem',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  borderRadius: '12px',
                  background: 'var(--card-bg)',
                  borderLeft: '4px solid #5d8f82',
                }}
              >
                <div>
                  <span style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)' }}>
                    Usuarios Conectados Webchat
                  </span>
                  <div style={{ fontSize: '2rem', fontWeight: 800, color: 'var(--text-main)', marginTop: '0.2rem' }}>
                    {data.last_hour_kpis.active_webchat_visitors.toLocaleString()}
                  </div>
                  <span style={{ fontSize: '0.75rem', color: '#5d8f82', display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
                    <Activity size={12} /> Activos en los últimos 15 min
                  </span>
                </div>
                <div style={{ width: '46px', height: '46px', borderRadius: '12px', background: '#eaf1ee', color: '#5d8f82', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                  <UserCheck size={24} />
                </div>
              </div>

              {/* Card 2: Chats No Leídos / Pendientes */}
              <div
                className="card"
                style={{
                  padding: '1.25rem',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  borderRadius: '12px',
                  background: 'var(--card-bg)',
                  borderLeft: '4px solid #eab308',
                }}
              >
                <div>
                  <span style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)' }}>
                    Chats Pendientes de Respuesta
                  </span>
                  <div style={{ fontSize: '2rem', fontWeight: 800, color: data.last_hour_kpis.unread_chats > 0 ? '#ca8a04' : 'var(--text-main)', marginTop: '0.2rem' }}>
                    {data.last_hour_kpis.unread_chats.toLocaleString()}
                  </div>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    En cola o mensaje entrante sin atender
                  </span>
                </div>
                <div style={{ width: '46px', height: '46px', borderRadius: '12px', background: '#fef9c3', color: '#ca8a04', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                  <AlertTriangle size={24} />
                </div>
              </div>

              {/* Card 3: Chats en la Última Hora */}
              <div
                className="card"
                style={{
                  padding: '1.25rem',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  borderRadius: '12px',
                  background: 'var(--card-bg)',
                  borderLeft: '4px solid #5d8f82',
                }}
              >
                <div>
                  <span style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)' }}>
                    Chats Iniciados (Última Hora)
                  </span>
                  <div style={{ fontSize: '2rem', fontWeight: 800, color: 'var(--text-main)', marginTop: '0.2rem' }}>
                    {data.last_hour_kpis.chats_last_hour.toLocaleString()}
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.75rem' }}>
                    {data.last_hour_kpis.chats_trend_percentage >= 0 ? (
                      <span style={{ color: '#5d8f82', fontWeight: 600, display: 'inline-flex', alignItems: 'center', gap: '0.15rem' }}>
                        <TrendingUp size={13} /> +{data.last_hour_kpis.chats_trend_percentage}%
                      </span>
                    ) : (
                      <span style={{ color: '#dc2626', fontWeight: 600, display: 'inline-flex', alignItems: 'center', gap: '0.15rem' }}>
                        <TrendingDown size={13} /> {data.last_hour_kpis.chats_trend_percentage}%
                      </span>
                    )}
                    <span style={{ color: 'var(--text-muted)' }}>vs. hora anterior ({data.last_hour_kpis.chats_previous_hour})</span>
                  </div>
                </div>
                <div style={{ width: '46px', height: '46px', borderRadius: '12px', background: '#f3e8ff', color: '#5d8f82', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                  <Radio size={24} />
                </div>
              </div>

              {/* Card 4: Mensajes Enviados / Recibidos */}
              <div
                className="card"
                style={{
                  padding: '1.25rem',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  borderRadius: '12px',
                  background: 'var(--card-bg)',
                  borderLeft: '4px solid #5d8f82',
                }}
              >
                <div>
                  <span style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)' }}>
                    Mensajes Enviados / Recibidos
                  </span>
                  <div style={{ fontSize: '2rem', fontWeight: 800, color: 'var(--text-main)', marginTop: '0.2rem' }}>
                    {data.last_hour_kpis.messages_last_hour.sent.toLocaleString()} / {data.last_hour_kpis.messages_last_hour.received.toLocaleString()}
                  </div>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    Total: <strong>{data.last_hour_kpis.messages_last_hour.total.toLocaleString()}</strong> mensajes en 60m
                  </span>
                </div>
                <div style={{ width: '46px', height: '46px', borderRadius: '12px', background: '#eaf1ee', color: '#5d8f82', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                  <Send size={24} />
                </div>
              </div>
            </div>
          </div>

          {/* Bloque Secundario: Tiempos y Rendimiento Operativo */}
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.85rem' }}>
              <Activity size={16} style={{ color: 'var(--primary)' }} />
              <h2 style={{ fontSize: '1rem', fontWeight: 700, margin: 0, color: 'var(--text-main)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                Tiempos y Estado de Operación
              </h2>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '1rem' }}>
              {/* Panel 1: Conversaciones con Agentes */}
              <div className="card" style={{ padding: '1.25rem', borderRadius: '12px' }}>
                <span style={{ fontSize: '0.8rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase' }}>
                  Conversaciones con Agentes
                </span>

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem', marginTop: '1rem' }}>
                  <div>
                    <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>En Curso</span>
                    <div style={{ fontSize: '1.6rem', fontWeight: 800, color: '#5d8f82' }}>
                      {data.agent_conversations.ongoing.toLocaleString()}
                    </div>
                    <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Atendidas ahora</span>
                  </div>

                  <div>
                    <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Cerradas Hoy</span>
                    <div style={{ fontSize: '1.6rem', fontWeight: 800, color: '#5d8f82' }}>
                      {data.agent_conversations.closed_today.toLocaleString()}
                    </div>
                    <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Finalizadas por agentes</span>
                  </div>
                </div>

                <div style={{ borderTop: '1px solid var(--border)', marginTop: '1rem', paddingTop: '0.75rem', display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: '0.8rem' }}>
                  <span style={{ color: 'var(--text-muted)' }}>Operadores en línea:</span>
                  <span style={{ fontWeight: 700, color: 'var(--text-main)', display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                    <span style={{ width: '8px', height: '8px', borderRadius: '50%', background: '#5d8f82' }} />
                    {data.agent_conversations.active_agents_count} conectados
                  </span>
                </div>
              </div>

              {/* Panel 2: Espera Promedio del Usuario */}
              <div className="card" style={{ padding: '1.25rem', borderRadius: '12px' }}>
                <span style={{ fontSize: '0.8rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase' }}>
                  Espera Promedio del Usuario
                </span>

                <div style={{ marginTop: '1rem' }}>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Tiempo de Espera en Cola</span>
                  <div style={{ fontSize: '1.8rem', fontWeight: 800, color: 'var(--text-main)', marginTop: '0.15rem' }}>
                    {data.operating_times.avg_queue_wait_formatted}
                  </div>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    Desde solicitud hasta que un agente toma el chat
                  </span>
                </div>

                <div style={{ borderTop: '1px solid var(--border)', marginTop: '1rem', paddingTop: '0.75rem', display: 'grid', gap: '0.45rem', fontSize: '0.8rem' }}>
                  <span style={{ color: 'var(--text-muted)' }}>Estado de las metas de cola por equipo</span>
                  {(data.service_target_alerts?.queue.tracked ?? 0) === 0 ? (
                    <span style={{ color: 'var(--text-muted)' }}>No hay conversaciones esperando asignación.</span>
                  ) : (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.4rem' }}>
                      <span className="badge badge-warning">Por vencer: {data.service_target_alerts?.queue.at_risk ?? 0}</span>
                      <span className="badge badge-danger">Vencidas: {data.service_target_alerts?.queue.overdue ?? 0}</span>
                    </div>
                  )}
                </div>
              </div>

              {/* Panel 3: Respuestas de Agentes */}
              <div className="card" style={{ padding: '1.25rem', borderRadius: '12px' }}>
                <span style={{ fontSize: '0.8rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase' }}>
                  Respuestas de Agentes
                </span>

                <div style={{ marginTop: '1rem' }}>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Primera Respuesta (FRT)</span>
                  <div style={{ fontSize: '1.8rem', fontWeight: 800, color: '#5d8f82', marginTop: '0.15rem' }}>
                    {data.operating_times.avg_first_response_formatted}
                  </div>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    Desde asignación hasta el primer saludo del operador
                  </span>
                </div>

                <div style={{ borderTop: '1px solid var(--border)', marginTop: '1rem', paddingTop: '0.75rem', display: 'grid', gap: '0.45rem', fontSize: '0.8rem' }}>
                  <span style={{ color: 'var(--text-muted)' }}>Primera respuesta pendiente · metas por equipo</span>
                  {(data.service_target_alerts?.first_response.tracked ?? 0) === 0 ? (
                    <span style={{ color: 'var(--text-muted)' }}>No hay conversaciones asignadas pendientes de respuesta inicial.</span>
                  ) : (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.4rem' }}>
                      <span className="badge badge-warning">Por vencer: {data.service_target_alerts?.first_response.at_risk ?? 0}</span>
                      <span className="badge badge-danger">Vencidas: {data.service_target_alerts?.first_response.overdue ?? 0}</span>
                    </div>
                  )}
                </div>
              </div>

              {/* Panel 4: Duración de Atención */}
              <div className="card" style={{ padding: '1.25rem', borderRadius: '12px' }}>
                <span style={{ fontSize: '0.8rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase' }}>
                  Duración de Atención
                </span>

                <div style={{ marginTop: '1rem' }}>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Tiempo Medio de Resolución</span>
                  <div style={{ fontSize: '1.8rem', fontWeight: 800, color: '#5d8f82', marginTop: '0.15rem' }}>
                    {data.operating_times.avg_resolution_formatted}
                  </div>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    Duración promedio de conversaciones resueltas hoy
                  </span>
                </div>

                <div style={{ borderTop: '1px solid var(--border)', marginTop: '1rem', paddingTop: '0.75rem' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.8rem' }}>
                    <span style={{ color: 'var(--text-muted)' }}>Cierres registrados hoy:</span>
                    <span style={{ fontWeight: 700, color: 'var(--text-main)' }}>
                      {data.agent_conversations.closed_today}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* Tabla de Estado de Colas / Canales */}
          <div className="card" style={{ padding: '1.25rem', borderRadius: '12px' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '1rem' }}>
              <div>
                <h3 style={{ fontSize: '1rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                  Estado de Colas y Canales en Tiempo Real
                </h3>
                <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                  Monitoreo simultáneo de conversaciones por punto de contacto
                </span>
              </div>
              <span className="badge badge-secondary" style={{ fontSize: '0.8rem', padding: '0.35rem 0.65rem' }}>
                {data.channels_summary.length} Canales Activos
              </span>
            </div>

            {data.channels_summary.length === 0 ? (
              <p style={{ textAlign: 'center', color: 'var(--text-muted)', padding: '2rem 0', margin: 0 }}>
                No hay canales configurados para monitoreo.
              </p>
            ) : (
              <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '0.875rem' }}>
                  <thead>
                    <tr style={{ borderBottom: '2px solid var(--border)', color: 'var(--text-muted)', fontSize: '0.75rem', textTransform: 'uppercase' }}>
                      <th style={{ padding: '0.75rem 0.5rem' }}>Cola / Canal</th>
                      <th style={{ padding: '0.75rem 0.5rem', textAlign: 'center' }}>En Curso</th>
                      <th style={{ padding: '0.75rem 0.5rem', textAlign: 'center' }}>Cerradas Hoy</th>
                      <th style={{ padding: '0.75rem 0.5rem', textAlign: 'center' }}>Esperando Respuesta</th>
                      <th style={{ padding: '0.75rem 0.5rem', textAlign: 'right' }}>Tiempo Medio Respuesta</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.channels_summary.map((ch) => {
                      const isWa = ch.channel_type.toLowerCase() === 'whatsapp';
                      const badgeColor = isWa ? '#25d366' : '#5d8f82';

                      return (
                        <tr
                          key={ch.channel_id}
                          style={{ borderBottom: '1px solid var(--border)' }}
                          data-testid={`channel-row-${ch.channel_type}`}
                        >
                          <td style={{ padding: '0.85rem 0.5rem' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                              <Radio size={16} style={{ color: badgeColor }} />
                              <div>
                                <span style={{ fontWeight: 600, color: 'var(--text-main)', display: 'block' }}>
                                  {ch.channel_name}
                                </span>
                                <span style={{ fontSize: '0.7rem', color: 'var(--text-muted)', textTransform: 'uppercase' }}>
                                  {ch.channel_type}
                                </span>
                              </div>
                            </div>
                          </td>

                          <td style={{ padding: '0.85rem 0.5rem', textAlign: 'center' }}>
                            <span
                              className="badge"
                              style={{
                                background: ch.ongoing_conversations > 0 ? '#eaf1ee' : 'var(--border)',
                                color: ch.ongoing_conversations > 0 ? '#5d8f82' : 'var(--text-muted)',
                                fontWeight: 700,
                                padding: '0.25rem 0.6rem',
                                borderRadius: '12px',
                              }}
                            >
                              {ch.ongoing_conversations}
                            </span>
                          </td>

                          <td style={{ padding: '0.85rem 0.5rem', textAlign: 'center' }}>
                            <span style={{ fontWeight: 600, color: 'var(--text-main)' }}>
                              {ch.closed_conversations}
                            </span>
                          </td>

                          <td style={{ padding: '0.85rem 0.5rem', textAlign: 'center' }}>
                            <span
                              className="badge"
                              style={{
                                background: ch.waiting_response_count > 0 ? '#fef9c3' : '#eaf1ee',
                                color: ch.waiting_response_count > 0 ? '#ca8a04' : '#61726d',
                                fontWeight: 700,
                                padding: '0.25rem 0.6rem',
                                borderRadius: '12px',
                              }}
                            >
                              {ch.waiting_response_count}
                            </span>
                          </td>

                          <td style={{ padding: '0.85rem 0.5rem', textAlign: 'right', fontWeight: 600, color: 'var(--text-main)' }}>
                            {ch.avg_response_time_formatted}
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
