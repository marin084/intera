import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Building2, Check, Loader2, Plus, ShieldCheck, UserPlus, Users, X } from 'lucide-react';
import { api } from '../services/api';
import type { WorkspaceMember, WorkspaceMembership } from '../types';
import { useToast } from '../context/ToastContext';
import { useAuth } from '../context/useAuth';
import { ConfirmModal } from '../components/common/ConfirmModal';

const ASSIGNABLE_ROLES = [
  { value: 'admin', label: 'Administrador de Workspace' },
  { value: 'super_admin', label: 'Administrador principal' },
  { value: 'supervisor', label: 'Supervisor de Atención' },
  { value: 'flow_manager', label: 'Gestor de Flujos' },
  { value: 'agent', label: 'Agente de Atención' },
];

const roleName = (role: WorkspaceMembership['role'] | WorkspaceMember['role']) => (
  typeof role === 'string' ? role : role?.display_name || role?.name || 'Sin rol'
);

export const WorkspacesView: React.FC = () => {
  const { user, refreshWorkspaces } = useAuth();
  const { showToast } = useToast();
  const [workspaces, setWorkspaces] = useState<WorkspaceMembership[]>([]);
  const [loading, setLoading] = useState(true);
  const [createOpen, setCreateOpen] = useState(false);
  const [membersOpen, setMembersOpen] = useState(false);
  const [selectedWorkspace, setSelectedWorkspace] = useState<WorkspaceMembership | null>(null);
  const [members, setMembers] = useState<WorkspaceMember[]>([]);
  const [membersLoading, setMembersLoading] = useState(false);
  const [workspaceName, setWorkspaceName] = useState('');
  const [workspaceSlug, setWorkspaceSlug] = useState('');
  const [memberEmail, setMemberEmail] = useState('');
  const [memberRole, setMemberRole] = useState('agent');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [memberToDeactivate, setMemberToDeactivate] = useState<WorkspaceMember | null>(null);

  const canManage = Boolean(user?.is_platform_admin);

  const loadWorkspaces = useCallback(async () => {
    setLoading(true);
    try {
      const response = refreshWorkspaces ? await refreshWorkspaces() : await api.getWorkspaces();
      setWorkspaces(response.data);
    } catch (cause: any) {
      showToast(cause.message || 'No se pudieron cargar los workspaces.', 'error');
    } finally {
      setLoading(false);
    }
  }, [refreshWorkspaces, showToast]);

  const loadMembers = useCallback(async (workspaceId: string) => {
    setMembersLoading(true);
    try {
      const response = await api.getWorkspaceMembers(workspaceId);
      setMembers(response.data);
    } catch (cause: any) {
      showToast(cause.message || 'No se pudieron cargar los integrantes.', 'error');
    } finally {
      setMembersLoading(false);
    }
  }, [showToast]);

  useEffect(() => {
    if (canManage) void loadWorkspaces();
  }, [canManage, loadWorkspaces]);

  const openCreate = () => {
    setWorkspaceName('');
    setWorkspaceSlug('');
    setError('');
    setCreateOpen(true);
  };

  const openMembers = (workspace: WorkspaceMembership) => {
    setSelectedWorkspace(workspace);
    setMemberEmail('');
    setMemberRole('agent');
    setError('');
    setMembersOpen(true);
    void loadMembers(workspace.id);
  };

  const closePanels = () => {
    if (saving) return;
    setCreateOpen(false);
    setMembersOpen(false);
    setSelectedWorkspace(null);
    setError('');
  };

  const createWorkspace = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      await api.createWorkspace({ name: workspaceName.trim(), ...(workspaceSlug.trim() ? { slug: workspaceSlug.trim().toLowerCase() } : {}) });
      setCreateOpen(false);
      showToast('Workspace creado.', 'success');
      await loadWorkspaces();
    } catch (cause: any) {
      setError(cause.message || 'No se pudo crear el workspace.');
    } finally {
      setSaving(false);
    }
  };

  const addMember = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!selectedWorkspace) return;
    setSaving(true);
    setError('');
    try {
      await api.addWorkspaceMember(selectedWorkspace.id, { email: memberEmail.trim(), role: memberRole });
      setMemberEmail('');
      showToast('Integrante agregado al workspace.', 'success');
      await loadMembers(selectedWorkspace.id);
      await loadWorkspaces();
    } catch (cause: any) {
      setError(cause.message || 'No se pudo agregar el integrante.');
    } finally {
      setSaving(false);
    }
  };

  const updateMemberRole = async (member: WorkspaceMember, role: string) => {
    if (!selectedWorkspace || member.is_owner) return;
    setSaving(true);
    try {
      await api.updateWorkspaceMember(selectedWorkspace.id, member.id, { role });
      showToast('Rol actualizado.', 'success');
      await loadMembers(selectedWorkspace.id);
    } catch (cause: any) {
      showToast(cause.message || 'No se pudo actualizar el rol.', 'error');
    } finally {
      setSaving(false);
    }
  };

  const deactivateMember = async () => {
    if (!selectedWorkspace || !memberToDeactivate) return;
    setSaving(true);
    try {
      await api.removeWorkspaceMember(selectedWorkspace.id, memberToDeactivate.id);
      setMemberToDeactivate(null);
      showToast('Acceso desactivado para este workspace.', 'success');
      await loadMembers(selectedWorkspace.id);
      await loadWorkspaces();
    } catch (cause: any) {
      showToast(cause.message || 'No se pudo desactivar el acceso.', 'error');
    } finally {
      setSaving(false);
    }
  };

  const activeCount = useMemo(() => workspaces.reduce((total, workspace) => total + (workspace.member_count || 0), 0), [workspaces]);

  if (!canManage) return null;

  return (
    <>
      <div className="card">
        <div className="card-header">
          <div>
            <h2 className="card-title">Workspaces</h2>
            <p style={{ margin: '0.3rem 0 0', color: 'var(--text-muted)', fontSize: '0.875rem' }}>
              Separa clientes, equipos y datos con un workspace independiente para cada operación.
            </p>
          </div>
          <button className="btn btn-primary" type="button" onClick={openCreate}>
            <Plus size={16} /> Crear workspace
          </button>
        </div>

        <div style={{ display: 'flex', gap: '0.65rem', flexWrap: 'wrap', marginBottom: '1rem' }}>
          <span className="badge badge-secondary"><Building2 size={13} /> {workspaces.length} workspaces activos</span>
          <span className="badge badge-secondary"><Users size={13} /> {activeCount} accesos activos</span>
        </div>

        {loading ? <p style={{ color: 'var(--text-muted)' }}>Cargando workspaces…</p> : workspaces.length === 0 ? (
          <div style={{ padding: '2.5rem 1rem', textAlign: 'center', color: 'var(--text-muted)' }}>
            <Building2 size={34} style={{ opacity: 0.45, marginBottom: '0.5rem' }} />
            <p style={{ margin: 0 }}>Todavía no hay workspaces configurados.</p>
          </div>
        ) : (
          <div className="table-wrapper">
            <table>
              <thead><tr><th>Workspace</th><th>Rol actual</th><th>Integrantes</th><th>Estado</th><th>Acciones</th></tr></thead>
              <tbody>
                {workspaces.map((workspace) => (
                  <tr key={workspace.id}>
                    <td data-mobile-label="Workspace">
                      <strong style={{ display: 'block', color: 'var(--text-primary)' }}>{workspace.name}</strong>
                      <small style={{ color: 'var(--text-muted)' }}>{workspace.slug}</small>
                    </td>
                    <td data-mobile-label="Rol actual">{workspace.role ? <span className="badge badge-secondary">{roleName(workspace.role)}</span> : <span style={{ color: 'var(--text-muted)' }}>Administración global</span>}</td>
                    <td data-mobile-label="Integrantes"><span className="badge badge-info">{workspace.member_count ?? '—'}</span></td>
                    <td data-mobile-label="Estado"><span className="badge badge-success"><Check size={13} /> Activo</span></td>
                    <td data-mobile-label="Acciones"><button className="btn btn-secondary btn-sm" type="button" onClick={() => openMembers(workspace)}><Users size={14} /> Administrar</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {createOpen && (
        <div className="modal-overlay flow-panel-overlay" role="dialog" aria-modal="true" aria-labelledby="workspace-create-title" onMouseDown={(event) => { if (event.target === event.currentTarget && !saving) closePanels(); }}>
          <div className="modal-content flow-side-panel" style={{ width: 'min(520px, 100vw)', maxWidth: 'min(520px, 100vw)' }}>
            <div className="flow-side-panel-header">
              <h3 id="workspace-create-title" style={{ margin: 0, fontSize: '1.1rem', fontWeight: 600 }}>Crear workspace</h3>
              <button className="btn btn-secondary btn-sm" type="button" aria-label="Cerrar panel" onClick={closePanels} disabled={saving}><X size={16} /></button>
            </div>
            <form onSubmit={createWorkspace} className="flow-side-panel-form">
              <div className="flow-side-panel-body">
                {error && <div role="alert" style={{ padding: '0.75rem', marginBottom: '1rem', borderRadius: '8px', border: '1px solid #fecaca', color: '#b91c1c', background: '#fef2f2' }}>{error}</div>}
                <p style={{ margin: '0 0 1.2rem', color: 'var(--text-muted)', fontSize: '0.875rem', lineHeight: 1.5 }}>El workspace se creará con tu usuario como propietario inicial y administrador principal.</p>
                <div className="form-group"><label className="form-label" htmlFor="workspace-name">Nombre del workspace</label><input id="workspace-name" className="form-input" required maxLength={120} value={workspaceName} onChange={(event) => setWorkspaceName(event.target.value)} placeholder="Ej. Empresa de ventas" /></div>
                <div className="form-group"><label className="form-label" htmlFor="workspace-slug">Identificador (opcional)</label><input id="workspace-slug" className="form-input" maxLength={80} pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value={workspaceSlug} onChange={(event) => setWorkspaceSlug(event.target.value)} placeholder="Se genera automáticamente" /><small style={{ display: 'block', marginTop: '0.35rem', color: 'var(--text-muted)' }}>Usa minúsculas, números y guiones.</small></div>
              </div>
              <div className="flow-side-panel-footer"><button className="btn btn-secondary" type="button" onClick={closePanels} disabled={saving}>Cancelar</button><button className="btn btn-primary" type="submit" disabled={saving}>{saving && <Loader2 size={15} className="animate-spin" />} Crear workspace</button></div>
            </form>
          </div>
        </div>
      )}

      {membersOpen && selectedWorkspace && (
        <div className="modal-overlay flow-panel-overlay" role="dialog" aria-modal="true" aria-labelledby="workspace-members-title" onMouseDown={(event) => { if (event.target === event.currentTarget && !saving) closePanels(); }}>
          <div className="modal-content flow-side-panel" style={{ width: 'min(680px, 100vw)', maxWidth: 'min(680px, 100vw)' }}>
            <div className="flow-side-panel-header"><div><h3 id="workspace-members-title" style={{ margin: 0, fontSize: '1.1rem', fontWeight: 600 }}>{selectedWorkspace.name}</h3><small style={{ color: 'var(--text-muted)' }}>Integrantes y permisos por workspace</small></div><button className="btn btn-secondary btn-sm" type="button" aria-label="Cerrar panel" onClick={closePanels} disabled={saving}><X size={16} /></button></div>
            <div className="flow-side-panel-body">
              {error && <div role="alert" style={{ padding: '0.75rem', marginBottom: '1rem', borderRadius: '8px', border: '1px solid #fecaca', color: '#b91c1c', background: '#fef2f2' }}>{error}</div>}
              <div style={{ padding: '0.8rem', borderRadius: '10px', background: '#f4f8f6', border: '1px solid var(--border)', marginBottom: '1.1rem' }}><div style={{ display: 'flex', gap: '0.45rem', alignItems: 'center', color: 'var(--text-primary)', fontWeight: 600 }}><ShieldCheck size={16} /> Administración de acceso</div><p style={{ margin: '0.35rem 0 0', color: 'var(--text-muted)', fontSize: '0.8rem' }}>Agrega usuarios que ya existen en Intera. Las invitaciones por correo se incorporarán en una fase posterior.</p></div>
              <form onSubmit={addMember} style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(170px, 0.7fr) auto', gap: '0.55rem', alignItems: 'end', marginBottom: '1.2rem' }}>
                <div className="form-group" style={{ marginBottom: 0 }}><label className="form-label" htmlFor="workspace-member-email">Correo del usuario</label><input id="workspace-member-email" className="form-input" required type="email" value={memberEmail} onChange={(event) => setMemberEmail(event.target.value)} placeholder="usuario@dominio.com" /></div>
                <div className="form-group" style={{ marginBottom: 0 }}><label className="form-label" htmlFor="workspace-member-role">Rol</label><select id="workspace-member-role" className="form-input" value={memberRole} onChange={(event) => setMemberRole(event.target.value)}>{ASSIGNABLE_ROLES.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}</select></div>
                <button className="btn btn-primary" type="submit" disabled={saving}><UserPlus size={15} /> Agregar</button>
              </form>
              {membersLoading ? <p style={{ color: 'var(--text-muted)' }}>Cargando integrantes…</p> : members.length === 0 ? <p style={{ color: 'var(--text-muted)' }}>No hay integrantes registrados.</p> : <div style={{ display: 'grid', gap: '0.55rem' }}>{members.map((member) => <div key={member.id} style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(170px, 0.65fr) auto', alignItems: 'center', gap: '0.65rem', padding: '0.7rem 0.75rem', border: '1px solid var(--border)', borderRadius: '10px', opacity: member.is_active ? 1 : 0.62 }}><div><strong style={{ display: 'block', color: 'var(--text-primary)' }}>{member.name}{member.is_owner && <span className="badge badge-success" style={{ marginLeft: '0.45rem' }}>Propietario</span>}</strong><small style={{ color: 'var(--text-muted)' }}>{member.email}</small></div><select className="form-input" value={member.role?.name || 'agent'} disabled={member.is_owner || saving || !member.is_active} aria-label={`Rol de ${member.name}`} onChange={(event) => void updateMemberRole(member, event.target.value)}>{ASSIGNABLE_ROLES.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}</select>{member.is_active ? <button className="btn btn-secondary btn-sm" type="button" disabled={member.is_owner || saving} onClick={() => setMemberToDeactivate(member)}>Desactivar</button> : <button className="btn btn-secondary btn-sm" type="button" disabled={saving} onClick={() => void api.updateWorkspaceMember(selectedWorkspace.id, member.id, { is_active: true }).then(() => loadMembers(selectedWorkspace.id)).catch((cause: any) => showToast(cause.message || 'No se pudo reactivar el acceso.', 'error'))}>Reactivar</button>}</div>)}</div>}
            </div>
            <div className="flow-side-panel-footer"><button className="btn btn-secondary" type="button" onClick={closePanels} disabled={saving}>Cerrar</button></div>
          </div>
        </div>
      )}

      <ConfirmModal isOpen={!!memberToDeactivate} onClose={() => setMemberToDeactivate(null)} onConfirm={() => void deactivateMember()} title="Desactivar acceso" description={`El usuario dejará de poder entrar a ${selectedWorkspace?.name || 'este workspace'} y no recibirá nuevas conversaciones allí. Esta acción se puede revertir reactivando su acceso.`} confirmText="Sí, desactivar acceso" confirmVariant="danger" icon={<Users size={18} />} isLoading={saving} />
    </>
  );
};

export default WorkspacesView;
