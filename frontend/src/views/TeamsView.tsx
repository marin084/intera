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

const formatDuration = (seconds: number) => {
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
  const [memberIds, setMemberIds] = useState<number[]>([]);
  const [channelIds, setChannelIds] = useState<string[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const eligibleUsers = useMemo(() => users.filter(userCanJoinTeams).sort((a, b) => a.name.localeCompare(b.name)), [users]);

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
    setMemberIds([]);
    setChannelIds([]);
    setError('');
    setPanelOpen(true);
  };

  const openEdit = (team: Team) => {
    setEditing(team);
    setName(team.name);
    setDescription(team.description || '');
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
      const payload = { name: name.trim(), description: description.trim(), member_ids: memberIds, channel_ids: channelIds };
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
    <div className="content-body">
      <section className="card" style={{ padding: '1.5rem', margin: '1rem', border: '1px solid var(--border)', borderRadius: 'var(--radius)', background: 'var(--bg-card, #fff)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '1rem', marginBottom: '1rem' }}>
          <div>
            <h2 style={{ margin: 0, fontSize: '1.05rem', color: 'var(--text-primary)' }}>Equipos de atención</h2>
            <p style={{ margin: '0.3rem 0 0', color: 'var(--text-muted)', fontSize: '0.875rem' }}>
              Define quién atiende cada canal y reparte las conversaciones en colas separadas.
            </p>
          </div>
          <button className="btn btn-primary" type="button" onClick={openCreate} style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', flexShrink: 0 }}>
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
                    <td>
                      <strong style={{ display: 'block', color: 'var(--text-primary)' }}>{team.name}</strong>
                      {team.is_default && <span className="badge badge-success" style={{ marginTop: '0.25rem' }}>Predeterminado</span>}
                      {team.description && <small style={{ display: 'block', marginTop: '0.25rem', color: 'var(--text-muted)' }}>{team.description}</small>}
                    </td>
                    <td>{team.members.length ? team.members.map((member) => member.name).join(', ') : 'Sin integrantes'}</td>
                    <td>
                      {team.channels.length ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}><Radio size={14} />{team.channels.map((channel) => channel.name).join(', ')}</span> : <span style={{ color: 'var(--text-muted)' }}>Canales sin ruta</span>}
                    </td>
                    <td><span className="badge badge-info">{team.waiting_count}</span></td>
                    <td><span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}><Clock3 size={14} />{formatDuration(team.average_wait_seconds)}</span></td>
                    <td><button className="btn btn-secondary btn-sm" type="button" onClick={() => openEdit(team)}>Editar</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

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
    </div>
  );
};
