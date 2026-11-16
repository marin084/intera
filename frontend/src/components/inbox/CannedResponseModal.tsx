import React, { useState } from 'react';
import type { CannedResponse } from '../../types';
import { api } from '../../services/api';
import { X, Loader2, Zap, AlertCircle } from 'lucide-react';
import { useToast } from '../../context/ToastContext';

interface CannedResponseModalProps {
  isOpen: boolean;
  cannedResponse?: CannedResponse | null;
  onClose: () => void;
  onSaved: (savedResponse: CannedResponse) => void;
}

interface CannedResponseFormProps {
  cannedResponse?: CannedResponse | null;
  onClose: () => void;
  onSaved: (savedResponse: CannedResponse) => void;
}

const CannedResponseForm: React.FC<CannedResponseFormProps> = ({
  cannedResponse,
  onClose,
  onSaved,
}) => {
  const { showToast } = useToast();
  const [title, setTitle] = useState(cannedResponse?.title || '');
  const [shortcut, setShortcut] = useState(cannedResponse?.shortcut || '');
  const [category, setCategory] = useState(cannedResponse?.category || '');
  const [content, setContent] = useState(cannedResponse?.content || '');
  const [isActive, setIsActive] = useState(cannedResponse?.is_active ?? true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!title.trim()) {
      setError('El título de la respuesta es obligatorio.');
      return;
    }
    if (!content.trim()) {
      setError('El contenido del mensaje no puede estar vacío.');
      return;
    }

    setSaving(true);
    setError(null);

    // Normalize shortcut: remove leading slash if present, trim
    const cleanShortcut = shortcut ? shortcut.trim().replace(/^\/+/, '') : null;

    try {
      if (cannedResponse) {
        const res = await api.updateCannedResponse(cannedResponse.id, {
          title: title.trim(),
          shortcut: cleanShortcut,
          category: category.trim() || null,
          content: content.trim(),
          is_active: isActive,
        });
        onSaved(res.data);
      } else {
        const res = await api.createCannedResponse({
          title: title.trim(),
          shortcut: cleanShortcut,
          category: category.trim() || null,
          content: content.trim(),
          is_active: isActive,
        });
        onSaved(res.data);
      }
      onClose();
    } catch (err: any) {
      showToast(err.message || 'Error al guardar la respuesta rápida.', 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div
      data-testid="canned-response-modal"
      className="modal-content canned-response-side-panel"
      style={{
        background: 'white',
        borderRadius: '12px',
        width: '100%',
        boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)',
        overflow: 'hidden',
        display: 'flex',
        flexDirection: 'column',
      }}
      onClick={(e) => e.stopPropagation()}
    >
      {/* Header */}
      <div className="flow-side-panel-header">
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <Zap size={18} color="#5d8f82" />
          <h3 id="canned-response-panel-title" style={{ fontSize: '1rem', fontWeight: 700, margin: 0, color: '#263c38' }}>
            {cannedResponse ? 'Editar Respuesta Rápida' : 'Nueva Respuesta Rápida'}
          </h3>
        </div>
        <button
          type="button"
          data-testid="close-canned-modal-btn"
          onClick={onClose}
          className="btn btn-sm btn-ghost"
          style={{ padding: '4px', color: '#61726d' }}
          title="Cerrar ventana"
        >
          <X size={18} />
        </button>
      </div>

      {/* Form Body */}
      <form id="canned-response-form" onSubmit={handleSubmit} className="flow-side-panel-form">
        <div className="flow-side-panel-body" style={{ display: 'flex', flexDirection: 'column', gap: '0.85rem' }}>
        {error && (
          <div
            data-testid="canned-modal-error"
            style={{
              padding: '0.6rem 0.85rem',
              background: '#fef2f2',
              color: '#dc2626',
              border: '1px solid #fecaca',
              borderRadius: '6px',
              fontSize: '0.8rem',
              display: 'flex',
              alignItems: 'center',
              gap: '0.4rem',
            }}
          >
            <AlertCircle size={15} />
            <span>{error}</span>
          </div>
        )}

        <div>
          <label style={{ display: 'block', fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.3rem', color: '#56736b' }}>
            Título descriptivo *
          </label>
          <input
            type="text"
            data-testid="canned-title-input"
            className="form-input"
            placeholder="Ej. Cuentas Banco de Costa Rica"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            maxLength={120}
            required
            autoFocus
          />
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
          <div>
            <label style={{ display: 'block', fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.3rem', color: '#56736b' }}>
              Atajo con barra (Slash)
            </label>
            <div style={{ position: 'relative', display: 'flex', alignItems: 'center' }}>
              <span
                style={{
                  position: 'absolute',
                  left: '10px',
                  color: '#5d8f82',
                  fontFamily: 'monospace',
                  fontWeight: 700,
                  fontSize: '0.9rem',
                  pointerEvents: 'none',
                }}
              >
                /
              </span>
              <input
                type="text"
                data-testid="canned-shortcut-input"
                className="form-input"
                style={{ paddingLeft: '1.5rem', fontFamily: 'monospace' }}
                placeholder="bcr"
                value={shortcut.replace(/^\/+/, '')}
                onChange={(e) => setShortcut(e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, ''))}
                maxLength={50}
              />
            </div>
            <span style={{ fontSize: '0.7rem', color: 'var(--text-muted)' }}>
              Solo letras, números y guiones.
            </span>
          </div>

          <div>
            <label style={{ display: 'block', fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.3rem', color: '#56736b' }}>
              Categoría (Opcional)
            </label>
            <input
              type="text"
              data-testid="canned-category-input"
              className="form-input"
              placeholder="Ej. Finanzas, Atención"
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              maxLength={60}
            />
          </div>
        </div>

        <div>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.3rem' }}>
            <label style={{ fontSize: '0.8rem', fontWeight: 600, color: '#56736b', margin: 0 }}>
              Contenido del mensaje *
            </label>
            <span style={{ fontSize: '0.72rem', color: content.length > 1800 ? '#dc2626' : 'var(--text-muted)' }}>
              {content.length}/2000
            </span>
          </div>
          <textarea
            data-testid="canned-content-textarea"
            className="form-input"
            rows={5}
            placeholder="Escribe el cuerpo completo del mensaje predeterminado..."
            value={content}
            onChange={(e) => setContent(e.target.value)}
            maxLength={2000}
            required
            style={{ resize: 'vertical' }}
          />
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginTop: '0.2rem' }}>
          <input
            type="checkbox"
            id="canned-is-active-check"
            data-testid="canned-is-active-checkbox"
            checked={isActive}
            onChange={(e) => setIsActive(e.target.checked)}
            style={{ cursor: 'pointer', width: '16px', height: '16px' }}
          />
          <label htmlFor="canned-is-active-check" style={{ fontSize: '0.8rem', fontWeight: 500, color: '#56736b', cursor: 'pointer' }}>
            Plantilla activa (visible para los agentes en el chat)
          </label>
        </div>

        </div>
        </form>

        {/* Actions */}
        <div className="flow-side-panel-footer">
          <button
            type="button"
            data-testid="cancel-canned-modal-btn"
            className="btn btn-secondary"
            onClick={onClose}
            disabled={saving}
          >
            Cancelar
          </button>
          <button
            type="submit"
            form="canned-response-form"
            data-testid="save-canned-modal-btn"
            className="btn btn-primary"
            disabled={saving || !title.trim() || !content.trim()}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem' }}
          >
            {saving ? <Loader2 size={15} className="animate-spin" /> : null}
            {saving ? 'Guardando...' : cannedResponse ? 'Guardar Cambios' : 'Crear Respuesta'}
          </button>
        </div>
    </div>
  );
};

export const CannedResponseModal: React.FC<CannedResponseModalProps> = ({
  isOpen,
  cannedResponse,
  onClose,
  onSaved,
}) => {
  if (!isOpen) return null;

  return (
    <div
      data-testid="canned-response-modal-overlay"
      className="modal-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="canned-response-panel-title"
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(15, 23, 42, 0.55)',
        backdropFilter: 'blur(3px)',
        zIndex: 1100,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '1rem',
      }}
      onClick={onClose}
    >
      <CannedResponseForm
        key={cannedResponse?.id || 'new'}
        cannedResponse={cannedResponse}
        onClose={onClose}
        onSaved={onSaved}
      />
    </div>
  );
};
