import React from 'react';
import { Check, CheckCheck, Clock, AlertCircle } from 'lucide-react';
import type { Message } from '../../types';

interface MessageDeliveryStatusProps {
  message: Message;
  className?: string;
}

export const MessageDeliveryStatus: React.FC<MessageDeliveryStatusProps> = ({ message, className = '' }) => {
  // Only outbound messages display delivery/read status ticks
  if (message.direction !== 'outbound') {
    return null;
  }

  const deliveryStatus = message.metadata?.delivery_status;
  const status = (typeof deliveryStatus === 'string' ? deliveryStatus : '') || message.status || 'sent';

  if (status === 'pending') {
    return (
      <span
        data-testid="status-tick-pending"
        title="Pendiente de envío"
        className={`inline-flex items-center text-white/70 dark:text-slate-400 ${className}`}
      >
        <Clock size={13} className="animate-pulse" />
      </span>
    );
  }

  if (status === 'sent') {
    return (
      <span
        data-testid="status-tick-sent"
        title="Enviado"
        className={`inline-flex items-center text-white/80 dark:text-slate-400 ${className}`}
      >
        <Check size={14} />
      </span>
    );
  }

  if (status === 'delivered') {
    return (
      <span
        data-testid="status-tick-delivered"
        title="Entregado al dispositivo"
        className={`inline-flex items-center text-white/90 dark:text-slate-300 ${className}`}
      >
        <CheckCheck size={14} />
      </span>
    );
  }

  if (status === 'read') {
    const title = message.metadata?.delivery_status === 'read' ? 'Leído por el contacto' : 'Leído';
    return (
      <span
        data-testid="status-tick-read"
        title={title}
        className={`inline-flex items-center text-sky-300 dark:text-sky-400 font-semibold ${className}`}
      >
        <CheckCheck size={14} />
      </span>
    );
  }

  if (status === 'failed') {
    const rawError = message.metadata?.delivery_error;
    let errorDetail = 'Error al entregar en WhatsApp';
    let isStructured = false;

    if (typeof rawError === 'string') {
      errorDetail = rawError;
    } else if (rawError && typeof rawError === 'object') {
      isStructured = true;
      errorDetail = rawError.message || rawError.title || 'Error al entregar en WhatsApp';
    }

    const titleText = isStructured
      ? `Error de entrega: ${errorDetail}`
      : (errorDetail.startsWith('Error') ? errorDetail : `Error de entrega: ${errorDetail}`);

    return (
      <span
        data-testid="status-tick-failed"
        title={titleText}
        className={`inline-flex items-center text-rose-300 dark:text-rose-400 ${className}`}
      >
        <AlertCircle size={14} />
      </span>
    );
  }

  return null;
};
