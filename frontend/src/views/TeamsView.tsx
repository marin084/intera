import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Check, Clock3, Plus, Radio, UsersRound, X } from 'lucide-react';
import { api } from '../services/api';
import type { Channel, Team, User } from '../types';
import { useToast } from '../context/ToastContext';

const STAFF_ROLES = new Set(['agent', 'admin', 'supervisor', 'super_admin']);

const userCanJoinTeams = (user: User) => {
  const roles = (user.roles || []).map((role) => typeof role === 'string' ? role : role.name);
  return user.is_active && roles.some((role) => STAFF_ROLES.has(role));
};

const formatDuration = (seconds?: number | null) => {
  if (!seconds) return '—';
  if (seconds < 60) return `${seconds} s`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes} min`;
  return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
};

export const TeamsView: React.FC = () => {
  const { showToast } = useToast();
  const [teams, setTeams] = useState<Team[]>([]);
  const [users, setUsers] = useState<User[]>([]);
  const [channels, setChannels] = useState<Channel[]>([]);
  const [loading, setLoading] = useState(true);
  const [panelOpen, setPanelOpen] = useState(false);
  const [editing, setEditing] = useState<Team | null>(null);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [queueWaitTargetMinutes, setQueueWaitTargetMinutes] = useState('');
  const [firstResponseTargetMinutes, setFirstResponseTargetMinutes] = useState('');
  const [memberIds, setMemberIds] = useState<number[]>([]);
  const [channelIds, setChannelIds] = useState<string[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const eligibleUsers = useMemo(() => users.filter(userCanJoinTeams).sort((a, b) => a.name.localeCompare(b.name)), [users]);
  const defaultQueueWaitTargetSeconds = teams.find((team) => team.is_default)?.queue_wait_target_default_seconds ?? 120;
  const defaultFirstResponseTargetSeconds = teams.find((team) => team.is_default)?.first_response_target_default_seconds ?? 300;

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [teamResponse, userResponse, channelResponse] = await Promise.all([
        api.getTeams(), api.getUsers(), api.getChannels(),
      ]);
      setTeams(teamResponse.data);
      setUsers(userResponse.data);
      setChannels(channelResponse.data.filter((channel: Channel) => channel.is_active));
    } catch (cause: any) {
      showToast(cause.message || 'No se pudieron cargar los equipos.', 'error');
    } finally {
      setLoading(false);
    }
  }, [showToast]);

  useEffect(() => { void load(); }, [load]);

  useEffect(() => {
    if (!panelOpen) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !saving) setPanelOpen(false);
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [panelOpen, saving]);

  const openCreate = () => {
    setEditing(null);
    setName('');
    setDescription('');
    setQueueWaitTargetMinutes('');
    setFirstResponseTargetMinutes('');
    setMemberIds([]);
    setChannelIds([]);
    setError('');
    setPanelOpen(true);
  };

  const openEdit = (team: Team) => {
    setEditing(team);
    setName(team.name);
    setDescription(team.description || '');
    setQueueWaitTargetMinutes(team.queue_wait_target_seconds == null ? '' : String(team.queue_wait_target_seconds / 60));
    setFirstResponseTargetMinutes(team.first_response_target_seconds == null ? '' : String(team.first_response_target_seconds / 60));
    setMemberIds(team.members.map((member) => member.id));
    setChannelIds(team.channels.map((channel) => channel.id));
    setError('');
    setPanelOpen(true);
  };

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setError('');
    setSaving(true);
    try {
      const payload = {
        name: name.trim(),
        description: description.trim(),
        member_ids: memberIds,
        channel_ids: channelIds,
        queue_wait_target_seconds: queueWaitTargetMinutes.trim() ? Number(queueWaitTargetMinutes) * 60 : null,
        first_response_target_seconds: firstResponseTargetMinutes.trim() ? Number(firstResponseTargetMinutes) * 60 : null,
      };
      if (editing) await api.updateTeam(editing.id, payload);
      else await api.createTeam(payload);
      setPanelOpen(false);
      showToast(editing ? 'Equipo actualizado.' : 'Equipo creado.', 'success');
      await load();
    } catch (cause: any) {
      setError(cause.message || 'No se pudo guardar el equipo.');
    } finally {
      setSaving(false);
    }
  };

  const toggle = <T extends string | number>(values: T[], value: T, update: (next: T[]) => void) => {
    update(values.includes(value) ? values.filter((item) => item !== value) : [...values, value]);
  };

  return (
    <>
      <div className="card">
        <div className="card-header">
          <div>
            <h2 className="card-title">Equipos de atención</h2>
            <p style={{ margin: '0.3rem 0 0', color: 'var(--text-muted)', fontSize: '0.875rem' }}>
              Define quién atiende cada canal y reparte las conversaciones en colas separadas.
            </p>
          </div>
          <button className="btn btn-primary" type="button" onClick={openCreate} style={{ flexShrink: 0 }}>
            <Plus size={16} /> Crear equipo
          </button>
        </div>

        {loading ? <p style={{ color: 'var(--text-muted)' }}>Cargando equipos…</p> : teams.length === 0 ? (
          <div style={{ padding: '2.5rem 1rem', textAlign: 'center', color: 'var(--text-muted)' }}>
            <UsersRound size={34} style={{ opacity: 0.45, marginBottom: '0.5rem' }} />
            <p style={{ margin: 0 }}>Todavía no hay equipos configurados.</p>
          </div>
        ) : (
          <div className="table-wrapper">
            <table>
              <thead><tr><th>Equipo</th><th>Integrantes</th><th>Canales</th><th>En cola</th><th>Espera promedio</th><th>Acciones</th></tr></thead>
              <tbody>
                {teams.map((team) => (
                  <tr key={team.id}>
                    <td data-mobile-label="Equipo">
                      <strong style={{ display: 'block', color: 'var(--text-primary)' }}>{team.name}</strong>
                      {team.is_default && <span className="badge badge-success" style={{ marginTop: '0.25rem' }}>Predeterminado</span>}
                      {team.description && <small style={{ display: 'block', marginTop: '0.25rem', color: 'var(--text-muted)' }}>{team.description}</small>}
                      <small style={{ display: 'block', marginTop: '0.35rem', color: 'var(--text-muted)' }}>
                        Cola ≤ {formatDuration(team.queue_wait_target_effective_seconds ?? team.queue_wait_target_default_seconds ?? 120)} · 1.ª respuesta ≤ {formatDuration(team.first_response_target_effective_seconds ?? team.first_response_target_default_seconds ?? 300)}
                      </small>
                    </td>
                    <td data-mobile-label="Integrantes">
                      {team.members.length ? (
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.35rem' }}>
                          {team.members.map((member) => <span key={member.id} className="badge badge-secondary">{member.name}</span>)}
                        </div>
                      ) : <span style={{ color: 'var(--text-muted)' }}>Sin integrantes</span>}
                    </td>
                    <td data-mobile-label="Canales">
                      {team.channels.length ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}><Radio size={14} />{team.channels.map((channel) => channel.name).join(', ')}</span> : <span style={{ color: 'var(--text-muted)' }}>Canales sin ruta</span>}
                    </td>
                    <td data-mobile-label="En cola"><span className="badge badge-info">{team.waiting_count}</span></td>
                    <td data-mobile-label="Espera promedio"><span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}><Clock3 size={14} />{formatDuration(team.average_wait_seconds)}</span></td>
                    <td data-mobile-label="Acciones"><button className="btn btn-secondary btn-sm" type="button" onClick={() => openEdit(team)}>Editar</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {panelOpen && (
        <div className="modal-overlay flow-panel-overlay" role="dialog" aria-modal="true" aria-labelledby="team-panel-title" onMouseDown={(event) => { if (event.target === event.currentTarget && !saving) setPanelOpen(false); }}>
          <div className="modal-content flow-side-panel" style={{ width: 'min(620px, 100vw)', maxWidth: 'min(620px, 100vw)' }}>
            <div className="flow-side-panel-header">
              <h3 id="team-panel-title" style={{ margin: 0, fontSize: '1.1rem', fontWeight: 600 }}>{editing ? 'Editar equipo' : 'Crear equipo'}</h3>
              <button className="btn btn-secondary btn-sm" type="button" aria-label="Cerrar panel" onClick={() => setPanelOpen(false)} disabled={saving}><X size={16} /></button>
            </div>
            <form onSubmit={save} className="flow-side-panel-form">
              <div className="flow-side-panel-body">
                {error && <div role="alert" style={{ padding: '0.75rem', marginBottom: '1rem', borderRadius: '8px', border: '1px solid #fecaca', color: '#b91c1c', background: '#fef2f2' }}>{error}</div>}
                <div className="form-group">
                  <label className="form-label" htmlFor="team-name">Nombre del equipo</label>
                  <input id="team-name" className="form-input" required maxLength={120} value={name} onChange={(event) => setName(event.target.value)} placeholder="Ej. Atención de ventas" />
                </div>
                <div className="form-group">
                  <label className="form-label" htmlFor="team-description">Descripción</label>
                  <textarea id="team-description" className="form-textarea" rows={2} maxLength={1000} value={description} onChange={(event) => setDescription(event.target.value)} placeholder="Qué conversaciones atiende este equipo" />
                </div>

                <fieldset style={{ padding: 0, margin: '1.25rem 0', border: 0 }}>
                  <legend style={{ marginBottom: '0.35rem', fontWeight: 600, color: 'var(--text-primary)' }}>Tiempos de atención</legend>
                  <p style={{ margin: '0 0 0.75rem', color: 'var(--text-muted)', fontSize: '0.8rem' }}>
                    Deja un campo vacío para usar el valor predeterminado. La espera de cola empieza al solicitar una persona; la primera respuesta, al asignarse a un agente.
                  </p>
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '0.75rem' }}>
                    <div className="form-group" style={{ marginBottom: 0 }}>
                      <label className="form-label" htmlFor="team-queue-target">Objetivo de espera en cola (minutos)</label>
                      <input id="team-queue-target" className="form-input" type="number" min="1" max="1440" step="1" value={queueWaitTargetMinutes} onChange={(event) => setQueueWaitTargetMinutes(event.target.value)} placeholder={`Predeterminado: ${defaultQueueWaitTargetSeconds / 60} min`} />
                    </div>
                    <div className="form-group" style={{ marginBottom: 0 }}>
                      <label className="form-label" htmlFor="team-first-response-target">Objetivo de primera respuesta (minutos)</label>
                      <input id="team-first-response-target" className="form-input" type="number" min="1" max="1440" step="1" value={firstResponseTargetMinutes} onChange={(event) => setFirstResponseTargetMinutes(event.target.value)} placeholder={`Predeterminado: ${defaultFirstResponseTargetSeconds / 60} min`} />
                    </div>
                  </div>
                </fieldset>

                <fieldset style={{ padding: 0, margin: '1.25rem 0', border: 0 }}>
                  <legend style={{ marginBottom: '0.5rem', fontWeight: 600, color: 'var(--text-primary)' }}>Integrantes</legend>
                  <p style={{ margin: '0 0 0.65rem', color: 'var(--text-muted)', fontSize: '0.8rem' }}>Para recibir una conversación, la persona debe pertenecer al equipo y tener acceso al canal en su perfil.</p>
                  <div style={{ display: 'grid', gap: '0.5rem' }}>
                    {eligibleUsers.map((user) => (
                      <label key={user.id} style={{ display: 'flex', alignItems: 'center', gap: '0.55rem', padding: '0.55rem 0.65rem', border: '1px solid var(--border)', borderRadius: '8px', cursor: 'pointer' }}>
                        <input type="checkbox" checked={memberIds.includes(user.id)} onChange={() => toggle(memberIds, user.id, setMemberIds)} />
                        <span style={{ minWidth: 0 }}><strong>{user.name}</strong><small style={{ display: 'block', color: 'var(--text-muted)' }}>{user.email}</small></span>
                        {memberIds.includes(user.id) && <Check size={16} style={{ marginLeft: 'auto', color: 'var(--primary)' }} />}
                      </label>
                    ))}
                    {eligibleUsers.length === 0 && <span style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>No hay agentes activos disponibles.</span>}
                  </div>
                </fieldset>

                <fieldset style={{ padding: 0, margin: '1.25rem 0 0', border: 0 }}>
                  <legend style={{ marginBottom: '0.5rem', fontWeight: 600, color: 'var(--text-primary)' }}>Canales asignados al equipo</legend>
                  <p style={{ margin: '0 0 0.65rem', color: 'var(--text-muted)', fontSize: '0.8rem' }}>Cada canal puede dirigir mensajes a un solo equipo. Los canales sin ruta llegan al Equipo General.</p>
                  <div style={{ display: 'grid', gap: '0.5rem' }}>
                    {channels.map((channel) => (
                      <label key={channel.id} style={{ display: 'flex', alignItems: 'center', gap: '0.55rem', padding: '0.55rem 0.65rem', border: '1px solid var(--border)', borderRadius: '8px', cursor: 'pointer' }}>
                        <input type="checkbox" checked={channelIds.includes(channel.id)} onChange={() => toggle(channelIds, channel.id, setChannelIds)} />
                        <span><strong>{channel.name}</strong><small style={{ display: 'block', color: 'var(--text-muted)' }}>{channel.type}</small></span>
                        {channelIds.includes(channel.id) && <Check size={16} style={{ marginLeft: 'auto', color: 'var(--primary)' }} />}
                      </label>
                    ))}
                    {channels.length === 0 && <span style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>No hay canales activos disponibles.</span>}
                  </div>
                </fieldset>
              </div>
              <div className="modal-footer flow-side-panel-footer">
                <button className="btn btn-secondary" type="button" onClick={() => setPanelOpen(false)} disabled={saving}>Cancelar</button>
                <button className="btn btn-primary" type="submit" disabled={saving}>{saving ? 'Guardando…' : editing ? 'Guardar cambios' : 'Crear equipo'}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </>
  );
};
