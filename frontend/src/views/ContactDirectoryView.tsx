import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ContactRound, Download, Eye, FileSpreadsheet, Mail, Phone, Radio, RefreshCw, Search, Upload, X } from 'lucide-react';
import { useAuth } from '../context/useAuth';
import { useToast } from '../context/ToastContext';
import { api } from '../services/api';
import type { ContactDirectoryItem, ContactImportPreview } from '../types';

const importFields = [
  ['external_identifier', 'Identificador externo'],
  ['display_name', 'Nombre completo'],
  ['first_name', 'Nombre'],
  ['last_name', 'Apellido'],
  ['email', 'Correo electrónico'],
  ['phone', 'Teléfono'],
  ['country', 'País'],
  ['language', 'Idioma'],
  ['platforms', 'Plataformas'],
  ['whatsapp_bsuids', 'WhatsApp BSUIDs'],
  ['custom_fields', 'Campos personalizados (JSON)'],
] as const;

const formatDate = (value: string | null | undefined) => {
  if (!value) return 'Sin actividad registrada';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return 'Sin actividad registrada';
  return new Intl.DateTimeFormat('es-CR', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
};
const formatValue = (value: unknown) => {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
};

const statusLabel: Record<string, string> = {
  open: 'Abierta',
  pending: 'Pendiente',
  closed: 'Cerrada',
  bot: 'Automatizada',
  waiting_agent: 'Esperando agente',
  assigned: 'Asignada',
};

export const ContactDirectoryView: React.FC = () => {
  const { user, hasRole } = useAuth();
  const { showToast } = useToast();
  const isAllowed = Boolean(user?.is_platform_admin) || (typeof hasRole === 'function' && (hasRole('super_admin') || hasRole('admin')));
  const [contacts, setContacts] = useState<ContactDirectoryItem[]>([]);
  const [selected, setSelected] = useState<ContactDirectoryItem | null>(null);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState('');
  const [detailError, setDetailError] = useState('');
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(25);
  const detailPanelRef = useRef<HTMLDivElement>(null);
  const detailCloseRef = useRef<HTMLButtonElement>(null);
  const detailTriggerRef = useRef<HTMLButtonElement>(null);
  const importFileRef = useRef<HTMLInputElement>(null);
  const importPanelRef = useRef<HTMLDivElement>(null);
  const importCloseRef = useRef<HTMLButtonElement>(null);
  const importTriggerRef = useRef<HTMLButtonElement>(null);
  const importLoadingRef = useRef(false);
  const exportPanelRef = useRef<HTMLDivElement>(null);
  const exportCloseRef = useRef<HTMLButtonElement>(null);
  const exportTriggerRef = useRef<HTMLButtonElement>(null);
  const [importFile, setImportFile] = useState<File | null>(null);
  const [importPreview, setImportPreview] = useState<ContactImportPreview | null>(null);
  const [importMapping, setImportMapping] = useState<Record<string, string | null>>({});
  const [destinationChannelId, setDestinationChannelId] = useState<string | null>(null);
  const [importOpen, setImportOpen] = useState(false);
  const [importLoading, setImportLoading] = useState(false);
  const [importError, setImportError] = useState('');
  const [importResultErrors, setImportResultErrors] = useState<Array<{ row: number; message: string }>>([]);
  const [exportOpen, setExportOpen] = useState(false);
  const [exportFrom, setExportFrom] = useState('');
  const [exportTo, setExportTo] = useState('');
  importLoadingRef.current = importLoading;

  const loadContacts = useCallback(async (term = search, targetPage = page) => {
    setLoading(true);
    setError('');
    try {
      const response = await api.getContacts({ search: term.trim() || undefined, page: targetPage, per_page: pageSize });
      setContacts(response.data);
      setMeta({ current_page: response.meta.current_page, last_page: response.meta.last_page, total: response.meta.total });
    } catch (cause: any) {
      setError(cause?.message || 'No se pudieron cargar los contactos.');
    } finally {
      setLoading(false);
    }
  }, [page, pageSize, search]);

  useEffect(() => {
    if (!isAllowed) return;
    const timer = window.setTimeout(() => { void loadContacts(search, page); }, 250);
    return () => window.clearTimeout(timer);
  }, [isAllowed, loadContacts, page, search]);

  const openContact = async (contact: ContactDirectoryItem, trigger?: HTMLButtonElement) => {
    detailTriggerRef.current = trigger || null;
    setSelected(contact);
    setDetailLoading(true);
    setDetailError('');
    try {
      const response = await api.getContact(contact.id);
      setSelected(response.data);
    } catch (cause: any) {
      setDetailError(cause?.message || 'No se pudo cargar el detalle del contacto.');
    } finally {
      setDetailLoading(false);
    }
  };

  const chooseImportFile = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    setImportFile(file);
    setImportPreview(null);
    setImportMapping({});
    setDestinationChannelId(null);
    setImportError('');
    setImportResultErrors([]);
    setImportOpen(true);
    setImportLoading(true);
    try {
      const response = await api.getContactsImportPreview(file);
      setImportPreview(response.data);
      setImportMapping(response.data.suggested_mapping);
    } catch (cause: any) {
      setImportError(cause?.message || 'No se pudo leer el CSV.');
    } finally {
      setImportLoading(false);
    }
  };

  const completeImport = async () => {
    if (!importFile || !importPreview) return;
    if (!importMapping.external_identifier && !importMapping.email && !importMapping.phone) {
      setImportError('Mapea un identificador externo, correo o teléfono para poder importar.');
      return;
    }
    if (!destinationChannelId) {
      setImportError('Selecciona un canal destino para poder importar.');
      return;
    }

    setImportLoading(true);
    setImportError('');
    try {
      const response = await api.importContacts(importFile, importMapping, destinationChannelId);
      const result = response.data;
      if (result.errors.length === 0) {
        setImportOpen(false);
        setImportFile(null);
        setImportPreview(null);
      } else {
        setImportResultErrors(result.errors);
      }
      setPage(1);
      await loadContacts(search, 1);
      const detail = result.errors.length > 0 ? ' ' + result.errors.length + ' filas requieren revisión.' : '';
      showToast('Importación lista: ' + result.created + ' creados, ' + result.updated + ' actualizados y ' + result.skipped + ' omitidos.' + detail, result.errors.length > 0 ? 'info' : 'success');
    } catch (cause: any) {
      setImportError(cause?.message || 'No se pudo importar el archivo.');
    } finally {
      setImportLoading(false);
    }
  };

  const detailOpen = selected !== null;
  useEffect(() => {
    if (!detailOpen) return;

    const previousFocus = detailTriggerRef.current || (document.activeElement as HTMLElement | null);
    detailCloseRef.current?.focus();
    const panel = detailPanelRef.current;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        setSelected(null);
        return;
      }
      if (event.key !== 'Tab' || !panel) return;
      const focusable = Array.from(panel.querySelectorAll<HTMLElement>('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter((element) => !element.hasAttribute('disabled'));
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => {
      window.removeEventListener('keydown', onKeyDown);
      previousFocus?.focus();
    };
  }, [detailOpen]);

  useEffect(() => {
    if (!importOpen) return;

    const previousFocus = importTriggerRef.current || (document.activeElement as HTMLElement | null);
    importCloseRef.current?.focus();
    const panel = importPanelRef.current;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        if (!importLoadingRef.current) setImportOpen(false);
        return;
      }
      if (event.key !== 'Tab' || !panel) return;
      const focusable = Array.from(panel.querySelectorAll<HTMLElement>('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter((element) => !element.hasAttribute('disabled'));
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => {
      window.removeEventListener('keydown', onKeyDown);
      previousFocus?.focus();
    };
  }, [importOpen]);

  useEffect(() => {
    if (!exportOpen) return;

    const previousFocus = exportTriggerRef.current || (document.activeElement as HTMLElement | null);
    exportCloseRef.current?.focus();
    const panel = exportPanelRef.current;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        setExportOpen(false);
        return;
      }
      if (event.key !== 'Tab' || !panel) return;
      const focusable = Array.from(panel.querySelectorAll<HTMLElement>('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter((element) => !element.hasAttribute('disabled'));
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => {
      window.removeEventListener('keydown', onKeyDown);
      previousFocus?.focus();
    };
  }, [exportOpen]);

  const exportRangeInvalid = Boolean(exportFrom && exportTo && exportFrom > exportTo);
  if (!isAllowed) {
    return (
      <div className="card" role="alert">
        <div style={{ padding: '2.5rem 1rem', textAlign: 'center', color: 'var(--text-muted)' }}>
          <ContactRound size={36} style={{ opacity: 0.45, marginBottom: '0.5rem' }} />
          <h2 style={{ margin: 0, color: 'var(--text-primary)', fontSize: '1.15rem' }}>Acceso restringido</h2>
          <p style={{ margin: '0.5rem 0 0' }}>El directorio de contactos está disponible para administradores.</p>
        </div>
      </div>
    );
  }

  return (
    <>
      <div className="card">
        <div className="card-header">
          <div>
            <h2 className="card-title">Contactos</h2>
            <p style={{ margin: '0.3rem 0 0', color: 'var(--text-muted)', fontSize: '0.875rem' }}>
              Contactos del Workspace: conversaciones e importaciones, sin mezclar datos entre espacios.
            </p>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap', justifyContent: 'flex-end' }}>
            <span className="badge badge-secondary">{meta.total} {meta.total === 1 ? 'contacto' : 'contactos'}</span>
            <button ref={importTriggerRef} className="btn btn-secondary btn-sm" type="button" onClick={(event) => { importTriggerRef.current = event.currentTarget; importFileRef.current?.click(); }} disabled={importLoading}>
              <Upload size={14} aria-hidden="true" /> Importar CSV
            </button>
            <button ref={exportTriggerRef} className="btn btn-secondary btn-sm" type="button" onClick={(event) => { exportTriggerRef.current = event.currentTarget; setExportOpen(true); }}>
              <Download size={14} aria-hidden="true" /> Exportar CSV
            </button>
            <input ref={importFileRef} type="file" accept=".csv,text/csv" aria-label="Seleccionar CSV" onChange={(event) => void chooseImportFile(event)} style={{ display: 'none' }} />
          </div>
        </div>

        <div style={{ display: 'flex', gap: '0.65rem', alignItems: 'center', marginBottom: '1rem', flexWrap: 'wrap' }}>
          <label style={{ flex: '1 1 280px', minWidth: 0, position: 'relative' }}>
            <Search size={16} aria-hidden="true" style={{ position: 'absolute', left: '0.75rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
            <input aria-label="Buscar contactos" className="form-input" style={{ paddingLeft: '2.2rem' }} value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Buscar por nombre, email, teléfono o identificador" />
          </label>
          <button className="btn btn-secondary" type="button" onClick={() => void loadContacts(search, page)} disabled={loading}>
            <RefreshCw size={15} aria-hidden="true" /> Actualizar
          </button>
          <label style={{ display: 'flex', alignItems: 'center', gap: '0.45rem', color: 'var(--text-muted)', fontSize: '0.85rem' }}>
            <span>Mostrar</span>
            <select aria-label="Contactos por página" className="form-select" style={{ width: 'auto' }} value={pageSize} onChange={(event) => { setPageSize(Number(event.target.value)); setPage(1); }}>
              <option value={25}>25</option>
              <option value={50}>50</option>
              <option value={100}>100</option>
            </select>
          </label>
        </div>

        {loading ? (
          <div style={{ padding: '2.5rem 1rem', textAlign: 'center', color: 'var(--text-muted)' }} aria-live="polite">Cargando contactos…</div>
        ) : error ? (
          <div role="alert" style={{ padding: '1rem', border: '1px solid #fecaca', background: '#fff7f7', color: '#b42318', borderRadius: '10px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem' }}>
            <span>{error}</span>
            <button className="btn btn-secondary btn-sm" type="button" onClick={() => void loadContacts()}>Reintentar</button>
          </div>
        ) : contacts.length === 0 ? (
          <div style={{ padding: '2.75rem 1rem', textAlign: 'center', color: 'var(--text-muted)' }}>
            <ContactRound size={36} style={{ opacity: 0.45, marginBottom: '0.5rem' }} />
            <h3 style={{ margin: 0, color: 'var(--text-primary)', fontSize: '1rem' }}>{search.trim() ? 'No encontramos contactos' : 'Todavía no hay contactos'}</h3>
            <p style={{ margin: '0.45rem 0 0' }}>{search.trim() ? 'Prueba con otro nombre, email o identificador.' : 'Aparecerán aquí cuando una persona escriba o cuando importes una lista.'}</p>
          </div>
        ) : (
          <div className="table-wrapper">
            <table>
              <thead><tr><th>Contacto</th><th>Canales</th><th>Conversaciones</th><th>Variables</th><th>Última actividad</th><th>Acciones</th></tr></thead>
              <tbody>
                {contacts.map((contact) => (
                  <tr key={contact.id}>
                    <td data-mobile-label="Contacto">
                      <strong style={{ display: 'block', color: 'var(--text-primary)' }}>{contact.display_name || contact.external_identifier || 'Sin nombre'}</strong>
                      {contact.email && <small style={{ display: 'flex', alignItems: 'center', gap: '0.3rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}><Mail size={13} />{contact.email}</small>}
                      {!contact.email && contact.phone && <small style={{ display: 'flex', alignItems: 'center', gap: '0.3rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}><Phone size={13} />{contact.phone}</small>}
                      {contact.external_identifier && <small style={{ display: 'block', color: 'var(--text-muted)', marginTop: '0.2rem' }}>ID: {contact.external_identifier}</small>}
                    </td>
                    <td data-mobile-label="Canales"><div style={{ display: 'flex', gap: '0.35rem', flexWrap: 'wrap' }}>{contact.channels.map((channel) => <span className="badge badge-secondary" key={channel.id}><Radio size={12} /> {channel.name}</span>)}</div></td>
                    <td data-mobile-label="Conversaciones"><span className="badge badge-info">{contact.conversations_count}</span></td>
                    <td data-mobile-label="Variables"><span className="badge badge-secondary">{contact.variable_count}</span></td>
                    <td data-mobile-label="Última actividad"><span style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>{formatDate(contact.last_activity_at)}</span></td>
                    <td data-mobile-label="Acciones"><button className="btn btn-secondary btn-sm" type="button" onClick={(event) => void openContact(contact, event.currentTarget)}><Eye size={14} /> Ver ficha</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {!loading && !error && contacts.length > 0 && meta.last_page > 1 && (
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '0.75rem', marginTop: '1rem', flexWrap: 'wrap' }}>
            <span style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>Página {meta.current_page} de {meta.last_page}</span>
            <div style={{ display: 'flex', gap: '0.5rem' }}>
              <button className="btn btn-secondary btn-sm" type="button" disabled={page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}>Anterior</button>
              <button className="btn btn-secondary btn-sm" type="button" disabled={page >= meta.last_page} onClick={() => setPage((current) => Math.min(meta.last_page, current + 1))}>Siguiente</button>
            </div>
          </div>
        )}
      </div>

      {importOpen && (
        <div className="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="contact-import-title" onMouseDown={(event) => { if (event.target === event.currentTarget && !importLoading) setImportOpen(false); }}>
          <div ref={importPanelRef} className="modal-content" style={{ width: 'min(920px, 96vw)', maxWidth: 'min(920px, 96vw)', maxHeight: '90vh', overflow: 'auto' }}>
            <div className="modal-header">
              <div>
                <h3 id="contact-import-title" style={{ margin: 0 }}>Importar contactos desde CSV</h3>
                <p style={{ margin: '0.3rem 0 0', color: 'var(--text-muted)', fontSize: '0.85rem' }}>
                  {importFile?.name || 'Selecciona un archivo'} · Mapea cada columna antes de guardar.
                </p>
              </div>
              <button ref={importCloseRef} className="btn btn-secondary btn-sm" type="button" aria-label="Cerrar importación" onClick={() => setImportOpen(false)} disabled={importLoading}><X size={16} /></button>
            </div>

            {importLoading && !importPreview ? (
              <div style={{ padding: '2rem 1rem', textAlign: 'center', color: 'var(--text-muted)' }} aria-live="polite">Leyendo el CSV…</div>
            ) : importError && !importPreview ? (
              <div role="alert" style={{ margin: '1rem', padding: '0.85rem', border: '1px solid #fecaca', background: '#fff7f7', color: '#b42318', borderRadius: '10px' }}>{importError}</div>
            ) : importPreview ? (
              <div style={{ padding: '1rem' }}>
                <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center', marginBottom: '1rem', flexWrap: 'wrap' }}>
                  <span className="badge badge-secondary"><FileSpreadsheet size={13} /> {importPreview.row_count} filas detectadas</span>
                  <span style={{ color: 'var(--text-muted)', fontSize: '0.82rem' }}>Las columnas no mapeadas se guardarán como campos adicionales.</span>
                </div>
                <label style={{ display: 'grid', gap: '0.3rem', marginBottom: '1rem', color: 'var(--text-primary)', fontSize: '0.82rem' }}>
                  <span>Canal destino <strong style={{ color: '#b42318' }}>*</strong></span>
                  <select aria-label="Canal destino" className="form-input" value={destinationChannelId ?? ''} onChange={(event) => setDestinationChannelId(event.target.value || null)}>
                    <option value="">Selecciona el canal donde quedarán relacionados</option>
                    {importPreview.available_channels.map((channel) => <option key={channel.id} value={channel.id}>{channel.name}</option>)}
                  </select>
                  {!importPreview.available_channels.length && <span style={{ color: '#b42318' }}>No hay canales activos en este Workspace. Crea uno antes de importar.</span>}
                  {!!importPreview.available_channels.length && <span style={{ color: 'var(--text-muted)' }}>Todos los contactos importados se relacionarán con este canal.</span>}
                </label>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '0.7rem' }}>
                  {importFields.map(([field, label]) => (
                    <label key={field} style={{ display: 'grid', gap: '0.3rem', color: 'var(--text-primary)', fontSize: '0.82rem' }}>
                      <span>{label}{['external_identifier', 'email', 'phone'].includes(field) ? ' (identidad)' : ''}</span>
                      <select className="form-input" value={importMapping[field] ?? ''} onChange={(event) => {
                        const nextColumn = event.target.value || null;
                        setImportMapping((current) => ({ ...current, [field]: nextColumn }));
                      }}>
                        <option value="">No importar</option>
                        {importPreview.headers.map((header) => <option key={header} value={header}>{header}</option>)}
                      </select>
                    </label>
                  ))}
                </div>
                <p style={{ margin: '0.8rem 0', color: 'var(--text-muted)', fontSize: '0.8rem' }}>
                  Necesitas mapear al menos un identificador externo, correo o teléfono. El importador actualiza coincidencias por identificador, correo y luego teléfono.
                </p>
                {importError && <div role="alert" style={{ marginBottom: '0.8rem', padding: '0.7rem', border: '1px solid #fecaca', background: '#fff7f7', color: '#b42318', borderRadius: '8px' }}>{importError}</div>}
                {importResultErrors.length > 0 && <section role="alert" style={{ marginBottom: '0.8rem', padding: '0.7rem', border: '1px solid #fed7aa', background: '#fffaf0', color: '#8a4b08', borderRadius: '8px' }}><strong>Filas que requieren revisión</strong><div style={{ display: 'grid', gap: '0.25rem', marginTop: '0.45rem', maxHeight: '140px', overflow: 'auto', fontSize: '0.8rem' }}>{importResultErrors.map((item) => <div key={`${item.row}-${item.message}`}>Fila {item.row}: {item.message}</div>)}</div></section>}
                <h4 style={{ margin: '1rem 0 0.55rem', fontSize: '0.95rem' }}>Muestra del archivo</h4>
                <div className="table-wrapper" style={{ maxHeight: '220px', overflow: 'auto' }}>
                  <table>
                    <thead><tr>{importPreview.headers.map((header) => <th key={header}>{header}</th>)}</tr></thead>
                    <tbody>{importPreview.sample.map((row, index) => <tr key={index}>{importPreview.headers.map((header) => <td key={header} style={{ maxWidth: '180px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{row[header] || '—'}</td>)}</tr>)}</tbody>
                  </table>
                </div>
              </div>
            ) : null}

            <div className="modal-footer" style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem' }}>
              <button className="btn btn-secondary" type="button" onClick={() => setImportOpen(false)} disabled={importLoading}>Cancelar</button>
              <button className="btn btn-primary" type="button" onClick={() => void completeImport()} disabled={importLoading || importResultErrors.length > 0 || !importPreview || !destinationChannelId || (!importMapping.external_identifier && !importMapping.email && !importMapping.phone)}>
                {importLoading ? 'Importando…' : importResultErrors.length > 0 ? 'Importación revisada' : 'Importar contactos'}
              </button>
            </div>
          </div>
        </div>
      )}

      {exportOpen && (
        <div className="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="contact-export-title" onMouseDown={(event) => { if (event.target === event.currentTarget) setExportOpen(false); }}>
          <div ref={exportPanelRef} className="modal-content" style={{ width: 'min(520px, 96vw)', maxWidth: 'min(520px, 96vw)' }}>
            <div className="modal-header">
              <div>
                <h3 id="contact-export-title" style={{ margin: 0 }}>Exportar contactos</h3>
                <p style={{ margin: '0.3rem 0 0', color: 'var(--text-muted)', fontSize: '0.85rem' }}>
                  Descarga los contactos del Workspace activo. La búsqueda actual también se aplicará.
                </p>
              </div>
              <button ref={exportCloseRef} className="btn btn-secondary btn-sm" type="button" aria-label="Cerrar exportación" onClick={() => setExportOpen(false)}><X size={16} /></button>
            </div>
            <div style={{ padding: '1rem' }}>
              <p style={{ margin: '0 0 0.8rem', color: 'var(--text-primary)', fontSize: '0.9rem' }}>
                Opcionalmente, limita la descarga por fecha de registro.
              </p>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.7rem' }}>
                <label style={{ display: 'grid', gap: '0.3rem', color: 'var(--text-primary)', fontSize: '0.82rem' }}>
                  <span>Fecha de registro desde</span>
                  <input aria-label="Fecha de registro desde" className="form-input" type="date" value={exportFrom} max={exportTo || undefined} onChange={(event) => setExportFrom(event.target.value)} />
                </label>
                <label style={{ display: 'grid', gap: '0.3rem', color: 'var(--text-primary)', fontSize: '0.82rem' }}>
                  <span>Fecha de registro hasta</span>
                  <input aria-label="Fecha de registro hasta" className="form-input" type="date" value={exportTo} min={exportFrom || undefined} onChange={(event) => setExportTo(event.target.value)} />
                </label>
              </div>
              {search.trim() && <p style={{ margin: '0.8rem 0 0', color: 'var(--text-muted)', fontSize: '0.8rem' }}>También se aplicará la búsqueda: “{search.trim()}”.</p>}
              {exportRangeInvalid && <div role="alert" style={{ marginTop: '0.8rem', padding: '0.7rem', border: '1px solid #fecaca', background: '#fff7f7', color: '#b42318', borderRadius: '8px', fontSize: '0.82rem' }}>La fecha final debe ser igual o posterior a la fecha inicial.</div>}
            </div>
            <div className="modal-footer" style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem' }}>
              <button className="btn btn-secondary" type="button" onClick={() => setExportOpen(false)}>Cancelar</button>
              <a
                className="btn btn-primary"
                href={api.getContactsExportUrl({ search: search.trim() || undefined, created_from: exportFrom || undefined, created_to: exportTo || undefined })}
                download
                aria-disabled={exportRangeInvalid ? 'true' : undefined}
                onClick={(event) => {
                  if (exportRangeInvalid) {
                    event.preventDefault();
                    return;
                  }
                  setExportOpen(false);
                }}
              >
                <Download size={15} aria-hidden="true" /> Descargar CSV
              </a>
            </div>
          </div>
        </div>
      )}

      {selected && (
        <div className="modal-overlay flow-panel-overlay" role="dialog" aria-modal="true" aria-labelledby="contact-directory-detail-title" onMouseDown={(event) => { if (event.target === event.currentTarget) setSelected(null); }}>
          <div ref={detailPanelRef} className="modal-content flow-side-panel" style={{ width: 'min(620px, 100vw)', maxWidth: 'min(620px, 100vw)' }}>
            <div className="flow-side-panel-header">
              <div><h3 id="contact-directory-detail-title" style={{ margin: 0, fontSize: '1.1rem' }}>Ficha del contacto</h3><p style={{ margin: '0.25rem 0 0', color: 'var(--text-muted)', fontSize: '0.8rem' }}>Información capturada en este Workspace</p></div>
              <button ref={detailCloseRef} className="btn btn-secondary btn-sm" type="button" aria-label="Cerrar ficha" onClick={() => setSelected(null)}><X size={16} /></button>
            </div>
            <div className="flow-side-panel-body">
              {detailLoading ? <p style={{ color: 'var(--text-muted)' }}>Cargando detalle…</p> : detailError ? <div role="alert" style={{ color: '#b42318' }}>{detailError}</div> : (
                <>
                  <div style={{ padding: '0.85rem', border: '1px solid var(--border)', borderRadius: '10px', marginBottom: '1rem' }}>
                    <strong style={{ fontSize: '1.05rem' }}>{selected.display_name || selected.external_identifier || 'Sin nombre'}</strong>
                    <div style={{ display: 'grid', gap: '0.3rem', marginTop: '0.55rem', color: 'var(--text-muted)', fontSize: '0.85rem' }}>
                      {selected.email && <span><Mail size={13} style={{ verticalAlign: 'middle', marginRight: '0.35rem' }} />{selected.email}</span>}
                      {selected.phone && <span><Phone size={13} style={{ verticalAlign: 'middle', marginRight: '0.35rem' }} />{selected.phone}</span>}
                      {selected.country && <span>País: {selected.country}</span>}
                      {selected.external_identifier && <span>Identificador: {selected.external_identifier}</span>}
                    </div>
                  </div>
                  <section style={{ marginBottom: '1.25rem' }}>
                    <h4 style={{ margin: '0 0 0.55rem', fontSize: '0.95rem' }}>Canales</h4>
                    <div style={{ display: 'flex', gap: '0.4rem', flexWrap: 'wrap' }}>{selected.channels.length ? selected.channels.map((channel) => <span className="badge badge-secondary" key={channel.id}><Radio size={12} /> {channel.name}</span>) : <span style={{ color: 'var(--text-muted)' }}>Sin canales registrados</span>}</div>
                  </section>
                  <section style={{ marginBottom: '1.25rem' }}>
                    <h4 style={{ margin: '0 0 0.55rem', fontSize: '0.95rem' }}>Variables capturadas ({selected.variable_count})</h4>
                    {Object.keys(selected.variables).length ? <div style={{ display: 'grid', gap: '0.4rem' }}>{Object.entries(selected.variables).map(([key, value]) => <div key={key} style={{ display: 'flex', justifyContent: 'space-between', gap: '1rem', padding: '0.5rem 0.6rem', borderRadius: '7px', background: 'var(--surface-subtle, #f4f7f5)', fontSize: '0.85rem' }}><strong>{key}</strong><span style={{ textAlign: 'right', overflowWrap: 'anywhere' }}>{formatValue(value)}</span></div>)}</div> : <span style={{ color: 'var(--text-muted)' }}>No hay variables capturadas.</span>}
                  </section>
                  <section style={{ marginBottom: '1.25rem' }}>
                    <h4 style={{ margin: '0 0 0.55rem', fontSize: '0.95rem' }}>Conversaciones ({selected.conversations?.length ?? selected.conversations_count})</h4>
                    {selected.conversations?.length ? <div style={{ display: 'grid', gap: '0.45rem' }}>{selected.conversations.map((conversation) => <div key={conversation.id} style={{ padding: '0.55rem 0.6rem', border: '1px solid var(--border)', borderRadius: '7px', fontSize: '0.85rem' }}><strong>{conversation.channel?.name || 'Canal no disponible'}</strong><span style={{ color: 'var(--text-muted)', marginLeft: '0.5rem' }}>{statusLabel[conversation.status] || conversation.status}</span><small style={{ display: 'block', color: 'var(--text-muted)', marginTop: '0.2rem' }}>{formatDate(conversation.last_activity_at)}</small></div>)}</div> : <span style={{ color: 'var(--text-muted)' }}>No hay conversaciones disponibles.</span>}
                  </section>
                  {Object.keys(selected.attributes).length > 0 && <section><h4 style={{ margin: '0 0 0.55rem', fontSize: '0.95rem' }}>Datos adicionales</h4><div style={{ display: 'grid', gap: '0.4rem' }}>{Object.entries(selected.attributes).map(([key, value]) => <div key={key} style={{ display: 'flex', justifyContent: 'space-between', gap: '1rem', fontSize: '0.85rem' }}><span style={{ color: 'var(--text-muted)' }}>{key}</span><span style={{ textAlign: 'right', overflowWrap: 'anywhere' }}>{formatValue(value)}</span></div>)}</div></section>}
                </>
              )}
            </div>
          </div>
        </div>
      )}
    </>
  );
};
