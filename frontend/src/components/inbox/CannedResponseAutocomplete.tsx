import React, { useEffect, useRef } from 'react';
import type { CannedResponse } from '../../types';
import { Zap, Command } from 'lucide-react';

interface CannedResponseAutocompleteProps {
  isOpen: boolean;
  filterText: string;
  responses: CannedResponse[];
  selectedIndex: number;
  onSelect: (response: CannedResponse) => void;
  onClose: () => void;
}

export const CannedResponseAutocomplete: React.FC<CannedResponseAutocompleteProps> = ({
  isOpen,
  filterText,
  responses,
  selectedIndex,
  onSelect,
  onClose,
}) => {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const listRef = useRef<HTMLDivElement | null>(null);

  // Filter responses by shortcut, title, or content (active responses only)
  const cleanFilter = filterText.toLowerCase().trim();
  const activeResponses = responses.filter((r) => r.is_active !== false);
  const filtered = activeResponses.filter((r) => {
    if (!cleanFilter) return true;
    const shortcutMatch = r.shortcut ? r.shortcut.toLowerCase().includes(cleanFilter) : false;
    const titleMatch = r.title ? r.title.toLowerCase().includes(cleanFilter) : false;
    const contentMatch = r.content ? r.content.toLowerCase().includes(cleanFilter) : false;
    return shortcutMatch || titleMatch || contentMatch;
  });

  // Keep selected item visible in scroll view
  useEffect(() => {
    if (listRef.current && filtered.length > 0) {
      const selectedEl = listRef.current.children[selectedIndex] as HTMLElement;
      if (selectedEl && typeof selectedEl.scrollIntoView === 'function') {
        selectedEl.scrollIntoView({ block: 'nearest' });
      }
    }
  }, [selectedIndex, filtered.length]);

  // Click outside listener to close popover
  useEffect(() => {
    if (!isOpen) return;

    const handleClickOutside = (e: MouseEvent) => {
      const target = e.target as HTMLElement;
      if (
        containerRef.current &&
        !containerRef.current.contains(target) &&
        !target.closest('[data-testid="canned-responses-trigger-btn"]') &&
        !target.closest('[data-testid="agent-reply-input"]')
      ) {
        onClose();
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  return (
    <div
      ref={containerRef}
      data-testid="canned-responses-autocomplete-popover"
      style={{
        position: 'absolute',
        bottom: '100%',
        left: '0.75rem',
        right: '0.75rem',
        marginBottom: '8px',
        maxHeight: '280px',
        background: '#ffffff',
        borderRadius: '8px',
        border: '1px solid var(--border)',
        boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1)',
        zIndex: 50,
        display: 'flex',
        flexDirection: 'column',
        overflow: 'hidden',
      }}
    >
      {/* Header bar */}
      <div
        style={{
          padding: '0.45rem 0.75rem',
          background: '#f4f7f5',
          borderBottom: '1px solid var(--border)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          fontSize: '0.72rem',
          color: 'var(--text-muted)',
        }}
      >
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', fontWeight: 600, color: 'var(--text-main)' }}>
          <Zap size={13} color="#5d8f82" /> Respuestas Rápidas ({filtered.length})
        </span>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.68rem' }}>
          <span><kbd style={{ padding: '1px 4px', background: '#d9e3de', borderRadius: '3px', fontSize: '0.65rem' }}>↑</kbd> <kbd style={{ padding: '1px 4px', background: '#d9e3de', borderRadius: '3px', fontSize: '0.65rem' }}>↓</kbd> navegar</span>
          <span><kbd style={{ padding: '1px 4px', background: '#d9e3de', borderRadius: '3px', fontSize: '0.65rem' }}>Enter</kbd> usar</span>
          <span><kbd style={{ padding: '1px 4px', background: '#d9e3de', borderRadius: '3px', fontSize: '0.65rem' }}>Esc</kbd> cerrar</span>
        </span>
      </div>

      {/* List items */}
      <div
        ref={listRef}
        style={{
          flex: 1,
          overflowY: 'auto',
          scrollBehavior: 'smooth',
          padding: '0.25rem 0',
          maxHeight: '225px',
        }}
      >
        {filtered.length === 0 ? (
          <div
            data-testid="canned-autocomplete-empty"
            style={{
              padding: '1.25rem 1rem',
              textAlign: 'center',
              color: 'var(--text-muted)',
              fontSize: '0.8rem',
            }}
          >
            No se encontraron respuestas con <code style={{ color: '#5d8f82' }}>/{cleanFilter}</code>
          </div>
        ) : (
          filtered.map((item, index) => {
            const isSelected = index === selectedIndex;
            return (
              <div
                key={item.id}
                data-testid={`canned-item-${item.shortcut || item.id}`}
                className="canned-autocomplete-item"
                onClick={() => onSelect(item)}
                style={{
                  padding: '0.55rem 0.75rem',
                  cursor: 'pointer',
                  background: isSelected ? '#eaf1ee' : 'transparent',
                  borderLeft: isSelected ? '3px solid #5d8f82' : '3px solid transparent',
                  display: 'flex',
                  alignItems: 'flex-start',
                  gap: '0.65rem',
                  transition: 'background 0.15s ease',
                }}
              >
                {/* Shortcut Pill */}
                <div style={{ flexShrink: 0, marginTop: '2px' }}>
                  <span
                    style={{
                      fontFamily: 'monospace',
                      fontWeight: 700,
                      fontSize: '0.78rem',
                      background: isSelected ? '#5d8f82' : '#eaf1ee',
                      color: isSelected ? '#ffffff' : '#456c61',
                      border: isSelected ? '1px solid #5d8f82' : '1px solid #a9c8bb',
                      padding: '2px 8px',
                      borderRadius: '6px',
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: '0.25rem',
                    }}
                  >
                    <Command size={10} />
                    {item.formatted_shortcut || `/${item.shortcut || 'sin-atajo'}`}
                  </span>
                </div>

                {/* Title & Preview */}
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', justifyContent: 'space-between' }}>
                    <span style={{ fontWeight: 600, fontSize: '0.84rem', color: isSelected ? '#456c61' : '#263c38' }}>
                      {item.title}
                    </span>
                    {item.category && (
                      <span
                        className="badge"
                        style={{
                          fontSize: '0.65rem',
                          background: '#eaf1ee',
                          color: '#61726d',
                          padding: '1px 5px',
                        }}
                      >
                        {item.category}
                      </span>
                    )}
                  </div>
                  <div
                    style={{
                      fontSize: '0.75rem',
                      color: isSelected ? '#56736b' : '#61726d',
                      overflow: 'hidden',
                      textOverflow: 'ellipsis',
                      whiteSpace: 'nowrap',
                      marginTop: '2px',
                    }}
                  >
                    {item.content}
                  </div>
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
};
