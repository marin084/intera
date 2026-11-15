import React, { useState } from 'react';
import { CheckCircle2, AlertTriangle, X, Loader2, Send } from 'lucide-react';

export interface FlowPublishModalProps {
  isOpen: boolean;
  versionNumber: number | string;
  notes: string;
  onChangeNotes: (notes: string) => void;
  onPublish: (notes: string) => Promise<boolean>;
  onClose: () => void;
  onSuccessDismiss: () => void;
}

export const FlowPublishModal: React.FC<FlowPublishModalProps> = ({
  isOpen,
  versionNumber,
  notes,
  onChangeNotes,
  onPublish,
  onClose,
  onSuccessDismiss,
}) => {
  const [step, setStep] = useState<'form' | 'success'>('form');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  if (!isOpen) return null;

  const handleClose = () => {
    setStep('form');
    setErrorMessage(null);
    onClose();
  };

  const handleSuccessClose = () => {
    setStep('form');
    setErrorMessage(null);
    onSuccessDismiss();
  };

  const handleSubmit = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (isSubmitting) return;

    setIsSubmitting(true);
    setErrorMessage(null);
    try {
      const ok = await onPublish(notes);
      if (ok) {
        setStep('success');
      }
    } catch (err: any) {
      setErrorMessage(err.message || 'Error al publicar la versión.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="modal-overlay" style={{ zIndex: 1050 }}>
      <div className="modal-content side-panel-compact" style={{ maxWidth: '480px' }}>
        {step === 'form' ? (
          <>
            <div className="modal-header">
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
                <div style={{ background: '#eaf1ee', padding: '0.45rem', borderRadius: '8px', color: '#5d8f82' }}>
                  <Send size={18} />
                </div>
                <h3 style={{ fontSize: '1.15rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
                  Publicar versión {versionNumber}
                </h3>
              </div>
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                onClick={handleClose}
                disabled={isSubmitting}
                aria-label="Cerrar modal"
              >
                <X size={16} />
              </button>
            </div>

            {errorMessage && (
              <div
                style={{
                  background: '#fef2f2',
                  border: '1px solid #fecdd3',
                  color: '#b91c1c',
                  padding: '0.65rem 0.85rem',
                  borderRadius: '6px',
                  fontSize: '0.825rem',
                  display: 'flex',
                  alignItems: 'center',
                  gap: '0.5rem',
                  marginBottom: '1rem',
                }}
              >
                <AlertTriangle size={16} style={{ flexShrink: 0 }} />
                <span>{errorMessage}</span>
              </div>
            )}

            <form onSubmit={handleSubmit}>
              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.85rem', margin: '0.5rem 0 1.25rem', fontSize: '0.875rem' }}>
                <p style={{ margin: 0, color: '#56736b', lineHeight: 1.5 }}>
                  Una versión publicada es <strong>inmutable</strong>. Para realizar cambios posteriores deberás crear una nueva versión borrador.
                </p>

                <div
                  style={{
                    background: '#f4f7f5',
                    padding: '0.75rem',
                    borderRadius: '6px',
                    border: '1px solid #d9e3de',
                    fontSize: '0.8rem',
                    color: '#61726d',
                    lineHeight: 1.45,
                  }}
                >
                  <div style={{ fontWeight: 600, color: '#35564f', marginBottom: '0.2rem' }}>Version Pinning garantizado:</div>
                  Las conversaciones que ya estén usando una versión anterior continuarán en esa versión sin verse alteradas.
                </div>

                <div>
                  <label
                    htmlFor="publish-notes-textarea"
                    style={{ display: 'block', fontSize: '0.8rem', fontWeight: 600, color: '#61726d', marginBottom: '0.25rem' }}
                  >
                    Notas de publicación (opcional)
                  </label>
                  <textarea
                    id="publish-notes-textarea"
                    className="form-textarea"
                    rows={2}
                    value={notes}
                    onChange={(e) => onChangeNotes(e.target.value)}
                    placeholder="Ej. Añadido flujo de bienvenida y preguntas frecuentes..."
                    style={{ width: '100%', fontSize: '0.825rem' }}
                    disabled={isSubmitting}
                  />
                </div>
              </div>

              <div className="modal-footer" style={{ borderTop: '1px solid var(--border)', paddingTop: '0.75rem', marginTop: 0 }}>
                <button
                  type="button"
                  className="btn btn-secondary"
                  onClick={handleClose}
                  disabled={isSubmitting}
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="btn btn-primary"
                  disabled={isSubmitting}
                  style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}
                >
                  {isSubmitting ? (
                    <>
                      <Loader2 size={14} className="animate-spin" />
                      <span>Publicando...</span>
                    </>
                  ) : (
                    <span>Publicar versión</span>
                  )}
                </button>
              </div>
            </form>
          </>
        ) : (
          <div style={{ padding: '0.75rem 0.5rem 0.25rem', textAlign: 'center' }}>
            <div
              style={{
                width: '76px',
                height: '76px',
                borderRadius: '50%',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                margin: '0.5rem auto 1.25rem',
              }}
              className="bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400"
            >
              <CheckCircle2 size={48} />
            </div>

            <h3 style={{ fontSize: '1.25rem', fontWeight: 700, margin: '0 0 0.5rem', color: 'var(--text-main)' }}>
              ¡Versión {versionNumber} publicada con éxito!
            </h3>

            <p style={{ fontSize: '0.875rem', color: '#61726d', margin: '0 0 1.5rem', lineHeight: 1.5 }}>
              Esta versión ya está activa y atendiendo a los usuarios en tus canales conectados.
            </p>

            <button
              type="button"
              className="btn btn-primary"
              style={{ width: '100%', padding: '0.65rem 1rem', fontWeight: 600, fontSize: '0.9rem' }}
              onClick={handleSuccessClose}
            >
              Continuar en el editor
            </button>
          </div>
        )}
      </div>
    </div>
  );
};
