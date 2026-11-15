import type { WebchatMessage } from './types';

export function sortAndDeduplicateWebchatMessages(msgs: WebchatMessage[]): WebchatMessage[] {
  // Map confirmed client_message_id to their server IDs
  const confirmedClientIds = new Set<string>();
  for (const m of msgs) {
    const cid = m.client_message_id || (m.metadata as any)?.client_message_id;
    if (cid && cid !== m.id) {
      confirmedClientIds.add(cid);
    }
  }

  // Filter out temporary optimistic placeholders that have been confirmed
  const withoutStaleOptimistic = msgs.filter((m) => !confirmedClientIds.has(m.id));

  // Deduplicate by unique message id
  const byId = new Map<string, WebchatMessage>();
  for (const m of withoutStaleOptimistic) {
    byId.set(m.id, m);
  }

  const unique = Array.from(byId.values());

  // Strict deterministic ordering:
  // 1. Timestamp in ms
  // 2. Inbound messages (contact) strictly BEFORE outbound messages (bot/agent) if timestamps match
  // 3. Fallback to localeCompare on UUIDv7
  return unique.sort((a, b) => {
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
