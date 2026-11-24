import React, { useEffect, useRef } from 'react';
import { Loader2, X } from 'lucide-react';

export interface ConfirmModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  description: string;
  confirmText?: string;
  cancelText?: string;
  confirmVariant?: 'primary' | 'danger';
  icon?: React.ReactNode;
  iconBgColor?: string;
  iconColor?: string;
  isLoading?: boolean;
  testId?: string;
  confirmTestId?: string;
  cancelTestId?: string;
  requiredText?: string;
  requiredTextLabel?: string;
}

export const ConfirmModal: React.FC<ConfirmModalProps> = ({
  isOpen,
  onClose,
  onConfirm,
  title,
  description,
  confirmText = 'Confirmar',
  cancelText = 'Cancelar',
  confirmVariant = 'primary',
  icon,
  iconBgColor,
  iconColor,
  isLoading = false,
  testId = 'confirm-modal',
  confirmTestId = 'confirm-modal-btn',
  cancelTestId = 'cancel-modal-btn',
  requiredText,
  requiredTextLabel = 'elemento',
}) => {
  const [confirmationText, setConfirmationText] = React.useState('');
  const modalRef = useRef<HTMLDivElement>(null);
  const cancelButtonRef = useRef<HTMLButtonElement>(null);
  const confirmationInputRef = useRef<HTMLInputElement>(null);
  const previouslyFocusedRef = useRef<HTMLElement | null>(null);

  useEffect(() => {
    if (isOpen) setConfirmationText('');
  }, [isOpen, requiredText]);

  // Keep keyboard users inside the open dialog and return focus to the control
  // that opened it when the dialog closes.
  useEffect(() => {
    if (!isOpen) {
      if (previouslyFocusedRef.current && document.contains(previouslyFocusedRef.current)) {
        previouslyFocusedRef.current.focus();
      }
      previouslyFocusedRef.current = null;
      return;
    }

    previouslyFocusedRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    if (requiredText !== undefined) {
      confirmationInputRef.current?.focus();
    } else {
      cancelButtonRef.current?.focus();
    }
  }, [isOpen, requiredText]);

  useEffect(() => {
    if (!isOpen) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !isLoading) {
        onClose();
        return;
      }

      if (e.key !== 'Tab' || !modalRef.current) return;

      const focusableElements = Array.from(
        modalRef.current.querySelectorAll<HTMLElement>(
          'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
        )
      );
      if (!focusableElements.length) return;

      const firstElement = focusableElements[0];
      const lastElement = focusableElements[focusableElements.length - 1];
      if (e.shiftKey && document.activeElement === firstElement) {
        e.preventDefault();
        lastElement.focus();
      } else if (!e.shiftKey && document.activeElement === lastElement) {
        e.preventDefault();
        firstElement.focus();
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen, isLoading, onClose]);

  if (!isOpen) return null;

  const isDanger = confirmVariant === 'danger';
  const defaultBg = isDanger ? 'bg-rose-50 dark:bg-rose-950/50' : 'bg-blue-50 dark:bg-blue-950/50';
  const defaultText = isDanger ? 'text-rose-600 dark:text-rose-400' : 'text-blue-600 dark:text-blue-400';

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="confirm-modal-title"
      aria-describedby={`${testId}-description`}
      aria-busy={isLoading}
      data-testid={testId}
      className="modal-overlay fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 animate-in fade-in duration-150"
      style={{
        position: 'fixed',
        inset: 0,
        backgroundColor: 'rgba(15, 23, 42, 0.5)',
        backdropFilter: 'blur(4px)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 9999,
        padding: '1rem',
      }}
      onClick={(e) => {
        if (e.target === e.currentTarget && !isLoading) {
          onClose();
        }
      }}
    >
      <div
        ref={modalRef}
        className="modal-content confirmation-side-panel bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 animate-in duration-150"
        style={{
          background: 'white',
          boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
          border: '1px solid var(--border, #d9e3de)',
        }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flow-side-panel-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            {icon && (
              <div
                className={`${defaultBg} ${defaultText}`}
                style={{
                  width: '40px',
                  height: '40px',
                  flex: '0 0 auto',
                  borderRadius: '9999px',
                  background: iconBgColor || (isDanger ? '#fef2f2' : '#eaf1ee'),
                  color: iconColor || (isDanger ? '#dc2626' : '#5d8f82'),
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                {icon}
              </div>
            )}
            <h3
              id="confirm-modal-title"
              style={{ fontSize: '1.15rem', fontWeight: 700, color: '#263c38', margin: 0 }}
            >
              {title}
            </h3>
          </div>
          <button type="button" className="btn btn-secondary btn-sm" onClick={onClose} disabled={isLoading} aria-label="Cerrar ventana de confirmación">
            <X size={16} />
          </button>
        </div>

        <div className="flow-side-panel-body">
          <p id={`${testId}-description`} style={{ fontSize: '0.875rem', color: '#61726d', lineHeight: 1.5, margin: 0 }}>
            {description}
          </p>
          {requiredText !== undefined && (
            <div style={{ marginTop: '1.25rem' }}>
              <label htmlFor={`${testId}-confirmation-input`} style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, color: '#35564f', marginBottom: '0.4rem' }}>
                Para confirmar, escribe el nombre exacto del {requiredTextLabel}:
                <strong style={{ display: 'block', marginTop: '0.25rem', color: '#263c38' }}>{requiredText}</strong>
              </label>
              <input
                ref={confirmationInputRef}
                id={`${testId}-confirmation-input`}
                type="text"
                className="form-input"
                autoComplete="off"
                spellCheck={false}
                value={confirmationText}
                onChange={(event) => setConfirmationText(event.target.value)}
                disabled={isLoading}
                placeholder={requiredText}
                data-testid={`${testId}-confirmation-input`}
              />
            </div>
          )}
        </div>

        <div className="flow-side-panel-footer" style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: '0.75rem' }}>
          <button
            type="button"
            ref={cancelButtonRef}
            data-testid={cancelTestId}
            // Cancellation must remain available while the destructive action
            // is being prepared. The exact-name requirement only guards the
            // confirm action, never the escape hatch.
            disabled={isLoading}
            onClick={onClose}
            className="border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 font-medium px-4 py-2.5 rounded-xl transition-colors"
            style={{
              padding: '0.55rem 1rem',
              borderRadius: '10px',
              fontWeight: 500,
              fontSize: '0.875rem',
              background: '#ffffff',
              border: '1px solid #d9e3de',
              color: '#56736b',
              cursor: isLoading ? 'not-allowed' : 'pointer',
            }}
          >
            {cancelText}
          </button>
          <button
            type="button"
            data-testid={confirmTestId}
            disabled={isLoading || (requiredText !== undefined && confirmationText.trim() !== requiredText.trim())}
            aria-busy={isLoading}
            onClick={onConfirm}
            className={`${
              isDanger
                ? 'bg-rose-600 hover:bg-rose-700 text-white'
                : 'bg-blue-600 hover:bg-blue-700 text-white'
            } font-medium px-5 py-2.5 rounded-xl shadow-sm transition-colors flex items-center gap-2`}
            style={{
              padding: '0.55rem 1.25rem',
              borderRadius: '10px',
              fontWeight: 600,
              fontSize: '0.875rem',
              background: isDanger ? '#dc2626' : '#5d8f82',
              border: isDanger ? '1px solid #dc2626' : '1px solid #5d8f82',
              color: '#ffffff',
              cursor: isLoading ? 'not-allowed' : 'pointer',
              display: 'inline-flex',
              alignItems: 'center',
              gap: '0.4rem',
            }}
          >
            {isLoading && <Loader2 size={16} className="animate-spin" />}
            <span>{confirmText}</span>
          </button>
        </div>
      </div>
    </div>
  );
};
