import React, { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { AlertCircle, CheckCircle2, Info, X } from 'lucide-react';

export type ToastType = 'success' | 'error' | 'info';

export interface ToastAction {
  label: string;
  onClick: () => void;
}

interface ToastItem {
  id: number;
  message: string;
  type: ToastType;
  action?: ToastAction;
}

interface ToastContextValue {
  showToast: (message: string, type?: ToastType, action?: ToastAction) => void;
}

const ToastContext = createContext<ToastContextValue>({ showToast: () => undefined });

const ToastCard: React.FC<{ toast: ToastItem; onDismiss: (id: number) => void }> = ({ toast, onDismiss }) => {
  const timeoutRef = useRef<number | undefined>(undefined);

  useEffect(() => {
    timeoutRef.current = window.setTimeout(() => onDismiss(toast.id), toast.action ? 8000 : toast.type === 'error' ? 7000 : 5000);
    return () => window.clearTimeout(timeoutRef.current);
  }, [onDismiss, toast.action, toast.id, toast.type]);

  const Icon = toast.type === 'success' ? CheckCircle2 : toast.type === 'error' ? AlertCircle : Info;

  return (
    <div className={`app-toast app-toast--${toast.type}`} role={toast.type === 'error' ? 'alert' : 'status'}>
      <Icon className="app-toast__icon" size={19} aria-hidden="true" />
      <span className="app-toast__message">{toast.message}</span>
      {toast.action && (
        <button
          type="button"
          className="app-toast__action"
          onClick={() => {
            toast.action?.onClick();
            onDismiss(toast.id);
          }}
        >
          {toast.action.label}
        </button>
      )}
      <button type="button" className="app-toast__close" onClick={() => onDismiss(toast.id)} aria-label="Cerrar notificación">
        <X size={17} aria-hidden="true" />
      </button>
    </div>
  );
};

export const ToastProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [toasts, setToasts] = useState<ToastItem[]>([]);
  const nextId = useRef(0);

  const dismissToast = useCallback((id: number) => {
    setToasts((current) => current.filter((toast) => toast.id !== id));
  }, []);

  const showToast = useCallback((message: string, type: ToastType = 'success', action?: ToastAction) => {
    const id = ++nextId.current;
    setToasts((current) => [...current.slice(-2), { id, message, type, action }]);
  }, []);

  return (
    <ToastContext.Provider value={{ showToast }}>
      {children}
      <div className="app-toast-viewport" aria-label="Notificaciones">
        {toasts.map((toast) => <ToastCard key={toast.id} toast={toast} onDismiss={dismissToast} />)}
      </div>
    </ToastContext.Provider>
  );
};

export const useToast = (): ToastContextValue => useContext(ToastContext);
