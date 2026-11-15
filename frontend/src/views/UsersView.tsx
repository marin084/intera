import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import type { User, Role } from '../types';
import {
  UserPlus,
  Shield,
  CheckCircle,
  XCircle,
  UserX,
  UserCheck,
  Trash2,
  AlertTriangle,
  Radio,
  Globe,
  Pencil,
} from 'lucide-react';
import { useAuth } from '../context/useAuth';
import { ConfirmModal } from '../components/common/ConfirmModal';
import { DiscardChangesPanel } from '../components/common/DiscardChangesPanel';

const channelIconSources: Record<string, string> = {
  whatsapp: '/channels/whatsapp.svg',
  facebook_messenger: '/channels/messenger.svg',
  instagram: '/channels/instagram.svg',
  telegram: '/channels/telegram.svg',
};

const UserChannelIcon: React.FC<{ type: string }> = ({ type }) => {
  const iconSource = channelIconSources[type];
  if (iconSource) {
    return <img className="flow-channel-brand-icon" src={iconSource} alt="" aria-hidden="true" />;
  }
  if (type === 'webchat') return <Globe size={14} aria-hidden="true" />;
  return <Radio size={14} aria-hidden="true" />;
};

export const UsersView: React.FC = () => {
  const { hasPermission } = useAuth();
  const [users, setUsers] = useState<User[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [editingUser, setEditingUser] = useState<User | null>(null);
  const [channelOptions, setChannelOptions] = useState<{ id: string; name: string; type: string; is_active: boolean }[]>([]);
  const [channelTarget, setChannelTarget] = useState<User | null>(null);
  const [selectedChannelIds, setSelectedChannelIds] = useState<string[]>([]);
  const [channelsLoading, setChannelsLoading] = useState(false);
  const [channelsSaving, setChannelsSaving] = useState(false);
  const [channelsError, setChannelsError] = useState<string | null>(null);
  const [channelAccessDirty, setChannelAccessDirty] = useState(false);
  const [confirmDiscardChannelAccess, setConfirmDiscardChannelAccess] = useState(false);

  // Form states
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [selectedRole, setSelectedRole] = useState('agent');
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [userFormDirty, setUserFormDirty] = useState(false);
  const [confirmDiscardUserForm, setConfirmDiscardUserForm] = useState(false);

  // Confirmation modal states
  const [userToConfirm, setUserToConfirm] = useState<User | null>(null);
  const [confirmActionType, setConfirmActionType] = useState<'deactivate' | 'activate' | 'delete' | null>(null);
  const [isConfirmModalOpen, setIsConfirmModalOpen] = useState(false);
  const [isSubmittingConfirm, setIsSubmittingConfirm] = useState(false);

  // Feedback notifications
  const [actionSuccessMessage, setActionSuccessMessage] = useState<string | null>(null);
  const [actionErrorMessage, setActionErrorMessage] = useState<string | null>(null);
  const [undoStatus, setUndoStatus] = useState<{ user: User; restoreActive: boolean } | null>(null);

  const discardUserForm = () => {
    setConfirmDiscardUserForm(false);
    setUserFormDirty(false);
    setShowModal(false);
    setEditingUser(null);
    setFormError(null);
  };

  const requestCloseUserPanel = () => {
    if (submitting) return;
    if (userFormDirty) setConfirmDiscardUserForm(true);
    else discardUserForm();
  };

  const discardChannelAccess = () => {
    setConfirmDiscardChannelAccess(false);
    setChannelAccessDirty(false);
    setChannelTarget(null);
  };

  const requestCloseChannelAccess = () => {
    if (channelsSaving) return;
    if (channelAccessDirty) setConfirmDiscardChannelAccess(true);
    else discardChannelAccess();
  };

  useEffect(() => {
    if (!undoStatus) return;
    const timeout = window.setTimeout(() => setUndoStatus(null), 6000);
    return () => window.clearTimeout(timeout);
  }, [undoStatus]);

  const fetchUsers = React.useCallback(async () => {
    try {
      const res = await api.getUsers();
      setUsers(res.data);
    } catch (err: any) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let isMounted = true;
    api.getUsers()
      .then((res) => {
        if (isMounted) setUsers(res.data);
      })
      .catch((err) => console.error(err))
      .finally(() => {
        if (isMounted) setLoading(false);
      });

    api.getRoles()
      .then((res) => {
        if (isMounted) setRoles(res.data);
      })
      .catch((err) => console.error(err));

    return () => {
      isMounted = false;
    };
  }, []);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return;
      if (showModal) requestCloseUserPanel();
      if (channelTarget) requestCloseChannelAccess();
      if (!isSubmittingConfirm) {
        setIsConfirmModalOpen(false);
        setUserToConfirm(null);
        setConfirmActionType(null);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isSubmittingConfirm, showModal, userFormDirty, submitting, channelTarget, channelAccessDirty, channelsSaving]);

  const openCreateUserPanel = () => {
    setEditingUser(null);
    setName('');
    setEmail('');
    setPassword('');
    setSelectedRole('agent');
    setFormError(null);
    setUserFormDirty(false);
    setConfirmDiscardUserForm(false);
    setShowModal(true);
  };

  const openEditUserPanel = (user: User) => {
    setEditingUser(user);
    setName(user.name);
    setEmail(user.email);
    setPassword('');
    setFormError(null);
    setUserFormDirty(false);
    setConfirmDiscardUserForm(false);
    setShowModal(true);
  };

  const closeUserPanel = () => {
    requestCloseUserPanel();
  };

  const handleSaveUser = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError(null);
    setSubmitting(true);

    try {
      if (editingUser) {
        await api.updateUser(editingUser.id, { name, email });
        setActionSuccessMessage(`Se actualizaron los datos de «${name}».`);
        setTimeout(() => setActionSuccessMessage(null), 4000);
      } else {
        await api.createUser({
          name,
          email,
          password,
          roles: [selectedRole],
        });
      }
      setShowModal(false);
      setUserFormDirty(false);
      setConfirmDiscardUserForm(false);
      setEditingUser(null);
      setName('');
      setEmail('');
      setPassword('');
      await fetchUsers();
    } catch (err: any) {
      setFormError(err.message || (editingUser ? 'Error al actualizar usuario.' : 'Error al crear usuario.'));
    } finally {
      setSubmitting(false);
    }
  };

  const handleToggleStatus = (user: User) => {
    setUserToConfirm(user);
    setConfirmActionType(user.is_active ? 'deactivate' : 'activate');
    setIsConfirmModalOpen(true);
  };

  const handleManageChannels = async (user: User) => {
    setChannelAccessDirty(false);
    setConfirmDiscardChannelAccess(false);
    setChannelTarget(user);
    setSelectedChannelIds((user.accessible_channels || []).map((channel) => channel.id));
    setChannelsError(null);
    setChannelsLoading(true);
    try {
      const response = await api.getChannelAccessOptions();
      setChannelOptions(response.data);
    } catch (err: any) {
      setChannelsError(err.message || 'No se pudieron cargar los canales.');
    } finally {
      setChannelsLoading(false);
    }
  };

  const handleSaveChannels = async () => {
    if (!channelTarget) return;
    setChannelsSaving(true);
    setChannelsError(null);
    try {
      await api.updateUserChannels(channelTarget.id, selectedChannelIds);
      setChannelAccessDirty(false);
      setConfirmDiscardChannelAccess(false);
      setChannelTarget(null);
      setActionSuccessMessage(`Canales de «${channelTarget.name}» actualizados correctamente.`);
      await fetchUsers();
      setTimeout(() => setActionSuccessMessage(null), 4000);
    } catch (err: any) {
      setChannelsError(err.message || 'No se pudieron guardar los canales.');
    } finally {
      setChannelsSaving(false);
    }
  };

  const handleCloseConfirmModal = () => {
    if (isSubmittingConfirm) return;
    setIsConfirmModalOpen(false);
    setUserToConfirm(null);
    setConfirmActionType(null);
  };

  const executeConfirmAction = async () => {
    if (!userToConfirm || !confirmActionType) return;
    setIsSubmittingConfirm(true);
    try {
      if (confirmActionType === 'deactivate') {
        await api.updateUserStatus(userToConfirm.id, false);
        setActionSuccessMessage(`El usuario «${userToConfirm.name}» fue desactivado correctamente.`);
        setUndoStatus({ user: userToConfirm, restoreActive: true });
      } else if (confirmActionType === 'activate') {
        await api.updateUserStatus(userToConfirm.id, true);
        setActionSuccessMessage(`El usuario «${userToConfirm.name}» fue reactivado correctamente.`);
        setUndoStatus({ user: userToConfirm, restoreActive: false });
      } else if (confirmActionType === 'delete') {
        if (typeof (api as any).deleteUser === 'function') {
          await (api as any).deleteUser(userToConfirm.id);
        }
        setUndoStatus(null);
        setActionSuccessMessage(`El usuario «${userToConfirm.name}» fue eliminado definitivamente.`);
      }

      setIsConfirmModalOpen(false);
      setUserToConfirm(null);
      setConfirmActionType(null);
      fetchUsers();

      setTimeout(() => {
        setActionSuccessMessage(null);
      }, 6000);
    } catch (err: any) {
      setActionErrorMessage(err.message || 'No se pudo procesar la acción del usuario.');
      setIsConfirmModalOpen(false);
      setUserToConfirm(null);
      setConfirmActionType(null);
      setTimeout(() => {
        setActionErrorMessage(null);
      }, 5000);
    } finally {
      setIsSubmittingConfirm(false);
    }
  };

  const handleUndoStatus = async () => {
    if (!undoStatus) return;
    try {
      await api.updateUserStatus(undoStatus.user.id, undoStatus.restoreActive);
      setActionSuccessMessage(`Se deshizo el cambio de estado de «${undoStatus.user.name}».`);
      setUndoStatus(null);
      await fetchUsers();
    } catch (err: any) {
      setActionErrorMessage(err.message || 'No se pudo deshacer el cambio de estado.');
      setTimeout(() => setActionErrorMessage(null), 5000);
    }
  };

  const canManage = hasPermission('users.manage');

  let confirmTitle = '';
  let confirmDescription = '';
  let confirmButtonText = 'Confirmar';
  let confirmVariant: 'primary' | 'danger' = 'primary';
  let confirmIcon: React.ReactNode = <UserCheck size={24} />;
  let modalTestId = 'confirm-user-modal';
  let confirmBtnTestId = 'confirm-user-btn';

  if (confirmActionType === 'deactivate') {
    confirmTitle = `¿Desactivar al usuario «${userToConfirm?.name}»?`;
    confirmDescription =
      'Se cerrarán sus sesiones activas y perderá el acceso a la plataforma hasta que un administrador lo vuelva a habilitar.';
    confirmButtonText = 'Sí, desactivar usuario';
    confirmVariant = 'danger';
    confirmIcon = <UserX size={24} />;
    modalTestId = 'confirm-deactivate-user-modal';
    confirmBtnTestId = 'confirm-deactivate-user-btn';
  } else if (confirmActionType === 'activate') {
    confirmTitle = `¿Reactivar al usuario «${userToConfirm?.name}»?`;
    confirmDescription =
      'El usuario podrá volver a iniciar sesión y atender conversaciones según sus roles y permisos asignados.';
    confirmButtonText = 'Sí, reactivar usuario';
    confirmVariant = 'primary';
    confirmIcon = <UserCheck size={24} />;
    modalTestId = 'confirm-activate-user-modal';
    confirmBtnTestId = 'confirm-activate-user-btn';
  } else if (confirmActionType === 'delete') {
    confirmTitle = `¿Eliminar definitivamente al usuario «${userToConfirm?.name}»?`;
    confirmDescription =
      'Esta acción es irreversible y eliminará su registro. Sus conversaciones históricas mantendrán la trazabilidad de auditoría.';
    confirmButtonText = 'Sí, eliminar usuario';
    confirmVariant = 'danger';
    confirmIcon = <Trash2 size={24} />;
    modalTestId = 'confirm-delete-user-modal';
    confirmBtnTestId = 'confirm-delete-user-btn';
  }

  return (
    <div>
      {actionSuccessMessage && (
        <div
          data-testid="user-action-success-alert"
          className="alert alert-success"
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: '0.5rem',
            marginBottom: '1rem',
            background: '#eaf1ee',
            borderColor: '#a9c8bb',
            color: '#263c38',
          }}
        >
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.5rem' }}>
            <CheckCircle size={18} color="#5d8f82" />
            {actionSuccessMessage}
          </span>
          {undoStatus && (
            <button type="button" className="btn btn-sm btn-secondary" onClick={handleUndoStatus}>
              Deshacer
            </button>
          )}
        </div>
      )}

      {actionErrorMessage && (
        <div
          data-testid="user-action-error-alert"
          className="alert alert-danger"
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: '0.5rem',
            marginBottom: '1rem',
            background: '#fef2f2',
            borderColor: '#fca5a5',
            color: '#991b1b',
          }}
        >
          <AlertTriangle size={18} color="#dc2626" />
          <span>{actionErrorMessage}</span>
        </div>
      )}

      <div className="card">
        <div className="card-header">
          <div>
            <h2 className="card-title">Administración de Usuarios</h2>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.875rem' }}>
              Gestiona operadores y administradores, y define a qué canales puede acceder cada usuario.
            </p>
          </div>
          {canManage && (
            <button className="btn btn-primary" onClick={openCreateUserPanel}>
              <UserPlus size={16} /> Crear Usuario
            </button>
          )}
        </div>

        {loading ? (
          <p style={{ color: 'var(--text-muted)', padding: '1rem' }}>Cargando usuarios...</p>
        ) : users.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '3rem 1rem', color: 'var(--text-muted)' }}>
            <UserPlus size={40} style={{ opacity: 0.3, marginBottom: '0.75rem', color: '#5d8f82' }} />
            <h3 style={{ fontSize: '1.05rem', fontWeight: 600, color: '#35564f', marginBottom: '0.35rem' }}>No hay usuarios registrados</h3>
            <p style={{ fontSize: '0.85rem', maxWidth: '380px', margin: '0 auto 1.25rem' }}>
              Invita operadores, supervisores o administradores para gestionar las conversaciones de Intera.
            </p>
            {canManage && (
              <button className="btn btn-primary" onClick={openCreateUserPanel}>
                <UserPlus size={16} /> Crear mi primer Usuario
              </button>
            )}
          </div>
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th>Correo</th>
                  <th>Roles</th>
                  <th>Canales</th>
                  <th>Estado</th>
                  <th>Último Acceso</th>
                  {canManage && <th>Acciones</th>}
                </tr>
              </thead>
              <tbody>
                {users.map((user) => (
                  <tr key={user.id}>
                    <td style={{ fontWeight: 600 }}>{user.name}</td>
                    <td>{user.email}</td>
                    <td>
                      <div style={{ display: 'flex', gap: '0.25rem', flexWrap: 'wrap' }}>
                        {(user.roles as any[])?.map((r, i) => (
                          <span key={i} className="badge badge-info" style={{ display: 'inline-flex', gap: '0.25rem' }}>
                            <Shield size={12} />
                            {typeof r === 'string' ? r : r.display_name || r.name}
                          </span>
                        ))}
                      </div>
                    </td>
                    <td>
                      {user.channel_access_scope === 'none' ? (
                        <span style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>Sin acceso</span>
                      ) : user.channel_access_scope !== 'all' && !user.accessible_channels?.length ? (
                        <span style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>Sin canales asignados</span>
                      ) : user.accessible_channels?.length ? (
                        <div
                          className="flow-channel-badges"
                          aria-label={`Canales autorizados: ${user.accessible_channels.map((channel) => channel.name).join(', ')}`}
                        >
                          {user.accessible_channels.map((channel) => (
                            <span
                              className="flow-channel-badge"
                              key={channel.id}
                              title={`${channel.name} · ${channel.type}${channel.is_active ? '' : ' · inactivo'}`}
                              role="img"
                              aria-label={channel.name}
                            >
                              <UserChannelIcon type={channel.type} />
                            </span>
                          ))}
                        </div>
                      ) : (
                        <span style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>Sin canales configurados</span>
                      )}
                    </td>
                    <td>
                      {user.is_active ? (
                        <span className="badge badge-success" style={{ display: 'inline-flex', gap: '0.25rem' }}>
                          <CheckCircle size={12} /> Activo
                        </span>
                      ) : (
                        <span className="badge badge-danger" style={{ display: 'inline-flex', gap: '0.25rem' }}>
                          <XCircle size={12} /> Inactivo
                        </span>
                      )}
                    </td>
                    <td style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>
                      {user.last_login_at ? new Date(user.last_login_at).toLocaleString() : 'Nunca'}
                    </td>
                    {canManage && (
                      <td className="user-actions-cell">
                        <div className="user-actions">
                        <button
                          className="btn btn-sm btn-secondary"
                          onClick={() => openEditUserPanel(user)}
                          title={`Editar nombre y correo de ${user.name}`}
                          aria-label={`Editar usuario ${user.name}`}
                          data-testid={`edit-user-btn-${user.id}`}
                        >
                          <Pencil size={14} /> Editar
                        </button>
                        <button
                          className={`btn btn-sm ${user.is_active ? 'btn-danger' : 'btn-secondary'}`}
                          onClick={() => handleToggleStatus(user)}
                          title={user.is_active ? 'Desactivar acceso de este usuario' : 'Activar acceso de este usuario'}
                          aria-label={user.is_active ? `Desactivar usuario ${user.name}` : `Activar usuario ${user.name}`}
                          data-testid={`toggle-user-status-btn-${user.id}`}
                        >
                          {user.is_active ? 'Desactivar' : 'Activar'}
                        </button>
                        <button
                          className="btn btn-sm btn-secondary"
                          onClick={() => handleManageChannels(user)}
                          disabled={user.channel_access_scope === 'all'}
                          title={user.channel_access_scope === 'all' ? 'El superadministrador tiene acceso total' : 'Gestionar acceso a canales'}
                          aria-label={`Gestionar canales de ${user.name}`}
                        >
                          <Radio size={14} /> Canales
                        </button>
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Modal Confirmación Reutilizable */}
      <ConfirmModal
        isOpen={isConfirmModalOpen}
        onClose={handleCloseConfirmModal}
        onConfirm={executeConfirmAction}
        title={confirmTitle}
        description={confirmDescription}
        confirmText={confirmButtonText}
        cancelText="Cancelar"
        confirmVariant={confirmVariant}
        icon={confirmIcon}
        isLoading={isSubmittingConfirm}
        testId={modalTestId}
        confirmTestId={confirmBtnTestId}
        cancelTestId="cancel-user-modal-btn"
        requiredText={confirmActionType === 'delete' ? userToConfirm?.name : undefined}
        requiredTextLabel="usuario"
      />

      {channelTarget && (
        <div className="modal-overlay" onMouseDown={(event) => { if (event.target === event.currentTarget) requestCloseChannelAccess(); }}>
          <div className="modal-content flow-side-panel user-channels-modal" role="dialog" aria-modal="true" aria-labelledby="user-channels-title">
            <div className="modal-header">
              <div>
                <h3 id="user-channels-title" style={{ fontSize: '1.15rem', fontWeight: 600 }}>Acceso a canales</h3>
                <p style={{ color: 'var(--text-muted)', fontSize: '0.85rem', marginTop: '0.25rem' }}>{channelTarget.name} · selecciona los canales disponibles para este usuario.</p>
              </div>
              <button className="btn btn-secondary btn-sm" onClick={requestCloseChannelAccess} disabled={channelsSaving} aria-label="Cerrar">✕</button>
            </div>
            <div className="flow-side-panel-body">
              {channelsError && <div className="alert alert-danger">{channelsError}</div>}
              {channelsLoading ? (
                <p style={{ color: 'var(--text-muted)', padding: '1rem 0' }}>Cargando canales...</p>
              ) : channelOptions.length ? (
                <div className="user-channel-options">
                  {channelOptions.map((channel) => (
                    <label className="user-channel-option" key={channel.id}>
                      <input
                        type="checkbox"
                        checked={selectedChannelIds.includes(channel.id)}
                        onChange={(event) => {
                          setChannelAccessDirty(true);
                          setSelectedChannelIds((current) => event.target.checked
                            ? [...current, channel.id]
                            : current.filter((id) => id !== channel.id));
                        }}
                      />
                      <span className="user-channel-option-icon"><Radio size={16} /></span>
                      <span className="user-channel-option-copy"><strong>{channel.name}</strong><small>{channel.type}{channel.is_active ? '' : ' · inactivo'}</small></span>
                    </label>
                  ))}
                </div>
              ) : (
                <p style={{ color: 'var(--text-muted)', padding: '1rem 0' }}>Aún no hay canales configurados.</p>
              )}
            </div>
            <div className="flow-side-panel-footer">
              <button className="btn btn-secondary" onClick={requestCloseChannelAccess} disabled={channelsSaving}>Cancelar</button>
              <button className="btn btn-primary" onClick={handleSaveChannels} disabled={channelsLoading || channelsSaving}>
                {channelsSaving ? 'Guardando...' : `Guardar acceso (${selectedChannelIds.length})`}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Panel para crear o editar usuario */}
      {showModal && (
        <div className="modal-overlay flow-panel-overlay" role="dialog" aria-modal="true" aria-labelledby="create-user-panel-title">
          <div className="modal-content flow-side-panel">
            <div className="flow-side-panel-header">
              <h3 id="create-user-panel-title" style={{ fontSize: '1.15rem', fontWeight: 600, margin: 0 }}>{editingUser ? 'Editar Usuario' : 'Crear Nuevo Usuario'}</h3>
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                onClick={closeUserPanel}
                disabled={submitting}
                aria-label="Cerrar panel"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSaveUser} onChangeCapture={() => setUserFormDirty(true)} className="flow-side-panel-form">
              <div className="flow-side-panel-body">
                {formError && (
                  <div className="alert alert-danger" style={{ marginBottom: '1rem' }}>{formError}</div>
                )}
              <div className="form-group">
                <label className="form-label">Nombre Completo</label>
                <input
                  type="text"
                  className="form-input"
                  required
                  placeholder="Ej. Carlos Mendoza"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                />
              </div>

              <div className="form-group">
                <label className="form-label">Correo Electrónico</label>
                <input
                  type="email"
                  className="form-input"
                  required
                  placeholder="carlos@empresa.com"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                />
              </div>

              {!editingUser && <>
                <div className="form-group">
                  <label className="form-label">Contraseña (Mínimo 12 caracteres)</label>
                  <input
                    type="password"
                    className="form-input"
                    required
                    minLength={12}
                    placeholder="••••••••••••"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                  />
                </div>

                <div className="form-group">
                  <label className="form-label">Rol del Sistema</label>
                  <select
                    className="form-select"
                    value={selectedRole}
                    onChange={(e) => setSelectedRole(e.target.value)}
                  >
                    {roles.map((r) => (
                      <option key={r.id} value={r.name}>
                        {r.display_name} ({r.name})
                      </option>
                    ))}
                  </select>
                </div>
              </>}

              </div>
              <div className="modal-footer flow-side-panel-footer">
                <button
                  type="button"
                  className="btn btn-secondary"
                  onClick={closeUserPanel}
                  disabled={submitting}
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="btn btn-primary"
                  disabled={submitting}
                >
                  {submitting ? 'Guardando...' : editingUser ? 'Guardar cambios' : 'Crear Usuario'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
      <DiscardChangesPanel
        isOpen={confirmDiscardUserForm}
        onContinueEditing={() => setConfirmDiscardUserForm(false)}
        onDiscard={discardUserForm}
      />
      <DiscardChangesPanel
        isOpen={confirmDiscardChannelAccess}
        onContinueEditing={() => setConfirmDiscardChannelAccess(false)}
        onDiscard={discardChannelAccess}
      />
    </div>
  );
};
