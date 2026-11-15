import React, { useState, useEffect } from 'react';
import { api } from '../../services/api';
import type { FunnelContact } from '../../types';
import { X, Download, MessageSquare, User, Radio, Calendar, Activity } from 'lucide-react';

interface FunnelContactsModalProps {
  isOpen: boolean;
  onClose: () => void;
  flowId: string;
  stepNumber?: number | null;
  stepLabel?: string;
  steps?: any[] | null;
  nodeId?: string | null;
  nodeKey?: string | null;
  optionId?: string | null;
  optionTitle?: string;
  from?: string;
  to?: string;
  channelId?: string;
  onOpenConversation?: (conversationId: string) => void;
}

export const FunnelContactsModal: React.FC<FunnelContactsModalProps> = ({
  isOpen,
  onClose,
  flowId,
  stepNumber,
  stepLabel,
  steps,
  nodeId,
  nodeKey,
  optionId,
  optionTitle,
  from,
  to,
  channelId,
  onOpenConversation,
}) => {
  const [contacts, setContacts] = useState<FunnelContact[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [total, setTotal] = useState<number>(0);
  const [page, setPage] = useState<number>(1);
  const [lastPage, setLastPage] = useState<number>(1);

  useEffect(() => {
    if (!isOpen) return;

    let active = true;

    const promise = stepNumber
      ? api.getStepContacts({
          flow_id: flowId,
          step_number: stepNumber,
          steps: steps || undefined,
          from,
          to,
          channel_id: channelId,
          page,
          per_page: 20,
        })
      : api.getFunnelContacts({
          flow_id: flowId,
          node_id: nodeId || undefined,
          node_key: nodeKey || undefined,
          option_id: optionId || undefined,
          option_title: optionTitle || undefined,
          from,
          to,
          channel_id: channelId,
          page,
          per_page: 20,
        });

    promise
      .then((res) => {
        if (active) {
          setContacts(res.data);
          setTotal(res.total);
          setLastPage(res.last_page);
        }
      })
      .catch((err) => {
        console.error('Error fetching funnel contacts:', err);
        if (active) setContacts([]);
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [isOpen, flowId, stepNumber, stepLabel, steps, nodeId, nodeKey, optionId, optionTitle, from, to, channelId, page]);

  if (!isOpen) return null;

  const exportUrl = stepNumber
    ? api.getStepContactsExportUrl({
        flow_id: flowId,
        step_number: stepNumber,
        steps: steps || undefined,
        from,
        to,
        channel_id: channelId,
      })
    : api.getFunnelContactsExportUrl({
        flow_id: flowId,
        node_id: nodeId || undefined,
        node_key: nodeKey || undefined,
        option_id: optionId || undefined,
        option_title: optionTitle || '',
        from,
        to,
        channel_id: channelId,
      });

  const handleRowClick = (conversationId: string | null) => {
    if (!conversationId) return;
    if (onOpenConversation) {
      onOpenConversation(conversationId);
      onClose();
    } else {
      window.history.pushState(null, '', `/conversations?id=${conversationId}`);
      window.dispatchEvent(new PopStateEvent('popstate'));
      onClose();
    }
  };

  return (
    <div
      className="modal-overlay"
      style={{
        position: 'fixed',
        inset: 0,
        backgroundColor: 'rgba(15, 23, 42, 0.65)',
        backdropFilter: 'blur(3px)',
        zIndex: 9999,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '1rem',
      }}
      data-testid="funnel-contacts-modal"
      role="dialog"
      aria-modal="true"
    >
      <div
        className="modal-content side-panel-wide flow-side-panel"
        style={{
          width: '100%',
          maxWidth: '850px',
          maxHeight: '88vh',
          display: 'flex',
          flexDirection: 'column',
          background: 'var(--bg-card)',
          borderRadius: 'var(--radius)',
          boxShadow: 'var(--shadow-lg)',
          overflow: 'hidden',
        }}
      >
        {/* Header */}
        <div className="flow-side-panel-header">
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <span style={{ fontSize: '1.25rem' }}>{stepNumber ? '🪜' : '🔘'}</span>
              <h2 style={{ fontSize: '1.15rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                {stepNumber
                  ? `Contactos en: Paso ${stepNumber} — ${stepLabel || 'Etapa'}`
                  : `Contactos que seleccionaron: “${optionTitle}”`}
              </h2>
            </div>
            <p style={{ margin: '0.25rem 0 0', fontSize: '0.8rem', color: 'var(--text-muted)' }}>
              {stepNumber
                ? 'Usuarios únicos que alcanzaron esta etapa del embudo secuencial en la misma conversación.'
                : 'Trazabilidad exacta de usuarios y sesiones que transitaron por esta opción del bot.'}
            </p>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <a
              href={exportUrl}
              className="btn btn-secondary btn-sm"
              download
              title="Descargar listado en formato CSV para Excel"
              data-testid="export-contacts-csv-btn"
              style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', textDecoration: 'none' }}
            >
              <Download size={14} /> Descargar CSV
            </a>
            <button
              className="btn btn-secondary btn-sm"
              onClick={onClose}
              data-testid="close-modal-btn"
              aria-label="Cerrar panel"
              style={{ padding: '0.4rem' }}
            >
              <X size={18} />
            </button>
          </div>
        </div>

        {/* Content Table */}
        <div className="flow-side-panel-body" style={{ flex: 1 }}>
          {loading ? (
            <div style={{ padding: '3rem 1rem', textAlign: 'center', color: 'var(--text-muted)' }} data-testid="modal-loading">
              <Activity className="animate-spin" size={28} style={{ margin: '0 auto 0.75rem', color: 'var(--primary)' }} />
              <p style={{ margin: 0 }}>Cargando contactos de la opción...</p>
            </div>
          ) : contacts.length === 0 ? (
            <div style={{ padding: '3.5rem 1rem', textAlign: 'center', color: 'var(--text-muted)' }} data-testid="modal-empty">
              <User size={40} style={{ opacity: 0.35, margin: '0 auto 0.75rem' }} />
              <p style={{ fontWeight: 600, color: 'var(--text-main)', margin: '0 0 0.25rem' }}>
                Sin contactos registrados
              </p>
              <p style={{ fontSize: '0.85rem', margin: 0 }}>
                Ningún contacto seleccionó esta opción en el periodo de tiempo consultado.
              </p>
            </div>
          ) : (
            <div className="table-responsive">
              <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '0.875rem' }}>
                <thead>
                  <tr style={{ borderBottom: '1px solid var(--border)', color: 'var(--text-muted)', fontSize: '0.8rem' }}>
                    <th style={{ padding: '0.75rem 0.5rem' }}>Contacto / Usuario</th>
                    <th style={{ padding: '0.75rem 0.5rem' }}>Teléfono o ID</th>
                    <th style={{ padding: '0.75rem 0.5rem' }}>Canal</th>
                    <th style={{ padding: '0.75rem 0.5rem' }}>Fecha y Hora</th>
                    <th style={{ padding: '0.75rem 0.5rem', textAlign: 'right' }}>Acción</th>
                  </tr>
                </thead>
                <tbody>
                  {contacts.map((contact, idx) => {
                    const initials = (contact.contact_name || 'U')
                      .split(' ')
                      .map((w) => w[0])
                      .slice(0, 2)
                      .join('')
                      .toUpperCase();

                    return (
                      <tr
                        key={contact.conversation_id || idx}
                        style={{ borderBottom: '1px solid var(--border)' }}
                        data-testid={`contact-row-${contact.contact_id || idx}`}
                      >
                        <td style={{ padding: '0.75rem 0.5rem' }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: '0.65rem' }}>
                            <div
                              style={{
                                width: '32px',
                                height: '32px',
                                borderRadius: '50%',
                                background: 'var(--primary-light)',
                                color: 'var(--primary)',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                fontWeight: 700,
                                fontSize: '0.75rem',
                              }}
                            >
                              {initials}
                            </div>
                            <div>
                              <div style={{ fontWeight: 600, color: 'var(--text-main)' }}>
                                {contact.contact_name}
                              </div>
                              {contact.email && (
                                <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                                  {contact.email}
                                </div>
                              )}
                            </div>
                          </div>
                        </td>

                        <td style={{ padding: '0.75rem 0.5rem', color: 'var(--text-muted)' }}>
                          <code>{contact.phone_number}</code>
                        </td>

                        <td style={{ padding: '0.75rem 0.5rem' }}>
                          <span
                            className="badge badge-secondary"
                            style={{
                              textTransform: 'uppercase',
                              fontSize: '0.7rem',
                              padding: '0.2rem 0.45rem',
                            }}
                          >
                            <Radio size={11} style={{ display: 'inline', marginRight: '3px' }} />
                            {contact.channel_name || contact.channel_type}
                          </span>
                        </td>

                        <td style={{ padding: '0.75rem 0.5rem', color: 'var(--text-muted)', fontSize: '0.8rem' }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                            <Calendar size={13} />
                            <span>{contact.stepped_at_formatted || contact.stepped_at}</span>
                          </div>
                        </td>

                        <td style={{ padding: '0.75rem 0.5rem', textAlign: 'right' }}>
                          {contact.conversation_id && (
                            <button
                              className="btn btn-primary btn-sm"
                              onClick={() => handleRowClick(contact.conversation_id)}
                              title="Abrir conversación en el Inbox"
                              data-testid={`open-inbox-${contact.conversation_id}`}
                              style={{ padding: '0.3rem 0.6rem', fontSize: '0.75rem' }}
                            >
                              <MessageSquare size={12} /> Abrir en Inbox
                            </button>
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

        {/* Footer / Pagination */}
        <div
          style={{
            padding: '0.85rem 1.5rem',
            borderTop: '1px solid var(--border)',
            background: 'var(--bg-main)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            fontSize: '0.8rem',
            color: 'var(--text-muted)',
          }}
        >
          <span>
            Mostrando <strong>{contacts.length}</strong> de <strong>{total}</strong> contacto(s)
          </span>

          {lastPage > 1 && (
            <div style={{ display: 'flex', gap: '0.35rem' }}>
              <button
                className="btn btn-secondary btn-sm"
                disabled={page <= 1}
                onClick={() => {
                  setLoading(true);
                  setPage((p) => Math.max(1, p - 1));
                }}
              >
                Anterior
              </button>
              <span style={{ padding: '0.3rem 0.5rem' }}>
                Página {page} de {lastPage}
              </span>
              <button
                className="btn btn-secondary btn-sm"
                disabled={page >= lastPage}
                onClick={() => {
                  setLoading(true);
                  setPage((p) => Math.min(lastPage, p + 1));
                }}
              >
                Siguiente
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
