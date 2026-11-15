import React from 'react';
import { AlertTriangle, CheckCircle, ChevronRight, X } from 'lucide-react';
import type { FlowValidationError } from '../../types';

interface ValidationPanelProps {
  errors: FlowValidationError[];
  isValid: boolean | null;
  onSelectNode: (nodeKey: string) => void;
  onClose: () => void;
}

export const ValidationPanel: React.FC<ValidationPanelProps> = ({
  errors,
  isValid,
  onSelectNode,
  onClose,
}) => {
  if (isValid === null) {
    return null;
  }

  if (isValid && errors.length === 0) {
    return (
      <div className="validation-drawer" style={{ borderColor: '#a9c8bb' }}>
        <div className="validation-drawer-header" style={{ background: '#eaf1ee', borderBottomColor: '#eaf1ee' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', color: '#263c38', fontWeight: 600, fontSize: '0.9rem' }}>
            <CheckCircle size={18} color="#5d8f82" />
            <span>El flujo es válido y puede publicarse sin errores.</span>
          </div>
          <button className="btn btn-secondary btn-sm" style={{ padding: '0.2rem 0.4rem' }} onClick={onClose}>
            <X size={14} />
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="validation-drawer">
      <div className="validation-drawer-header">
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <AlertTriangle size={18} color="#dc2626" />
          <span style={{ fontWeight: 700, fontSize: '0.9rem', color: '#991b1b' }}>
            {errors.length} {errors.length === 1 ? 'problema encontrado' : 'problemas encontrados'}
          </span>
        </div>
        <button className="btn btn-secondary btn-sm" style={{ padding: '0.2rem 0.4rem' }} onClick={onClose}>
          <X size={14} />
        </button>
      </div>

      <div style={{ maxHeight: '220px', overflowY: 'auto' }}>
        {errors.map((err, idx) => (
          <div
            key={idx}
            className="validation-error-item"
            onClick={() => {
              if (err.node_key) {
                onSelectNode(err.node_key);
              }
            }}
          >
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: '0.5rem' }}>
              <span style={{ color: '#dc2626', fontWeight: 700, marginTop: '2px' }}>•</span>
              <div>
                <div style={{ fontSize: '0.8rem', color: '#35564f', fontWeight: 500 }}>
                  {err.message}
                </div>
                {err.node_key && (
                  <div style={{ fontSize: '0.7rem', color: '#61726d', marginTop: '0.15rem' }}>
                    Nodo: <code style={{ background: '#eaf1ee', padding: '0.1rem 0.3rem', borderRadius: '3px' }}>{err.node_key}</code>
                  </div>
                )}
              </div>
            </div>

            {err.node_key && (
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.2rem', color: '#5d8f82', fontSize: '0.75rem', fontWeight: 600, flexShrink: 0 }}>
                <span>Localizar</span>
                <ChevronRight size={14} />
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
};
