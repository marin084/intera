import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import type { Role, Permission } from '../types';
import { Shield, Key, HelpCircle, Minus, Plus, Check, Loader2 } from 'lucide-react';
import { useAuth } from '../context/useAuth';
import { useToast } from '../context/ToastContext';

const MODULE_LABELS: Record<string, string> = {
  users: 'Usuarios',
  roles: 'Roles y permisos',
  channels: 'Canales',
  conversations: 'Conversaciones',
  inbox: 'Bandeja y respuestas',
  agents: 'Equipo',
  teams: 'Equipos',
  audit: 'Auditoría',
  system: 'Sistema',
  flows: 'Flujos conversacionales',
};

const MODULE_ORDER = ['users', 'roles', 'teams', 'channels', 'conversations', 'inbox', 'agents', 'audit', 'system', 'flows'];

type PermissionEntry = Pick<Permission, 'id' | 'name' | 'display_name' | 'module'>;

function groupPermissions(items: PermissionEntry[]) {
  const grouped = new Map<string, PermissionEntry[]>();
  items.forEach((permission) => {
    const key = permission.module || 'other';
    grouped.set(key, [...(grouped.get(key) || []), permission]);
  });

  return [...grouped.entries()]
    .sort(([a], [b]) => {
      const ai = MODULE_ORDER.indexOf(a);
      const bi = MODULE_ORDER.indexOf(b);
      return (ai < 0 ? MODULE_ORDER.length : ai) - (bi < 0 ? MODULE_ORDER.length : bi) || a.localeCompare(b);
    })
    .map(([key, permissions]) => ({
      key,
      label: MODULE_LABELS[key] || key.replace(/[_-]/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()),
      permissions: [...permissions].sort((a, b) => a.name.localeCompare(b.name)),
    }));
}

