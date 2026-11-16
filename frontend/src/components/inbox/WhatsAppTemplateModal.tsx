import React, { useState, useEffect } from 'react';
import { X, FileText, Send, CheckCircle2, ShieldAlert, AlertTriangle } from 'lucide-react';
import { api } from '../../services/api';
import { useToast } from '../../context/ToastContext';

interface TemplateItem {
  key: string;
  name: string;
  category: string;
  language: string;
  status: string;
  text: string;
  components: any[];
}

interface WhatsAppTemplateModalProps {
  isOpen: boolean;
  onClose: () => void;
  channelId?: string;
  conversationId?: string;
  contactName?: string;
  onSendTemplate?: (templateName: string) => void;
  onTemplateSent?: () => void;
}

export const WhatsAppTemplateModal: React.FC<WhatsAppTemplateModalProps> = (props) => {
  const scopeKey = [props.isOpen ? 'open' : 'closed', props.channelId ?? '', props.conversationId ?? '', props.contactName ?? 'Cliente'].join(':');

  return <WhatsAppTemplateModalContent key={scopeKey} {...props} />;
};

const WhatsAppTemplateModalContent: React.FC<WhatsAppTemplateModalProps> = ({
  isOpen,
  onClose,
  channelId,
  conversationId,
  contactName = 'Cliente',
  onSendTemplate,
  onTemplateSent,
}) => {
  const { showToast } = useToast();
  const [templateLoadState, setTemplateLoadState] = useState(() => ({
    channelId: channelId ?? null,
    templates: [] as TemplateItem[],
    loading: isOpen && Boolean(channelId),
  }));
  const templates = templateLoadState.channelId === channelId ? templateLoadState.templates : [];
  const loading = isOpen && Boolean(channelId) && (templateLoadState.channelId !== channelId || templateLoadState.loading);
  const [selectedTemplateKey, setSelectedTemplateKey] = useState<string>('');
  const [parameters, setParameters] = useState<Record<number, string>>({ 1: contactName });
  const [sending, setSending] = useState(false);
  const [sentSuccess, setSentSuccess] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => {
    if (!isOpen || !channelId) return;

    let isMounted = true;
    api.getWhatsAppTemplates(channelId)
      .then((res) => {
        if (!isMounted) return;
        // Strictly filter only templates verified by Meta as APPROVED
        const mapped: TemplateItem[] = (res.data || [])
          .filter((item: any) => (item.status || '').toUpperCase() === 'APPROVED')
          .map((item: any) => {
            const bodyComponent = item.components?.find((c: any) => c.type === 'BODY');
            return {
              key: `${item.name}:${item.language || 'es'}`,
              name: item.name,
              category: item.category || 'UTILITY',
              language: item.language || 'es',
              status: 'APPROVED',
              text: item.text || bodyComponent?.text || '',
              components: item.components || [],
            };
          });

        setTemplateLoadState({ channelId, templates: mapped, loading: false });
        if (mapped.length > 0) {
          setSelectedTemplateKey(mapped[0].key);
        } else {
          setSelectedTemplateKey('');
        }
      })
      .catch((err) => {
        console.warn('Error al cargar plantillas de Meta:', err);
        if (isMounted) {
          setTemplateLoadState({ channelId, templates: [], loading: false });
          setSelectedTemplateKey('');
          setErrorMessage('No pudimos cargar las plantillas de WhatsApp. Inténtalo de nuevo.');
        }
      });

    return () => {
      isMounted = false;
    };
  }, [isOpen, channelId]);

  if (!isOpen) return null;

  const currentTemplate = templates.find((t) => t.key === selectedTemplateKey) || templates[0];
  const bodyVariables = [...(currentTemplate?.text.matchAll(/\{\{\s*(\d+)\s*\}\}/g) || [])]
    .map((match) => Number(match[1]))
    .filter((number, index, all) => Number.isInteger(number) && number > 0 && all.indexOf(number) === index)
    .sort((a, b) => a - b);
  const unsupportedVariables = (currentTemplate?.components || []).some((component) =>
    component.type !== 'BODY' && /\{\{\s*\d+\s*\}\}/.test(component.text || '')
  );

  const renderedPreview = currentTemplate?.text
    ? currentTemplate.text.replace(/\{\{\s*(\d+)\s*\}\}/g, (_match, index) => parameters[Number(index)] || `{{${index}}}`)
    : '';

  const handleSend = async () => {
    if (!currentTemplate) return;
    setSending(true);
    setErrorMessage(null);

    try {
      if (unsupportedVariables) {
        throw new Error('Esta plantilla requiere parámetros en componentes de encabezado o botones que todavía no se pueden completar desde aquí.');
      }

      if (conversationId) {
        await api.sendWhatsAppTemplate(conversationId, {
          name: currentTemplate.name,
          language: { code: currentTemplate.language || 'es' },
          ...(bodyVariables.length > 0 ? { components: [{
              type: 'body',
              parameters: bodyVariables.map((index) => ({
                  type: 'text',
                  text: parameters[index] || '',
              })),
          }] } : {}),
        });
      }

      setSentSuccess(true);
      if (onSendTemplate) {
        onSendTemplate(currentTemplate.name);
      }
      if (onTemplateSent) {
        onTemplateSent();
      }

      setTimeout(() => {
        setSentSuccess(false);
        onClose();
      }, 1500);
    } catch (err: any) {
      const msg = err.response?.data?.message || err.message || 'Error al enviar plantilla a través de WhatsApp.';
      showToast(msg, 'error');
    } finally {
      setSending(false);
    }
  };

  return (
    <div
      role="dialog"
      aria-modal="true"
      data-testid="whatsapp-template-modal"
      className="modal-overlay"
      style={{
        position: 'fixed',
        inset: 0,
        backgroundColor: 'rgba(15, 23, 42, 0.65)',
        backdropFilter: 'blur(3px)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 9999,
        padding: '1rem',
      }}
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div
        className="modal-content flow-side-panel"
        style={{
          background: 'white',
          borderRadius: '12px',
          width: '100%',
          maxWidth: '540px',
          boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)',
          overflow: 'hidden',
          display: 'flex',
          flexDirection: 'column',
          maxHeight: '90vh',
        }}
      >
        {/* Modal Header */}
        <div className="flow-side-panel-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            <div
              style={{
                width: '32px',
                height: '32px',
                borderRadius: '8px',
                background: '#eaf1ee',
                color: '#5d8f82',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <FileText size={18} />
            </div>
            <div>
              <h3 style={{ fontSize: '0.95rem', fontWeight: 600, color: '#263c38', margin: 0 }}>
                Reabrir conversación con Plantilla
              </h3>
              <p style={{ fontSize: '0.75rem', color: '#61726d', margin: 0 }}>
                Ventana de 24 horas de WhatsApp (Meta)
              </p>
            </div>
          </div>
          <button
            type="button"
            data-testid="close-template-modal-btn"
            onClick={onClose}
            style={{
              background: 'none',
              border: 'none',
              color: '#61726d',
              cursor: 'pointer',
              padding: '4px',
              borderRadius: '6px',
            }}
          >
            <X size={18} />
          </button>
        </div>

        {/* Modal Body */}
        <div className="flow-side-panel-body" style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          {/* Policy Information Box */}
          <div
            style={{
              padding: '0.75rem 1rem',
              background: '#fffbeb',
              border: '1px solid #fef3c7',
              borderRadius: '8px',
              fontSize: '0.8rem',
              color: '#92400e',
              lineHeight: 1.45,
              display: 'flex',
              gap: '0.6rem',
            }}
          >
            <ShieldAlert size={16} style={{ color: '#d97706', flexShrink: 0, marginTop: '2px' }} />
            <div>
              <strong>Política de Meta:</strong> Han pasado más de 24 horas desde el último mensaje del usuario. Para contactarlo nuevamente, debes enviar una plantilla preaprobada (HSM). Una vez que el usuario responda, la ventana de 24 horas se reabrirá para texto libre.
            </div>
          </div>

          {errorMessage && (
            <div style={{ padding: '0.75rem', background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: '8px', color: '#991b1b', fontSize: '0.8rem' }}>
              {errorMessage}
            </div>
          )}

          {loading ? (
            <div style={{ padding: '2rem 1rem', textAlign: 'center', color: '#61726d', fontSize: '0.85rem' }}>
              Consultando plantillas aprobadas por Meta...
            </div>
          ) : templates.length === 0 ? (
            <div
              data-testid="no-approved-templates-notice"
              style={{
                padding: '1.25rem',
                background: '#f4f7f5',
                border: '1px dashed #d9e3de',
                borderRadius: '8px',
                textAlign: 'center',
                color: '#61726d',
              }}
            >
              <AlertTriangle size={28} style={{ color: '#f59e0b', margin: '0 auto 0.5rem auto' }} />
              <div style={{ fontWeight: 600, fontSize: '0.9rem', color: '#35564f', marginBottom: '0.35rem' }}>
                No hay plantillas aprobadas por Meta disponibles
              </div>
              <p style={{ fontSize: '0.8rem', color: '#61726d', margin: '0 0 0.75rem 0', lineHeight: 1.45 }}>
                Para contactar a clientes fuera de la ventana de 24 horas, debes crear y aprobar plantillas en tu cuenta comercial de WhatsApp (WABA). Al ser aprobadas por Meta, aparecerán aquí automáticamente.
              </p>
            </div>
          ) : (
            <>
              {/* Template Selector */}
              <div>
                <label htmlFor="whatsapp-template-select" style={{ fontSize: '0.8rem', fontWeight: 600, color: '#56736b', marginBottom: '0.4rem', display: 'block' }}>
                  Plantilla preaprobada seleccionada:
                </label>
                <select
                  id="whatsapp-template-select"
                  className="form-select"
                  value={currentTemplate?.key || ''}
                  onChange={(e) => setSelectedTemplateKey(e.target.value)}
                  style={{ width: '100%', marginBottom: '0.75rem', fontSize: '0.85rem' }}
                >
                  {templates.map((tpl) => (
                    <option key={tpl.key} value={tpl.key}>
                      {tpl.name} ({tpl.language.toUpperCase()} — {tpl.category})
                    </option>
                  ))}
                </select>

                <div
                  style={{
                    border: '1px solid #d9e3de',
                    borderRadius: '8px',
                    padding: '0.85rem',
                    background: '#f4f7f5',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                    <span style={{ fontSize: '0.82rem', fontWeight: 600, color: '#35564f' }}>
                      {currentTemplate?.name}
                    </span>
                    <span
                      style={{
                        fontSize: '0.7rem',
                        background: '#eaf1ee',
                        color: '#263c38',
                        padding: '2px 8px',
                        borderRadius: '9999px',
                        fontWeight: 500,
                      }}
                    >
                      Aprobada por Meta
                    </span>
                  </div>

                  {/* Chat Bubble Preview */}
                  <div
                    style={{
                      background: '#dcf8c6',
                      color: '#263c38',
                      padding: '0.75rem 0.9rem',
                      borderRadius: '8px',
                      boxShadow: '0 1px 2px rgba(0,0,0,0.06)',
                      fontSize: '0.82rem',
                      lineHeight: 1.45,
                    }}
                  >
                    <div style={{ fontWeight: 600, color: '#263c38', marginBottom: '0.25rem' }}>
                      Vista previa
                    </div>
                    <div>
                      {renderedPreview}
                    </div>
                    <div style={{ fontSize: '0.68rem', color: '#5d8f82', textAlign: 'right', marginTop: '0.35rem' }}>
                      Responde a este mensaje para reabrir el chat
                    </div>
                  </div>
                </div>
              </div>

              {bodyVariables.length > 0 && (
                <div style={{ background: '#f4f7f5', padding: '0.75rem 1rem', borderRadius: '8px', border: '1px solid #d9e3de', display: 'grid', gap: '0.6rem' }}>
                  {bodyVariables.map((index) => (
                    <label key={index} style={{ fontSize: '0.8rem', fontWeight: 600, color: '#56736b' }}>
                      Parámetro &#123;&#123;{index}&#125;&#125;:
                      <input
                        type="text"
                        className="form-input"
                        value={parameters[index] || ''}
                        onChange={(e) => setParameters((current) => ({ ...current, [index]: e.target.value }))}
                        placeholder={index === 1 ? contactName : `Valor para {{${index}}}`}
                        style={{ fontSize: '0.85rem', marginTop: '0.35rem' }}
                      />
                    </label>
                  ))}
                </div>
              )}
              {unsupportedVariables && (
                <div role="alert" style={{ color: '#92400e', background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: '8px', padding: '0.75rem', fontSize: '0.8rem' }}>
                  Esta plantilla tiene parámetros en encabezado o botones. Esta pantalla aún no permite completarlos.
                </div>
              )}
            </>
          )}

          {/* Meta Commercial Manager Notice */}
          <div
            style={{
              padding: '0.65rem 0.85rem',
              background: '#eaf1ee',
              borderRadius: '8px',
              fontSize: '0.75rem',
              color: '#61726d',
              lineHeight: 1.4,
            }}
          >
            💡 <em>Las plantillas de mensaje oficiales se configuran y aprueban desde el <strong>Administrador Comercial de WhatsApp de Meta</strong>. Al sincronizarse, estarán disponibles dinámicamente en esta lista.</em>
          </div>

          {sentSuccess && (
            <div
              style={{
                padding: '0.65rem',
                background: '#eaf1ee',
                color: '#263c38',
                borderRadius: '8px',
                fontSize: '0.8rem',
                display: 'flex',
                alignItems: 'center',
                gap: '0.5rem',
                fontWeight: 500,
              }}
            >
              <CheckCircle2 size={16} />
              <span>Plantilla enviada exitosamente. Esperando respuesta del cliente...</span>
            </div>
          )}
        </div>

        {/* Modal Footer */}
        <div className="flow-side-panel-footer">
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={onClose}
            disabled={sending}
          >
            Cancelar
          </button>
          <button
            type="button"
            data-testid="send-whatsapp-template-btn"
            className="btn btn-primary btn-sm"
            onClick={handleSend}
            disabled={sending || sentSuccess || templates.length === 0 || unsupportedVariables}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '0.4rem',
              background: templates.length === 0 || unsupportedVariables ? '#80918c' : '#5d8f82',
              borderColor: templates.length === 0 || unsupportedVariables ? '#80918c' : '#5d8f82',
              cursor: templates.length === 0 || unsupportedVariables ? 'not-allowed' : 'pointer',
            }}
          >
            <Send size={14} />
            <span>{sending ? 'Enviando plantilla...' : 'Enviar plantilla'}</span>
          </button>
        </div>
      </div>
    </div>
  );
};
