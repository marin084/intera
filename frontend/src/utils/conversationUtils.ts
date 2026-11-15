import type { Message } from '../types';

export function sortAndDeduplicateInboxMessages(msgs: Message[]): Message[] {
  const byId = new Map<string, Message>();
  for (const m of msgs) {
    byId.set(m.id, m);
  }

  return Array.from(byId.values()).sort((a, b) => {
    const timeA = new Date(a.sent_at || a.created_at || 0).getTime();
    const timeB = new Date(b.sent_at || b.created_at || 0).getTime();

    if (timeA !== timeB) {
      return timeA - timeB;
    }

    const isInboundA = a.direction === 'inbound' || a.sender_type === 'contact';
    const isInboundB = b.direction === 'inbound' || b.sender_type === 'contact';

    if (isInboundA && !isInboundB) return -1;
    if (!isInboundA && isInboundB) return 1;

    return (a.id || '').localeCompare(b.id || '');
  });
}
