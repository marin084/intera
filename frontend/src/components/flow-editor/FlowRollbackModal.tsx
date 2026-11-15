import React, { useState, useEffect } from 'react';
import { RotateCcw, AlertTriangle, X } from 'lucide-react';
import type { FlowVersion } from '../../types';

interface FlowRollbackModalProps {
  version: FlowVersion;
  activeVersionNumber?: number;
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (notes?: string) => Promise<void>;
}

export const FlowRollbackModal: React.FC<FlowRollbackModalProps> = ({
  version,
  activeVersionNumber,
  isOpen,
  onClose,
  onConfirm,
}) => {
  const [notes, setNotes] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (!isOpen) return;
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !isSubmitting) {
        onClose();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose, isSubmitting]);

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    try {
      await onConfirm(notes);
      onClose();
    } catch {
      // Error handled by caller
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="modal-overlay" style={{ zIndex: 1050 }}>
      <div className="modal-content side-panel-compact" style={{ maxWidth: '520px' }}>
        <div className="modal-header" style={{ borderBottom: '1px solid #fee2e2' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            <div style={{ background: '#fee2e2', padding: '0.5rem', borderRadius: '8px', color: '#dc2626' }}>
              <RotateCcw size={20} />
            </div>
            <div>
              <h3 style={{ margin: 0, fontSize: '1.15rem', color: '#991b1b', fontWeight: 600 }}>
                Restaurar Versión Histórica v{version.version_number}
              </h3>
              <p style={{ margin: 0, fontSize: '0.8rem', color: '#61726d' }}>
                Reversión segura de la versión activa en producción
              </p>
            </div>
          </div>
          <button type="button" className="btn btn-secondary btn-sm" onClick={onClose} disabled={isSubmitting}>
            <X size={16} />
          </button>
        </div>

        <form onSubmit={handleSubmit}>
          <div style={{ padding: '1rem 0' }}>
            <div
              style={{
                background: '#fffbeb',
                border: '1px solid #fef3c7',
                borderRadius: '8px',
                padding: '0.9rem',
                display: 'flex',
                gap: '0.75rem',
                marginBottom: '1rem',
              }}
            >
              <AlertTriangle size={20} color="#d97706" style={{ flexShrink: 0, marginTop: '2px' }} />
              <div style={{ fontSize: '0.875rem', color: '#92400e', lineHeight: 1.5 }}>
                <p style={{ margin: '0 0 0.5rem 0', fontWeight: 600 }}>
                  Estás por restaurar la versión v{version.version_number}.
                </p>
                <p style={{ margin: '0 0 0.5rem 0' }}>
                  Las nuevas conversaciones utilizarán v{version.version_number}
                  {activeVersionNumber ? ` (reemplazando a la actual v${activeVersionNumber})` : ''}.
                </p>
                <p style={{ margin: 0 }}>
                  Las conversaciones actuales continuarán con la versión que tenían asignada sin verse afectadas.
                </p>
              </div>
            </div>

            <div className="form-group" style={{ marginBottom: 0 }}>
              <label className="form-label" style={{ fontSize: '0.85rem' }}>
                Motivo o Notas de la Restauración (Opcional)
              </label>
              <textarea
                className="form-textarea"
                rows={3}
                placeholder="Ej. Incidencia en producción; se revierte a la versión estable v1..."
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                disabled={isSubmitting}
              />
            </div>
          </div>

          <div className="modal-footer" style={{ borderTop: '1px solid var(--border)', paddingTop: '0.75rem' }}>
            <button type="button" className="btn btn-secondary" onClick={onClose} disabled={isSubmitting}>
              Cancelar
            </button>
            <button
              type="submit"
              className="btn btn-danger"
              style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', background: '#dc2626', borderColor: '#b91c1c' }}
              disabled={isSubmitting}
            >
              <RotateCcw size={16} />
              {isSubmitting ? 'Restaurando...' : `Confirmar Rollback a v${version.version_number}`}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
