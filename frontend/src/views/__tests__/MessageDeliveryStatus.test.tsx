import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { MessageDeliveryStatus } from '../../components/inbox/MessageDeliveryStatus';
import type { Message } from '../../types';

describe('MessageDeliveryStatus Component', () => {
  const baseOutboundMessage: Message = {
    id: 'msg-1',
    conversation_id: 'conv-1',
    sender_type: 'agent',
    sender_id: 'user-1',
    direction: 'outbound',
    message_type: 'text',
    content: 'Hola mundo',
    sent_at: '2026-11-06T15:00:00Z',
  };

  it('does not render anything for inbound messages', () => {
    const inboundMessage: Message = {
      ...baseOutboundMessage,
      direction: 'inbound',
      status: 'read',
    };

    const { container } = render(<MessageDeliveryStatus message={inboundMessage} />);
    expect(container.firstChild).toBeNull();
  });

  it('renders pending clock icon for status pending', () => {
    const message: Message = {
      ...baseOutboundMessage,
      status: 'pending',
    };

    render(<MessageDeliveryStatus message={message} />);
    const tick = screen.getByTestId('status-tick-pending');
    expect(tick).toBeInTheDocument();
    expect(tick).toHaveAttribute('title', 'Pendiente de envío');
  });

  it('renders single tick for status sent', () => {
    const message: Message = {
      ...baseOutboundMessage,
      status: 'sent',
    };

    render(<MessageDeliveryStatus message={message} />);
    const tick = screen.getByTestId('status-tick-sent');
    expect(tick).toBeInTheDocument();
    expect(tick).toHaveAttribute('title', 'Enviado');
  });

  it('renders double grey tick for status delivered', () => {
    const message: Message = {
      ...baseOutboundMessage,
      status: 'delivered',
    };

    render(<MessageDeliveryStatus message={message} />);
    const tick = screen.getByTestId('status-tick-delivered');
    expect(tick).toBeInTheDocument();
    expect(tick).toHaveAttribute('title', 'Entregado al dispositivo');
  });

  it('renders double blue tick for status read', () => {
    const message: Message = {
      ...baseOutboundMessage,
      status: 'read',
    };

    render(<MessageDeliveryStatus message={message} />);
    const tick = screen.getByTestId('status-tick-read');
    expect(tick).toBeInTheDocument();
    expect(tick).toHaveAttribute('title', 'Leído');
    expect(tick.className).toContain('text-sky-300');
  });

  it('renders red alert circle with error tooltip for status failed', () => {
    const message: Message = {
      ...baseOutboundMessage,
      status: 'failed',
      metadata: {
        delivery_error: {
          code: 131047,
          message: 'Message failed to send outside 24 hour window',
        },
      },
    };

    render(<MessageDeliveryStatus message={message} />);
    const tick = screen.getByTestId('status-tick-failed');
    expect(tick).toBeInTheDocument();
    expect(tick).toHaveAttribute(
      'title',
      'Error de entrega: Message failed to send outside 24 hour window'
    );
    expect(tick.className).toContain('text-rose-300');
  });

  it('renders double blue tick with tooltip "Leído por el contacto" when metadata delivery_status is read', () => {
    const message: Message = {
      ...baseOutboundMessage,
      metadata: {
        delivery_status: 'read',
      },
    };

    render(<MessageDeliveryStatus message={message} />);
    const tick = screen.getByTestId('status-tick-read');
    expect(tick).toBeInTheDocument();
    expect(tick).toHaveAttribute('title', 'Leído por el contacto');
    expect(tick.className).toContain('text-sky-300');
  });

  it('renders red alert circle with string error message when delivery_error is string', () => {
    const message: Message = {
      ...baseOutboundMessage,
      metadata: {
        delivery_status: 'failed',
        delivery_error: 'Error al entregar en WhatsApp',
      },
    };

    render(<MessageDeliveryStatus message={message} />);
    const tick = screen.getByTestId('status-tick-failed');
    expect(tick).toBeInTheDocument();
    expect(tick).toHaveAttribute('title', 'Error al entregar en WhatsApp');
    expect(tick.className).toContain('text-rose-300');
  });
});
