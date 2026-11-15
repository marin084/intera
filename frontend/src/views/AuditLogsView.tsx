import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import type { AuditLog } from '../types';
import { FileText, User, Globe } from 'lucide-react';

const ACTION_TRANSLATIONS: Record<string, { label: string; bg: string; color: string }> = {
  'flow.create': { label: 'Flujo creado', bg: '#eaf1ee', color: '#263c38' },
  'flow.update': { label: 'Flujo actualizado', bg: '#eaf1ee', color: '#456c61' },
  'flow.delete': { label: 'Flujo eliminado', bg: '#fee2e2', color: '#b91c1c' },
  'flow.publish': { label: 'Versión publicada', bg: '#d1fae5', color: '#263c38' },
  'flow.rollback': { label: 'Rollback de versión', bg: '#fef3c7', color: '#b45309' },
  'channel.create': { label: 'Canal creado', bg: '#eaf1ee', color: '#263c38' },
  'channel.update': { label: 'Canal modificado', bg: '#eaf1ee', color: '#456c61' },
  'channel.key_regenerated': { label: 'Clave regenerada', bg: '#fef3c7', color: '#b45309' },
  'user.create': { label: 'Usuario creado', bg: '#eaf1ee', color: '#263c38' },
  'user.update': { label: 'Usuario modificado', bg: '#eaf1ee', color: '#456c61' },
  'user.status_toggle': { label: 'Estado cambiado', bg: '#eaf1ee', color: '#61726d' },
  'auth.login': { label: 'Inicio de sesión', bg: '#eaf1ee', color: '#456c61' },
  'auth.logout': { label: 'Cierre de sesión', bg: '#eaf1ee', color: '#61726d' },
  'auth.password_change': { label: 'Contraseña cambiada', bg: '#eaf1ee', color: '#456c61' },
  'conversation.claim': { label: 'Conversación tomada', bg: '#d1fae5', color: '#263c38' },
  'conversation.assign': { label: 'Conversación asignada', bg: '#eaf1ee', color: '#456c61' },
  'conversation.close': { label: 'Conversación cerrada', bg: '#eaf1ee', color: '#61726d' },
};

const ENTITY_TRANSLATIONS: Record<string, string> = {
  flow: 'Flujo',
  flow_version: 'Versión de Flujo',
  channel: 'Canal',
  user: 'Usuario',
  conversation: 'Conversación',
};

export const AuditLogsView: React.FC = () => {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.getAuditLogs()
      .then((res) => setLogs(res.data))
      .catch(console.error)
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="card">
      <div className="card-header">
        <div>
          <h2 className="card-title">Registro de Auditoría</h2>
          <p style={{ color: 'var(--text-muted)', fontSize: '0.875rem' }}>
            Trazabilidad y registro inmutable de operaciones administrativas y de seguridad.
          </p>
        </div>
      </div>

      {loading ? (
        <p style={{ color: 'var(--text-muted)', padding: '1rem' }}>Cargando eventos de auditoría...</p>
      ) : logs.length === 0 ? (
        <div style={{ textAlign: 'center', padding: '3.5rem 1rem', color: 'var(--text-muted)' }}>
          <FileText size={44} style={{ opacity: 0.35, marginBottom: '0.75rem', color: '#5d8f82' }} />
          <h3 style={{ fontSize: '1.05rem', fontWeight: 600, color: '#35564f', marginBottom: '0.35rem' }}>No hay eventos de auditoría registrados</h3>
          <p style={{ fontSize: '0.85rem', maxWidth: '400px', margin: '0 auto' }}>
            Las operaciones críticas del sistema (cambios en flujos, inicio de sesión, publicación y gestión de usuarios) se registrarán aquí con sello temporal inmutable.
          </p>
        </div>
      ) : (
        <div className="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Fecha y Hora</th>
                <th>Acción</th>
                <th>Usuario</th>
                <th>Entidad Afectada</th>
                <th>Dirección IP</th>
                <th>Contexto / Metadatos</th>
              </tr>
            </thead>
            <tbody>
              {logs.map((log) => {
                const actionMeta = ACTION_TRANSLATIONS[log.action] || {
                  label: log.action,
                  bg: '#eaf1ee',
                  color: '#61726d',
                };
                const entityName = log.entity_type ? (ENTITY_TRANSLATIONS[log.entity_type] || log.entity_type) : null;
                const hasMetadata = log.metadata && Object.keys(log.metadata).length > 0;

                return (
                  <tr key={log.id}>
                    <td style={{ color: 'var(--text-muted)', fontSize: '0.8rem', whiteSpace: 'nowrap' }}>
                      {new Date(log.created_at).toLocaleString()}
                    </td>
                    <td>
                      <span
                        className="badge"
                        style={{
                          background: actionMeta.bg,
                          color: actionMeta.color,
                          fontWeight: 600,
                          fontSize: '0.75rem',
                        }}
                        title={`Acción interna: ${log.action}`}
                      >
                        {actionMeta.label}
                      </span>
                    </td>
                    <td>
                      {log.user ? (
                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.85rem' }}>
                          <User size={14} color="#61726d" />
                          <span>{log.user.name}</span>
                        </div>
                      ) : (
                        <span style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>Sistema / Proceso</span>
                      )}
                    </td>
                    <td>
                      {entityName ? (
                        <span className="badge badge-secondary" style={{ fontSize: '0.75rem' }}>
                          {entityName} {log.entity_id ? `#${log.entity_id.slice(0, 8)}` : ''}
                        </span>
                      ) : (
                        <span style={{ color: 'var(--text-muted)' }}>-</span>
                      )}
                    </td>
                    <td style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
                        <Globe size={12} />
                        <span>{log.ip_address || '127.0.0.1'}</span>
                      </div>
                    </td>
                    <td>
                      {hasMetadata ? (
                        <pre style={{ margin: 0, fontSize: '0.7rem', background: '#f4f7f5', padding: '0.25rem 0.5rem', borderRadius: '4px', maxWidth: '280px', overflow: 'hidden', textOverflow: 'ellipsis', border: '1px solid #d9e3de' }}>
                          {JSON.stringify(log.metadata)}
                        </pre>
                      ) : (
                        <span style={{ color: 'var(--text-muted)', fontSize: '0.75rem' }}>Sin metadatos</span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
};
