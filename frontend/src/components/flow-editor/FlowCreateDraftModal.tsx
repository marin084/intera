import React from 'react';
import { CopyPlus, GitBranch, X, Loader2 } from 'lucide-react';

export interface FlowCreateDraftModalProps {
  isOpen: boolean;
  currentVersionNumber: number | string;
  isCreating: boolean;
  onConfirm: () => void | Promise<void>;
  onClose: () => void;
}

export const FlowCreateDraftModal: React.FC<FlowCreateDraftModalProps> = ({
  isOpen,
  currentVersionNumber,
  isCreating,
  onConfirm,
  onClose,
}) => {
  if (!isOpen) return null;

  return (
    <div className="modal-overlay" style={{ zIndex: 1050 }}>
      <div className="modal-content flow-side-panel">
        <div className="modal-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            <div style={{ background: '#eaf1ee', padding: '0.45rem', borderRadius: '8px', color: '#5d8f82' }}>
              <CopyPlus size={20} />
            </div>
            <div>
              <h3 style={{ margin: 0, fontSize: '1.15rem', color: '#35564f', fontWeight: 700 }}>
                Crear nueva versión borrador
              </h3>
              <p style={{ margin: 0, fontSize: '0.775rem', color: '#61726d' }}>
                Clonación segura del flujo para edición
              </p>
            </div>
          </div>
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={onClose}
            disabled={isCreating}
            aria-label="Cerrar modal"
          >
            <X size={16} />
          </button>
        </div>

        <div className="flow-side-panel-body" style={{ display: 'flex', flexDirection: 'column', gap: '0.9rem' }}>
          <p style={{ fontSize: '0.875rem', color: '#56736b', lineHeight: 1.5, margin: 0 }}>
            Vas a generar una nueva versión borrador basada en la <strong>Versión {currentVersionNumber}</strong>.
          </p>

          <div
            style={{
              background: '#f4f7f5',
              border: '1px solid #d9e3de',
              borderRadius: '8px',
              padding: '0.85rem',
              display: 'flex',
              alignItems: 'flex-start',
              gap: '0.6rem',
              fontSize: '0.8rem',
              color: '#61726d',
              lineHeight: 1.45,
            }}
          >
            <GitBranch size={16} style={{ color: '#5d8f82', flexShrink: 0, marginTop: '2px' }} />
            <span>
              Tus canales continuarán atendiendo a los usuarios con la versión publicada actual hasta que decidas publicar este nuevo borrador.
            </span>
          </div>
        </div>

        <div className="flow-side-panel-footer">
          <button
            type="button"
            className="btn btn-secondary"
            onClick={onClose}
            disabled={isCreating}
          >
            Cancelar
          </button>
          <button
            type="button"
            className="btn btn-primary"
            onClick={onConfirm}
            disabled={isCreating}
            style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}
          >
            {isCreating ? (
              <>
                <Loader2 size={14} className="animate-spin" />
                <span>Creando borrador...</span>
              </>
            ) : (
              <span>Crear borrador</span>
            )}
          </button>
        </div>
      </div>
    </div>
  );
};