export const RolesView: React.FC = () => {
  const { hasPermission } = useAuth();
  const { showToast } = useToast();
  const canManageRoles = hasPermission('roles.manage');

  const [roles, setRoles] = useState<Role[]>([]);
  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [loading, setLoading] = useState(true);

  // Auto-assignment capacity configuration state
  const [capacities, setCapacities] = useState<Record<number, number>>({});
  const [savingRoleId, setSavingRoleId] = useState<number | null>(null);

  const getRoleMinCapacity = (roleName: string): number => {
    return roleName === 'super_admin' || roleName === 'flow_manager' ? 0 : 1;
  };

  const getRoleDisplayName = (role: Role): string => {
    if (role.name === 'super_admin') {
      return 'Administrador';
    }
    return role.display_name;
  };

  useEffect(() => {
    Promise.all([api.getRoles(), api.getPermissions()])
      .then(([rolesRes, permsRes]) => {
        setRoles(rolesRes.data);
        setPermissions(permsRes.data);

        // Initialize capacities with dynamic role defaults (0 for admin/manager, 5 for operational)
        const initialCapacities: Record<number, number> = {};
        rolesRes.data.forEach((r) => {
          const defaultVal = getRoleMinCapacity(r.name) === 0 ? 0 : 5;
          initialCapacities[r.id] = r.max_concurrent_conversations ?? defaultVal;
        });
        setCapacities(initialCapacities);
      })
      .catch(console.error)
      .finally(() => setLoading(false));
  }, []);

  const handleCapacityChange = (roleId: number, val: number, minCap: number) => {
    const clamped = Math.max(minCap, Math.min(50, val));
    setCapacities((prev) => ({ ...prev, [roleId]: clamped }));
  };

  const handleSaveCapacity = async (roleId: number, minCap: number) => {
    const defaultVal = minCap === 0 ? 0 : 5;
    const capacity = capacities[roleId] ?? defaultVal;
    setSavingRoleId(roleId);

    try {
      const response = await api.updateRole(roleId, {
        max_concurrent_conversations: capacity,
      });

      // Update role in list
      setRoles((prev) =>
        prev.map((r) => (r.id === roleId ? { ...r, max_concurrent_conversations: response.data.max_concurrent_conversations } : r))
      );
      const roleName = roles.find((role) => role.id === roleId)?.display_name || 'el rol';
      showToast(`Capacidad de autoasignación de «${roleName}» guardada.`, 'success');
    } catch (err: any) {
      console.error('Error saving role capacity:', err);
      const msg = err.response?.data?.message || err.message || 'Error al guardar capacidad';
      showToast(msg, 'error');
    } finally {
      setSavingRoleId(null);
    }
  };

  return (
    <div>
      <div className="card">
        <div className="card-header">
          <div>
            <h2 className="card-title">Roles y Permisos (RBAC)</h2>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.875rem' }}>
              Permisos organizados por área para revisar de un vistazo qué puede hacer cada rol.
            </p>
          </div>
        </div>

        {loading ? (
          <p style={{ color: 'var(--text-muted)' }}>Cargando roles...</p>
        ) : (
          <div className="roles-grid">
            {roles.map((role) => {
              const displayName = getRoleDisplayName(role);
              const minCap = getRoleMinCapacity(role.name);
              const defaultVal = minCap === 0 ? 0 : 5;
              const currentCap = capacities[role.id] ?? role.max_concurrent_conversations ?? defaultVal;
              const isSaving = savingRoleId === role.id;

              return (
                <div
                  key={role.id}
                  className="role-card"
                >
                  <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.5rem' }}>
                      <Shield size={20} color="#5d8f82" />
                      <h3 style={{ fontSize: '1.05rem', fontWeight: 600 }}>{displayName}</h3>
                    </div>
                    <div style={{ fontSize: '0.75rem', fontFamily: 'monospace', color: '#61726d', marginBottom: '0.75rem' }}>
                      identifier: {role.name}
                    </div>
                    <p style={{ fontSize: '0.85rem', color: 'var(--text-muted)', marginBottom: '1rem', minHeight: '36px' }}>
                      {role.description}
                    </p>

                    <details className="role-permissions" aria-label={`Permisos de ${displayName}`}>
                      <summary className="role-permissions-heading">
                        <span>Permisos asignados</span>
                        <span className="role-permissions-heading-meta">
                          <span className="role-permissions-total">{role.permissions.length}</span>
                          <span className="role-permissions-toggle" aria-hidden="true" />
                        </span>
                      </summary>
                      <div className="role-permission-groups">
                        {groupPermissions(role.permissions).map((group) => (
                          <details className="role-permission-group" key={group.key}>
                            <summary>
                              <span>{group.label}</span>
                              <span className="role-permission-count">{group.permissions.length}</span>
                            </summary>
                            <ul>
                              {group.permissions.map((permission) => (
                                <li key={permission.id}>
                                  <Check size={14} aria-hidden="true" />
                                  <span>{permission.display_name}</span>
                                </li>
                              ))}
                            </ul>
                          </details>
                        ))}
                      </div>
                    </details>
                  </div>

                  {/* Configuración operativa de capacidad de autoasignación */}
                  <div
                    style={{
                      borderTop: '1px solid var(--border)',
                      paddingTop: '0.85rem',
                      marginTop: '0.5rem',
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.4rem' }}>
                      <span
                        style={{
                          fontSize: '0.8rem',
                          fontWeight: 600,
                          color: 'var(--text-main)',
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: '0.3rem',
                        }}
                      >
                        Capacidad de autoasignación:
                        <span
                          title="Número máximo de conversaciones concurrentes que el sistema auto-asignará a usuarios con este rol."
                          style={{ display: 'inline-flex', cursor: 'help', color: 'var(--text-muted)' }}
                        >
                          <HelpCircle size={14} />
                        </span>
                      </span>
                    </div>

                    {canManageRoles ? (
                      <div>
                        <div
                          className="role-capacity-controls"
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '0.5rem',
                            whiteSpace: 'nowrap',
                            minHeight: '32px',
                          }}
                        >
                          <div
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              border: '1px solid var(--border)',
                              borderRadius: 'var(--radius)',
                              background: '#f4f7f5',
                              overflow: 'hidden',
                              flexShrink: 0,
                            }}
                          >
                            <button
                              type="button"
                              onClick={() => handleCapacityChange(role.id, currentCap - 1, minCap)}
                              disabled={isSaving || currentCap <= minCap}
                              style={{
                                padding: '4px 8px',
                                border: 'none',
                                background: 'transparent',
                                cursor: isSaving || currentCap <= minCap ? 'not-allowed' : 'pointer',
                                display: 'flex',
                                alignItems: 'center',
                                color: currentCap <= minCap ? 'var(--text-muted)' : 'var(--text-main)',
                              }}
                              aria-label={`Disminuir capacidad de ${displayName}`}
                            >
                              <Minus size={14} />
                            </button>
                            <input
                              type="number"
                              min={minCap}
                              max={50}
                              value={currentCap}
                              onChange={(e) => {
                                const parsed = parseInt(e.target.value, 10);
                                handleCapacityChange(role.id, isNaN(parsed) ? minCap : parsed, minCap);
                              }}
                              aria-label={`Capacidad para ${displayName}`}
                              className="input-no-spinners"
                              style={{
                                width: '44px',
                                textAlign: 'center',
                                border: 'none',
                                outline: 'none',
                                fontWeight: 700,
                                fontSize: '0.85rem',
                                padding: '4px 0',
                                background: 'transparent',
                                MozAppearance: 'textfield',
                              }}
                            />
                            <button
                              type="button"
                              onClick={() => handleCapacityChange(role.id, currentCap + 1, minCap)}
                              disabled={isSaving || currentCap >= 50}
                              style={{
                                padding: '4px 8px',
                                border: 'none',
                                background: 'transparent',
                                cursor: isSaving || currentCap >= 50 ? 'not-allowed' : 'pointer',
                                display: 'flex',
                                alignItems: 'center',
                                color: currentCap >= 50 ? 'var(--text-muted)' : 'var(--text-main)',
                              }}
                              aria-label={`Aumentar capacidad de ${displayName}`}
                            >
                              <Plus size={14} />
                            </button>
                          </div>

                          {currentCap === 0 ? (
                            <span
                              className="badge"
                              style={{
                                fontSize: '0.75rem',
                                backgroundColor: '#eaf1ee',
                                color: '#61726d',
                                border: '1px solid #d9e3de',
                                padding: '3px 8px',
                                fontWeight: 500,
                                whiteSpace: 'nowrap',
                                flexShrink: 0,
                              }}
                            >
                              Sin autoasignación
                            </span>
                          ) : (
                            <span
                              style={{
                                fontSize: '0.8rem',
                                color: 'var(--text-muted)',
                                whiteSpace: 'nowrap',
                                flexShrink: 0,
                              }}
                            >
                              chats activos
                            </span>
                          )}

                          <button
                            type="button"
                            className="btn btn-sm btn-primary"
                            onClick={() => handleSaveCapacity(role.id, minCap)}
                            disabled={isSaving}
                            style={{
                              marginLeft: 'auto',
                              padding: '4px 10px',
                              fontSize: '0.75rem',
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '0.3rem',
                              whiteSpace: 'nowrap',
                              flexShrink: 0,
                            }}
                          >
                            {isSaving ? (
                              <>
                                <Loader2 size={12} className="animate-spin" /> Guardando...
                              </>
                            ) : (
                              'Guardar'
                            )}
                          </button>
                        </div>
                      </div>
                    ) : (
                      <div style={{ minHeight: '32px', display: 'flex', alignItems: 'center' }}>
                        {currentCap === 0 ? (
                          <span
                            className="badge"
                            style={{
                              fontSize: '0.75rem',
                              backgroundColor: '#eaf1ee',
                              color: '#61726d',
                              border: '1px solid #d9e3de',
                              padding: '3px 8px',
                              fontWeight: 500,
                              whiteSpace: 'nowrap',
                            }}
                          >
                            Sin autoasignación
                          </span>
                        ) : (
                          <div style={{ fontSize: '0.85rem', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                            <span style={{ fontWeight: 600, color: 'var(--text-main)' }}>[ {currentCap} ]</span> chats activos
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      <div className="card">
        <div className="card-header">
          <div>
            <h3 className="card-title" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <Key size={18} /> Catálogo de permisos
            </h3>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.875rem', marginTop: '0.25rem' }}>
              {permissions.length} permisos distribuidos en {groupPermissions(permissions).length} áreas.
            </p>
          </div>
        </div>

        <div className="permission-catalog-grid">
          {groupPermissions(permissions).map((group) => (
            <section className="permission-catalog-group" key={group.key}>
              <header>
                <span>{group.label}</span>
                <span>{group.permissions.length}</span>
              </header>
              <ul>
                {group.permissions.map((permission) => (
                  <li key={permission.id}>
                    <span className="permission-catalog-name">{permission.display_name}</span>
                    <code>{permission.name}</code>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      </div>
    </div>
  );
};
