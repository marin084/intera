import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import type { Conversation } from '../../types';
import { api } from '../../services/api';
import { useIsMobile } from '../../hooks/useMediaQuery';
import {
  X,
  Pencil,
  Check,
  ChevronDown,
  ChevronRight,
  Phone,
  Mail,
  Calendar,
  Hash,
  Globe,
  Tag,
  Plus,
  FileText,
  Download,
  Loader2,
  Send,
} from 'lucide-react';

interface ContactDetailPanelProps {
  conversation: Conversation;
  isOpen: boolean;
  onClose: () => void;
  onContactUpdated?: (updatedContact: any) => void;
  onTagAdded?: (tag: string) => void;
  onTagRemoved?: (tag: string) => void;
  onNoteAdded?: (note: any) => void;
}

export const ContactDetailPanel: React.FC<ContactDetailPanelProps> = ({
  conversation,
  isOpen,
  onClose,
  onContactUpdated,
  onTagAdded,
  onTagRemoved,
  onNoteAdded,
}) => {
  const isMobile = useIsMobile();
  // Accordion section collapse state
  const [openSections, setOpenSections] = useState<Record<string, boolean>>({
    contact: true,
    variables: true,
    tags: true,
    notes: true,
    media: true,
  });

  // Contact name inline editing
  const [isEditingName, setIsEditingName] = useState(false);
  const [editedName, setEditedName] = useState('');
  const [isSavingName, setIsSavingName] = useState(false);

  // Tag creation state
  const [isAddingTag, setIsAddingTag] = useState(false);
  const [newTagInput, setNewTagInput] = useState('');
  const [isSavingTag, setIsSavingTag] = useState(false);

  // Internal note quick add
  const [newNoteInput, setNewNoteInput] = useState('');
  const [isSavingNote, setIsSavingNote] = useState(false);

  // Image preview modal
  const [previewImage, setPreviewImage] = useState<{ url: string; name: string } | null>(null);

  useEffect(() => {
    if (!isOpen || !isMobile) return;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handleEscape);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleEscape);
    };
  }, [isOpen, isMobile, onClose]);

  if (!isOpen) return null;

  const contact = conversation.contact;
  const displayName = contact?.display_name || contact?.name || 'Contacto sin nombre';
  const channelType = conversation.channel?.type || 'webchat';
  const isWhatsApp = channelType === 'whatsapp';

  const toggleSection = (section: string) => {
    setOpenSections((prev) => ({ ...prev, [section]: !prev[section] }));
  };

  const startEditName = () => {
    setEditedName(displayName);
    setIsEditingName(true);
  };

  const handleSaveName = async () => {
    const trimmed = editedName.trim();
    if (!trimmed || trimmed === displayName) {
      setIsEditingName(false);
      return;
    }

    setIsSavingName(true);
    try {
      const res = await api.updateConversationContact(conversation.id, trimmed);
      setIsEditingName(false);
      if (onContactUpdated) {
        onContactUpdated(res.contact);
      }
    } catch (err) {
      console.error('Error al actualizar nombre de contacto:', err);
    } finally {
      setIsSavingName(false);
    }
  };

  const handleAddTag = async () => {
    const trimmed = newTagInput.trim();
    if (!trimmed) {
      setIsAddingTag(false);
      return;
    }

    setIsSavingTag(true);
    try {
      await api.addConversationTag(conversation.id, trimmed);
      setNewTagInput('');
      setIsAddingTag(false);
      if (onTagAdded) {
        onTagAdded(trimmed);
      }
    } catch (err) {
      console.error('Error al agregar etiqueta:', err);
    } finally {
      setIsSavingTag(false);
    }
  };

  const handleRemoveTag = async (tagToRemove: string) => {
    try {
      await api.removeConversationTag(conversation.id, tagToRemove);
      if (onTagRemoved) {
        onTagRemoved(tagToRemove);
      }
    } catch (err) {
      console.error('Error al remover etiqueta:', err);
    }
  };

  const handleAddNote = async (e: React.FormEvent) => {
    e.preventDefault();
    const trimmed = newNoteInput.trim();
    if (!trimmed) return;

    setIsSavingNote(true);
    try {
      const res = await api.sendMessage(conversation.id, trimmed, { visibility: 'internal' });
      setNewNoteInput('');
      if (onNoteAdded) {
        onNoteAdded(res.data);
      }
    } catch (err) {
      console.error('Error al guardar nota interna:', err);
    } finally {
      setIsSavingNote(false);
    }
  };

  // Avatar initials helper
  const getInitials = (name: string) => {
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) {
      return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return name.slice(0, 2).toUpperCase() || 'CT';
  };

  // Format date helper
  const formatDate = (isoString?: string | null) => {
    if (!isoString) return 'No registrado';
    try {
      const d = new Date(isoString);
      return d.toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      });
    } catch {
      return isoString;
    }
  };

  const formatFileSize = (bytes?: number | null) => {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  };

  const tags = conversation.tags || [];
  const variables = conversation.variables || {};
  const attachments = conversation.attachments || [];
  const internalNotes = conversation.internal_notes || [];

  const panelContent = (
    <>
      {/* Mobile Backdrop */}
      <div
        className="contact-panel-backdrop"
        onClick={onClose}
        style={{
          display: 'none',
          position: 'fixed',
          inset: 0,
          background: 'rgba(15, 23, 42, 0.45)',
          backdropFilter: 'blur(2px)',
          zIndex: 40,
        }}
      />

      <aside
        id="contact-detail-panel"
        className="contact-detail-panel"
        role="dialog"
        aria-modal={isMobile ? 'true' : undefined}
        aria-labelledby="contact-detail-panel-title"
        aria-label="Ficha 360 del Contacto"
        style={{
          width: '330px',
          minWidth: '300px',
          height: '100%',
          background: '#ffffff',
          borderLeft: '1px solid var(--border)',
          display: 'flex',
          flexDirection: 'column',
          overflowY: 'auto',
          flexShrink: 0,
          zIndex: 30,
        }}
      >
        {/* Panel Header */}
        <div
          style={{
            padding: '1rem',
            borderBottom: '1px solid var(--border)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            background: 'var(--bg-card)',
          }}
        >
          <div id="contact-detail-panel-title" style={{ fontSize: '0.95rem', fontWeight: 600, color: 'var(--text-main)' }}>
            Ficha 360° del Contacto
          </div>
          <button
            type="button"
            onClick={onClose}
            data-testid="close-contact-panel-btn"
            aria-label="Cerrar panel de contacto"
            style={{
              background: 'transparent',
              border: 'none',
              cursor: 'pointer',
              color: 'var(--text-muted)',
              display: 'flex',
              alignItems: 'center',
              padding: '4px',
              borderRadius: '4px',
            }}
          >
            <X size={18} />
          </button>
        </div>

        {/* Quick Identity Header */}
        <div
          style={{
            padding: '1.25rem 1rem',
            textAlign: 'center',
            borderBottom: '1px solid var(--border)',
            background: '#fafbfc',
          }}
        >
          {/* Avatar */}
          <div
            style={{
              width: '64px',
              height: '64px',
              borderRadius: '50%',
              background: isWhatsApp ? '#5d8f82' : '#5d8f82',
              color: '#ffffff',
              fontSize: '1.4rem',
              fontWeight: 700,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              margin: '0 auto 0.75rem',
              boxShadow: '0 2px 4px rgba(0,0,0,0.08)',
            }}
          >
            {getInitials(displayName)}
          </div>

          {/* Inline Editable Name */}
          <div style={{ minHeight: '32px', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            {isEditingName ? (
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem', width: '100%', maxWidth: '240px' }}>
                <input
                  type="text"
                  data-testid="edit-contact-name-input"
                  value={editedName}
                  onChange={(e) => setEditedName(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') handleSaveName();
                    if (e.key === 'Escape') setIsEditingName(false);
                  }}
                  autoFocus
                  aria-label="Editar nombre de contacto"
                  style={{
                    flex: 1,
                    fontSize: '0.95rem',
                    fontWeight: 600,
                    padding: '4px 8px',
                    borderRadius: '4px',
                    border: '1px solid var(--primary)',
                    outline: 'none',
                    textAlign: 'center',
                  }}
                />
                <button
                  type="button"
                  data-testid="save-contact-name-btn"
                  onClick={handleSaveName}
                  disabled={isSavingName}
                  aria-label="Guardar nombre"
                  style={{
                    background: 'var(--primary)',
                    color: '#ffffff',
                    border: 'none',
                    borderRadius: '4px',
                    padding: '4px 6px',
                    cursor: 'pointer',
                    display: 'flex',
                    alignItems: 'center',
                  }}
                >
                  {isSavingName ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
                </button>
                <button
                  type="button"
                  data-testid="cancel-contact-name-btn"
                  onClick={() => setIsEditingName(false)}
                  aria-label="Cancelar edición"
                  style={{
                    background: 'transparent',
                    color: 'var(--text-muted)',
                    border: '1px solid var(--border)',
                    borderRadius: '4px',
                    padding: '4px 6px',
                    cursor: 'pointer',
                    display: 'flex',
                    alignItems: 'center',
                  }}
                >
                  <X size={14} />
                </button>
              </div>
            ) : (
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '0.4rem',
                }}
              >
                <span
                  style={{
                    fontSize: '1.05rem',
                    fontWeight: 700,
                    color: 'var(--text-main)',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                    maxWidth: '220px',
                  }}
                  title={displayName}
                >
                  {displayName}
                </span>
                <button
                  type="button"
                  data-testid="edit-contact-name-btn"
                  onClick={startEditName}
                  aria-label="Editar nombre"
                  style={{
                    background: 'transparent',
                    border: 'none',
                    cursor: 'pointer',
                    color: 'var(--text-muted)',
                    display: 'flex',
                    alignItems: 'center',
                    padding: '2px',
                  }}
                >
                  <Pencil size={13} />
                </button>
              </div>
            )}
          </div>

          {/* Badges: Channel & Phone / Email */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '0.4rem', marginTop: '0.5rem', flexWrap: 'wrap' }}>
            <span
              className="badge"
              style={{
                fontSize: '0.7rem',
                fontWeight: 600,
                backgroundColor: isWhatsApp ? '#eaf1ee' : '#eaf1ee',
                color: isWhatsApp ? '#5d8f82' : '#5d8f82',
                border: `1px solid ${isWhatsApp ? '#a9c8bb' : '#a9c8bb'}`,
              }}
            >
              {isWhatsApp ? 'WhatsApp' : 'Webchat'}
            </span>

            {contact?.country && (
              <span
                className="badge"
                style={{
                  fontSize: '0.7rem',
                  backgroundColor: '#eaf1ee',
                  color: '#61726d',
                }}
              >
                {contact.country}
              </span>
            )}
          </div>

          {/* Last session activity */}
          <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.5rem' }}>
            Última actividad: {formatDate(conversation.last_message_at || conversation.updated_at)}
          </div>
        </div>

        {/* Collapsible Sections Container */}
        <div style={{ flex: 1, overflowY: 'auto' }}>
          {/* Section 1: Contact Details */}
          <div style={{ borderBottom: '1px solid var(--border)' }}>
            <button
              type="button"
              onClick={() => toggleSection('contact')}
              style={{
                width: '100%',
                padding: '0.75rem 1rem',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                background: 'transparent',
                border: 'none',
                fontWeight: 600,
                fontSize: '0.85rem',
                color: 'var(--text-main)',
                cursor: 'pointer',
              }}
            >
              <span>📋 Datos del Contacto</span>
              {openSections.contact ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
            </button>
            {openSections.contact && (
              <div style={{ padding: '0 1rem 0.85rem', fontSize: '0.8rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                  <Phone size={14} color="var(--text-muted)" />
                  <span style={{ color: 'var(--text-muted)' }}>Teléfono:</span>
                  <span style={{ fontWeight: 600, color: 'var(--text-main)' }}>
                    {contact?.phone || 'No registrado'}
                  </span>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                  <Mail size={14} color="var(--text-muted)" />
                  <span style={{ color: 'var(--text-muted)' }}>Email:</span>
                  <span style={{ fontWeight: 600, color: 'var(--text-main)' }}>
                    {contact?.email || 'No registrado'}
                  </span>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                  <Hash size={14} color="var(--text-muted)" />
                  <span style={{ color: 'var(--text-muted)' }}>Identificador:</span>
                  <span style={{ fontFamily: 'monospace', fontSize: '0.75rem', color: 'var(--text-main)' }}>
                    {contact?.external_identifier || contact?.id}
                  </span>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                  <Globe size={14} color="var(--text-muted)" />
                  <span style={{ color: 'var(--text-muted)' }}>País:</span>
                  <span style={{ fontWeight: 600, color: 'var(--text-main)' }}>
                    {contact?.country || 'No detectado'}
                  </span>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                  <Calendar size={14} color="var(--text-muted)" />
                  <span style={{ color: 'var(--text-muted)' }}>Primer contacto:</span>
                  <span style={{ color: 'var(--text-main)' }}>
                    {formatDate(contact?.created_at || conversation.started_at)}
                  </span>
                </div>
              </div>
            )}
          </div>

          {/* Section 2: Flow Execution Variables */}
          <div style={{ borderBottom: '1px solid var(--border)' }}>
            <button
              type="button"
              onClick={() => toggleSection('variables')}
              style={{
                width: '100%',
                padding: '0.75rem 1rem',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                background: 'transparent',
                border: 'none',
                fontWeight: 600,
                fontSize: '0.85rem',
                color: 'var(--text-main)',
                cursor: 'pointer',
              }}
            >
              <span>⚙️ Variables del Flujo ({Object.keys(variables).length})</span>
              {openSections.variables ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
            </button>
            {openSections.variables && (
              <div style={{ padding: '0 1rem 0.85rem' }}>
                {Object.keys(variables).length === 0 ? (
                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', fontStyle: 'italic' }}>
                    Sin variables capturadas aún
                  </div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
                    {Object.entries(variables).map(([key, val]) => (
                      <div
                        key={key}
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'space-between',
                          padding: '4px 6px',
                          background: '#f4f7f5',
                          borderRadius: '4px',
                          border: '1px solid #eaf1ee',
                          fontSize: '0.75rem',
                          gap: '0.5rem',
                        }}
                      >
                        <span
                          style={{
                            fontFamily: 'monospace',
                            color: '#5d8f82',
                            fontWeight: 600,
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap',
                          }}
                        >
                          ${key}
                        </span>
                        <span
                          className="badge"
                          style={{
                            backgroundColor: '#ffffff',
                            border: '1px solid var(--border)',
                            color: 'var(--text-main)',
                            fontWeight: 500,
                            maxWidth: '150px',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap',
                          }}
                          title={typeof val === 'object' ? JSON.stringify(val) : String(val)}
                        >
                          {typeof val === 'object' ? JSON.stringify(val) : String(val)}
                        </span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Section 3: Tags */}
          <div style={{ borderBottom: '1px solid var(--border)' }}>
            <button
              type="button"
              onClick={() => toggleSection('tags')}
              style={{
                width: '100%',
                padding: '0.75rem 1rem',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                background: 'transparent',
                border: 'none',
                fontWeight: 600,
                fontSize: '0.85rem',
                color: 'var(--text-main)',
                cursor: 'pointer',
              }}
            >
              <span>🏷️ Etiquetas ({tags.length})</span>
              {openSections.tags ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
            </button>
            {openSections.tags && (
              <div style={{ padding: '0 1rem 0.85rem' }}>
                {tags.length === 0 && (
                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', fontStyle: 'italic', marginBottom: '0.5rem' }}>
                    Sin etiquetas asignadas
                  </div>
                )}
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.35rem', marginBottom: '0.5rem' }}>
                  {tags.map((tag) => {
                    const tagName = typeof tag === 'string' ? tag : tag.name;
                    const tagKey = typeof tag === 'string' ? tag : (tag.id || tag.name);
                    const tagColor = typeof tag === 'string' ? undefined : tag.color;

                    return (
                      <span
                        key={tagKey}
                        className="badge"
                        style={{
                          fontSize: '0.75rem',
                          backgroundColor: tagColor ? `${tagColor}15` : '#eaf1ee',
                          color: tagColor || 'var(--text-main)',
                          border: `1px solid ${tagColor ? `${tagColor}40` : '#d9e3de'}`,
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: '0.25rem',
                          padding: '3px 7px',
                        }}
                      >
                        <Tag size={11} color={tagColor || 'var(--text-muted)'} />
                        {tagName}
                        <button
                          type="button"
                          data-testid={`remove-tag-${tagName}`}
                          onClick={() => handleRemoveTag(tagName)}
                          aria-label={`Eliminar etiqueta ${tagName}`}
                          style={{
                            background: 'transparent',
                            border: 'none',
                            cursor: 'pointer',
                            display: 'inline-flex',
                            padding: 0,
                            color: tagColor || 'var(--text-muted)',
                          }}
                        >
                          <X size={12} />
                        </button>
                      </span>
                    );
                  })}
                </div>

                {isAddingTag ? (
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem', marginTop: '0.25rem' }}>
                    <input
                      type="text"
                      data-testid="new-tag-input"
                      placeholder="Nueva etiqueta..."
                      value={newTagInput}
                      onChange={(e) => setNewTagInput(e.target.value)}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter') handleAddTag();
                        if (e.key === 'Escape') setIsAddingTag(false);
                      }}
                      autoFocus
                      aria-label="Nombre de etiqueta"
                      style={{
                        flex: 1,
                        fontSize: '0.75rem',
                        padding: '4px 6px',
                        border: '1px solid var(--border)',
                        borderRadius: '4px',
                        outline: 'none',
                      }}
                    />
                    <button
                      type="button"
                      data-testid="save-tag-btn"
                      onClick={handleAddTag}
                      disabled={isSavingTag}
                      aria-label="Confirmar etiqueta"
                      style={{
                        background: 'var(--primary)',
                        color: '#ffffff',
                        border: 'none',
                        borderRadius: '4px',
                        padding: '4px 6px',
                        cursor: 'pointer',
                        display: 'flex',
                        alignItems: 'center',
                      }}
                    >
                      {isSavingTag ? <Loader2 size={12} className="animate-spin" /> : <Check size={12} />}
                    </button>
                    <button
                      type="button"
                      onClick={() => setIsAddingTag(false)}
                      aria-label="Cancelar etiqueta"
                      style={{
                        background: 'transparent',
                        color: 'var(--text-muted)',
                        border: '1px solid var(--border)',
                        borderRadius: '4px',
                        padding: '4px 6px',
                        cursor: 'pointer',
                        display: 'flex',
                        alignItems: 'center',
                      }}
                    >
                      <X size={12} />
                    </button>
                  </div>
                ) : (
                  <button
                    type="button"
                    data-testid="add-tag-trigger-btn"
                    onClick={() => setIsAddingTag(true)}
                    style={{
                      background: 'transparent',
                      border: '1px dashed var(--border)',
                      borderRadius: '4px',
                      color: 'var(--primary)',
                      fontSize: '0.75rem',
                      fontWeight: 500,
                      padding: '3px 8px',
                      cursor: 'pointer',
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: '0.25rem',
                    }}
                  >
                    <Plus size={12} /> Agregar etiqueta
                  </button>
                )}
              </div>
            )}
          </div>

          {/* Section 4: Internal Notes */}
          <div style={{ borderBottom: '1px solid var(--border)' }}>
            <button
              type="button"
              onClick={() => toggleSection('notes')}
              style={{
                width: '100%',
                padding: '0.75rem 1rem',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                background: 'transparent',
                border: 'none',
                fontWeight: 600,
                fontSize: '0.85rem',
                color: 'var(--text-main)',
                cursor: 'pointer',
              }}
            >
              <span>📝 Notas Internas ({internalNotes.length})</span>
              {openSections.notes ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
            </button>
            {openSections.notes && (
              <div style={{ padding: '0 1rem 0.85rem' }}>
                {/* Notes List */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem', marginBottom: '0.6rem' }}>
                  {internalNotes.length === 0 ? (
                    <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', fontStyle: 'italic' }}>
                      Sin notas internas aún
                    </div>
                  ) : (
                    internalNotes.map((note) => (
                      <div
                        key={note.id}
                        style={{
                          background: '#fefce8',
                          border: '1px solid #fef08a',
                          borderRadius: '6px',
                          padding: '6px 8px',
                          fontSize: '0.75rem',
                        }}
                      >
                        <div style={{ display: 'flex', justifyContent: 'space-between', color: '#854d0e', fontWeight: 600, marginBottom: '2px', fontSize: '0.7rem' }}>
                          <span>{note.author_name || note.sender_name || 'Agente'}</span>
                          <span>{(note.created_at || note.sent_at) ? new Date(note.created_at || note.sent_at!).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}</span>
                        </div>
                        <div style={{ color: '#713f12', wordBreak: 'break-word' }}>{note.content}</div>
                      </div>
                    ))
                  )}
                </div>

                {/* Quick Add Note Form */}
                <form onSubmit={handleAddNote} style={{ display: 'flex', gap: '0.25rem' }}>
                  <input
                    type="text"
                    data-testid="new-note-input"
                    placeholder="Escribir nota interna..."
                    value={newNoteInput}
                    onChange={(e) => setNewNoteInput(e.target.value)}
                    aria-label="Nota interna rápida"
                    style={{
                      flex: 1,
                      fontSize: '0.75rem',
                      padding: '4px 6px',
                      borderRadius: '4px',
                      border: '1px solid var(--border)',
                      outline: 'none',
                    }}
                  />
                  <button
                    type="submit"
                    data-testid="submit-note-btn"
                    disabled={isSavingNote || !newNoteInput.trim()}
                    aria-label="Guardar nota interna"
                    style={{
                      background: 'var(--warning, #d97706)',
                      color: '#ffffff',
                      border: 'none',
                      borderRadius: '4px',
                      padding: '4px 8px',
                      cursor: isSavingNote || !newNoteInput.trim() ? 'not-allowed' : 'pointer',
                      display: 'flex',
                      alignItems: 'center',
                    }}
                  >
                    {isSavingNote ? <Loader2 size={12} className="animate-spin" /> : <Send size={12} />}
                  </button>
                </form>
              </div>
            )}
          </div>

          {/* Section 5: Multimedia & Documents */}
          <div style={{ borderBottom: '1px solid var(--border)' }}>
            <button
              type="button"
              onClick={() => toggleSection('media')}
              style={{
                width: '100%',
                padding: '0.75rem 1rem',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                background: 'transparent',
                border: 'none',
                fontWeight: 600,
                fontSize: '0.85rem',
                color: 'var(--text-main)',
                cursor: 'pointer',
              }}
            >
              <span>📎 Multimedia y Documentos ({attachments.length})</span>
              {openSections.media ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
            </button>
            {openSections.media && (
              <div style={{ padding: '0 1rem 0.85rem' }}>
                {attachments.length === 0 ? (
                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', fontStyle: 'italic' }}>
                    Sin archivos compartidos
                  </div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
                    {attachments.map((att) => {
                      const isImg = att.type === 'image';

                      return (
                        <div
                          key={att.id}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '0.5rem',
                            padding: '6px',
                            background: '#f4f7f5',
                            borderRadius: '6px',
                            border: '1px solid #eaf1ee',
                          }}
                        >
                          {isImg ? (
                            <button
                              type="button"
                              onClick={() => setPreviewImage({ url: att.url, name: att.file_name })}
                              style={{
                                width: '40px',
                                height: '40px',
                                borderRadius: '4px',
                                overflow: 'hidden',
                                border: 'none',
                                padding: 0,
                                cursor: 'pointer',
                                background: '#d9e3de',
                                flexShrink: 0,
                              }}
                              title="Ver vista previa"
                            >
                              <img
                                src={att.url}
                                alt={att.file_name}
                                style={{ width: '100%', height: '100%', objectFit: 'cover' }}
                              />
                            </button>
                          ) : (
                            <div
                              style={{
                                width: '40px',
                                height: '40px',
                                borderRadius: '4px',
                                background: '#eaf1ee',
                                color: '#5d8f82',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                flexShrink: 0,
                              }}
                            >
                              <FileText size={20} />
                            </div>
                          )}

                          <div style={{ flex: 1, minWidth: 0 }}>
                            <div
                              style={{
                                fontSize: '0.75rem',
                                fontWeight: 600,
                                color: 'var(--text-main)',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                              }}
                              title={att.file_name}
                            >
                              {att.file_name}
                            </div>
                            <div style={{ fontSize: '0.7rem', color: 'var(--text-muted)' }}>
                              {formatFileSize(att.file_size)} • {formatDate(att.sent_at)}
                            </div>
                          </div>

                          <a
                            href={att.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            download={att.file_name}
                            aria-label={`Descargar ${att.file_name}`}
                            style={{
                              color: 'var(--primary)',
                              padding: '4px',
                              display: 'flex',
                              alignItems: 'center',
                            }}
                          >
                            <Download size={14} />
                          </a>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      </aside>

      {/* Image Preview Modal */}
      {previewImage && (
        <div
          className="modal-overlay"
          role="dialog"
          aria-modal="true"
          aria-label={`Vista previa de ${previewImage.name}`}
          onClick={() => setPreviewImage(null)}
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(0, 0, 0, 0.75)',
            zIndex: 60,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            padding: '1rem',
          }}
        >
          <div
            className="modal-content side-panel-wide side-panel-image-content"
            onClick={(e) => e.stopPropagation()}
            style={{
              position: 'relative',
              maxWidth: '90vw',
              maxHeight: '90vh',
              background: '#000000',
              borderRadius: '8px',
              overflow: 'hidden',
              boxShadow: '0 20px 25px -5px rgba(0,0,0,0.5)',
            }}
          >
            <button
              type="button"
              onClick={() => setPreviewImage(null)}
              style={{
                position: 'absolute',
                top: '10px',
                right: '10px',
                background: 'rgba(0,0,0,0.6)',
                color: '#ffffff',
                border: 'none',
                borderRadius: '50%',
                width: '32px',
                height: '32px',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <X size={18} />
            </button>
            <img
              src={previewImage.url}
              alt={previewImage.name}
              style={{ maxWidth: '100%', maxHeight: '85vh', objectFit: 'contain', display: 'block' }}
            />
          </div>
        </div>
      )}
    </>
  );

  return isMobile ? createPortal(panelContent, document.body) : panelContent;
};
