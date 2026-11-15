import React, { useState, useMemo } from 'react';
import type { CannedResponse } from '../../types';
import {
  Zap,
  Search,
  X,
  Plus,
  Send,
  Copy,
  Check,
  Pencil,
  Trash2,
  Command,
  Loader2,
} from 'lucide-react';

interface CannedResponsesTabProps {
  responses: CannedResponse[];
  loading: boolean;
  canManage: boolean;
  onUseInChat: (content: string) => void;
  onCreate: () => void;
  onEdit: (response: CannedResponse) => void;
  onDelete: (id: string) => void;
}

export const CannedResponsesTab: React.FC<CannedResponsesTabProps> = ({
  responses,
  loading,
  canManage,
  onUseInChat,
  onCreate,
  onEdit,
  onDelete,
}) => {
  const [search, setSearch] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<string>('all');
  const [copiedId, setCopiedId] = useState<string | null>(null);

  // Extract unique categories
  const categories = useMemo(() => {
    const set = new Set<string>();
    responses.forEach((r) => {
      if (r.category && r.category.trim()) {
        set.add(r.category.trim());
      }
    });
    return Array.from(set);
  }, [responses]);

  // Filter responses
  const filteredResponses = useMemo(() => {
    const q = search.toLowerCase().trim();
    const cleanQ = q.replace(/^\/+/, '');

    return responses.filter((r) => {
      // Category filter
      if (selectedCategory !== 'all' && r.category !== selectedCategory) {
        return false;
      }

      // Search filter
      if (!q) return true;
      const matchTitle = r.title.toLowerCase().includes(q);
      const matchShortcut = r.shortcut ? r.shortcut.toLowerCase().includes(cleanQ) : false;
      const matchContent = r.content.toLowerCase().includes(q);

      return matchTitle || matchShortcut || matchContent;
    });
  }, [responses, search, selectedCategory]);

  const handleCopy = (id: string, text: string) => {
    try {
      navigator.clipboard.writeText(text);
      setCopiedId(id);
      setTimeout(() => {
        setCopiedId(null);
      }, 2000);
    } catch {
      // fallback
    }
  };

  return (
    <div
      data-testid="canned-responses-tab"
      style={{
        flex: 1,
        display: 'flex',
        flexDirection: 'column',
        overflow: 'hidden',
        background: '#f4f7f5',
      }}
    >
      {/* Top Action / Search Bar */}
      <div
        style={{
          padding: '0.85rem 1.25rem',
          background: 'white',
          borderBottom: '1px solid var(--border)',
          display: 'flex',
          flexDirection: 'column',
          gap: '0.65rem',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '0.75rem', flexWrap: 'wrap' }}>
          {/* Search Input */}
          <div style={{ position: 'relative', flex: 1, minWidth: '240px' }}>
            <Search size={16} style={{ position: 'absolute', left: '10px', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
            <input
              type="text"
              data-testid="canned-search-input"
              className="form-input"
              style={{ paddingLeft: '2rem', paddingRight: search ? '2rem' : '0.75rem' }}
              placeholder="Buscar por título, atajo /bcr o contenido..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            {search && (
              <button
                type="button"
                data-testid="clear-canned-search-btn"
                onClick={() => setSearch('')}
                style={{
                  position: 'absolute',
                  right: '8px',
                  top: '50%',
                  transform: 'translateY(-50%)',
                  background: 'transparent',
                  border: 'none',
                  cursor: 'pointer',
                  color: 'var(--text-muted)',
                  display: 'flex',
                  alignItems: 'center',
                }}
              >
                <X size={15} />
              </button>
            )}
          </div>

          {/* New Response Button (for Admins / Supervisors) */}
          {canManage && (
            <button
              type="button"
              data-testid="create-canned-response-btn"
              className="btn btn-primary"
              style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', whiteSpace: 'nowrap' }}
              onClick={onCreate}
            >
              <Plus size={16} /> Nueva respuesta
            </button>
          )}
        </div>

        {/* Category Pills */}
        {categories.length > 0 && (
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', overflowX: 'auto', paddingBottom: '2px' }}>
            <button
              type="button"
              data-testid="canned-category-all"
              onClick={() => setSelectedCategory('all')}
              className={`badge ${selectedCategory === 'all' ? 'badge-primary' : 'badge-secondary'}`}
              style={{
                cursor: 'pointer',
                border: selectedCategory === 'all' ? 'none' : '1px solid var(--border)',
                background: selectedCategory === 'all' ? 'var(--primary)' : 'white',
                color: selectedCategory === 'all' ? 'white' : 'var(--text-muted)',
                padding: '4px 10px',
                fontSize: '0.75rem',
              }}
            >
              Todas ({responses.length})
            </button>
            {categories.map((cat) => (
              <button
                key={cat}
                type="button"
                data-testid={`canned-category-${cat}`}
                onClick={() => setSelectedCategory(cat)}
                className={`badge ${selectedCategory === cat ? 'badge-primary' : 'badge-secondary'}`}
                style={{
                  cursor: 'pointer',
                  border: selectedCategory === cat ? 'none' : '1px solid var(--border)',
                  background: selectedCategory === cat ? 'var(--primary)' : 'white',
                  color: selectedCategory === cat ? 'white' : 'var(--text-muted)',
                  padding: '4px 10px',
                  fontSize: '0.75rem',
                }}
              >
                {cat}
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Catalog List */}
      <div style={{ flex: 1, overflowY: 'auto', padding: '1.25rem' }}>
        {loading ? (
          <div style={{ textAlign: 'center', padding: '3rem', color: 'var(--text-muted)' }}>
            <Loader2 size={32} className="animate-spin" style={{ margin: '0 auto 0.5rem', color: '#5d8f82' }} />
            <p style={{ fontSize: '0.85rem' }}>Cargando catálogo de respuestas rápidas...</p>
          </div>
        ) : filteredResponses.length === 0 ? (
          <div data-testid="canned-empty-state" style={{ textAlign: 'center', padding: '3rem 1.5rem', color: 'var(--text-muted)' }}>
            <Zap size={40} style={{ opacity: 0.3, margin: '0 auto 0.75rem', color: '#5d8f82' }} />
            <h4 style={{ fontSize: '1rem', fontWeight: 600, color: '#35564f', marginBottom: '0.35rem' }}>
              No se encontraron respuestas rápidas
            </h4>
            <p style={{ fontSize: '0.82rem', maxWidth: '360px', margin: '0 auto 1rem', lineHeight: 1.5 }}>
              {search
                ? `No hay coincidencias para "${search}". Intenta con otro término o limpia el filtro.`
                : 'Aún no se han configurado respuestas rápidas en el sistema.'}
            </p>
            {canManage && (
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                onClick={onCreate}
                style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
              >
                <Plus size={14} /> Crear la primera respuesta
              </button>
            )}
          </div>
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))', gap: '1rem' }}>
            {filteredResponses.map((item) => {
              const isCopied = copiedId === item.id;

              return (
                <div
                  key={item.id}
                  data-testid={`canned-card-${item.shortcut || item.id}`}
                  style={{
                    background: 'white',
                    borderRadius: '8px',
                    border: '1px solid var(--border)',
                    boxShadow: '0 1px 3px rgba(0, 0, 0, 0.05)',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'space-between',
                    padding: '1rem',
                    transition: 'box-shadow 0.15s ease',
                  }}
                >
                  <div>
                    {/* Top Row: Shortcut + Category + Manage Actions */}
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '0.5rem', marginBottom: '0.5rem' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', flexWrap: 'wrap' }}>
                        {item.shortcut ? (
                          <span
                            style={{
                              fontFamily: 'monospace',
                              fontWeight: 700,
                              fontSize: '0.8rem',
                              background: '#eaf1ee',
                              color: '#456c61',
                              border: '1px solid #a9c8bb',
                              padding: '2px 7px',
                              borderRadius: '4px',
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '0.2rem',
                            }}
                          >
                            <Command size={11} />
                            /{item.shortcut}
                          </span>
                        ) : (
                          <span style={{ fontSize: '0.72rem', color: 'var(--text-muted)', fontStyle: 'italic' }}>
                            Sin atajo
                          </span>
                        )}

                        {item.category && (
                          <span
                            className="badge"
                            style={{
                              fontSize: '0.68rem',
                              background: '#eaf1ee',
                              color: '#61726d',
                              padding: '2px 6px',
                            }}
                          >
                            {item.category}
                          </span>
                        )}
                      </div>

                      {/* Management Edit / Delete */}
                      {canManage && (
                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.2rem' }}>
                          <button
                            type="button"
                            data-testid={`edit-canned-btn-${item.id}`}
                            onClick={() => onEdit(item)}
                            className="btn btn-sm btn-ghost"
                            style={{ padding: '3px 5px', color: '#61726d' }}
                            title="Editar respuesta"
                          >
                            <Pencil size={13} />
                          </button>
                          <button
                            type="button"
                            data-testid={`delete-canned-btn-${item.id}`}
                            onClick={() => onDelete(item.id)}
                            className="btn btn-sm btn-ghost"
                            style={{ padding: '3px 5px', color: '#dc2626' }}
                            title="Eliminar respuesta"
                          >
                            <Trash2 size={13} />
                          </button>
                        </div>
                      )}
                    </div>

                    {/* Title */}
                    <div style={{ fontWeight: 700, fontSize: '0.9rem', color: '#263c38', marginBottom: '0.4rem' }}>
                      {item.title}
                    </div>

                    {/* Content Preview */}
                    <div
                      style={{
                        fontSize: '0.8rem',
                        color: '#61726d',
                        lineHeight: 1.45,
                        background: '#f4f7f5',
                        padding: '0.6rem 0.75rem',
                        borderRadius: '6px',
                        border: '1px solid #eaf1ee',
                        whiteSpace: 'pre-line',
                        maxHeight: '120px',
                        overflowY: 'auto',
                        marginBottom: '0.75rem',
                      }}
                    >
                      {item.content}
                    </div>
                  </div>

                  {/* Actions Footer */}
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: '0.5rem', paddingTop: '0.5rem', borderTop: '1px solid #eaf1ee' }}>
                    <button
                      type="button"
                      data-testid={`copy-canned-btn-${item.id}`}
                      className="btn btn-secondary btn-sm"
                      style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.75rem' }}
                      onClick={() => handleCopy(item.id, item.content)}
                    >
                      {isCopied ? <Check size={13} color="#5d8f82" /> : <Copy size={13} />}
                      {isCopied ? '¡Copiado!' : 'Copiar'}
                    </button>

                    <button
                      type="button"
                      data-testid={`use-canned-btn-${item.id}`}
                      className="btn btn-primary btn-sm"
                      style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.75rem' }}
                      onClick={() => onUseInChat(item.content)}
                    >
                      <Send size={13} /> Usar en chat
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
};
