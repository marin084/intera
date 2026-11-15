import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { WhatsAppTemplateModal } from '../WhatsAppTemplateModal';
import { api } from '../../../services/api';

vi.mock('../../../services/api', () => ({
  api: {
    getWhatsAppTemplates: vi.fn(),
    sendWhatsAppTemplate: vi.fn(),
  },
}));

describe('WhatsAppTemplateModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.sendWhatsAppTemplate).mockResolvedValue({ data: {} } as any);
  });

  it('sends the exact template language and all body parameters', async () => {
    vi.mocked(api.getWhatsAppTemplates).mockResolvedValue({ data: [
      {
        name: 'order_update', status: 'APPROVED', category: 'UTILITY', language: 'es',
        text: 'Hola {{1}}', components: [{ type: 'BODY', text: 'Hola {{1}}' }],
      },
      {
        name: 'order_update', status: 'APPROVED', category: 'UTILITY', language: 'en_US',
        text: 'Hello {{1}}, order {{2}}', components: [{ type: 'BODY', text: 'Hello {{1}}, order {{2}}' }],
      },
    ] } as any);

    render(<WhatsAppTemplateModal isOpen onClose={vi.fn()} channelId="channel-1" conversationId="conversation-1" contactName="Ada" />);
    await screen.findByText('order_update');
    fireEvent.change(screen.getByLabelText('Plantilla preaprobada seleccionada:'), { target: { value: 'order_update:en_US' } });

    fireEvent.change(screen.getByLabelText(/Parámetro \{\{1\}\}/), { target: { value: 'Ada' } });
    fireEvent.change(screen.getByLabelText(/Parámetro \{\{2\}\}/), { target: { value: 'A-42' } });
    fireEvent.click(screen.getByTestId('send-whatsapp-template-btn'));

    await waitFor(() => expect(api.sendWhatsAppTemplate).toHaveBeenCalledWith('conversation-1', {
      name: 'order_update',
      language: { code: 'en_US' },
      components: [{
        type: 'body',
        parameters: [
          { type: 'text', text: 'Ada' },
          { type: 'text', text: 'A-42' },
        ],
      }],
    }));
  });

  it('omits body parameters when the approved template has no variables', async () => {
    vi.mocked(api.getWhatsAppTemplates).mockResolvedValue({ data: [
      {
        name: 'thanks', status: 'APPROVED', category: 'UTILITY', language: 'es',
        text: 'Gracias por contactarnos.', components: [{ type: 'BODY', text: 'Gracias por contactarnos.' }],
      },
    ] } as any);

    render(<WhatsAppTemplateModal isOpen onClose={vi.fn()} channelId="channel-1" conversationId="conversation-1" />);
    await screen.findByText('thanks');
    fireEvent.click(screen.getByTestId('send-whatsapp-template-btn'));

    await waitFor(() => expect(api.sendWhatsAppTemplate).toHaveBeenCalledWith('conversation-1', {
      name: 'thanks',
      language: { code: 'es' },
    }));
  });

  it('resets contact-specific template parameters when the active conversation changes', async () => {
    vi.mocked(api.getWhatsAppTemplates).mockResolvedValue({ data: [
      {
        name: 'hello', status: 'APPROVED', category: 'UTILITY', language: 'es',
        text: 'Hola {{1}}', components: [{ type: 'BODY', text: 'Hola {{1}}' }],
      },
    ] } as any);

    const props = { isOpen: true, onClose: vi.fn(), channelId: 'channel-1', conversationId: 'conversation-1', contactName: 'Ada' };
    const { rerender } = render(<WhatsAppTemplateModal {...props} />);
    const parameterInput = await screen.findByLabelText(/Parámetro \{\{1\}\}/);
    expect(parameterInput).toHaveValue('Ada');

    fireEvent.change(parameterInput, { target: { value: 'Nombre editado' } });
    expect(parameterInput).toHaveValue('Nombre editado');

    rerender(<WhatsAppTemplateModal {...props} conversationId="conversation-2" contactName="Bea" />);
    expect(await screen.findByLabelText(/Parámetro \{\{1\}\}/)).toHaveValue('Bea');
  });
});
