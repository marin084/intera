import React, { useEffect, useState } from 'react';
import { AlertCircle, BellRing, CheckCircle2, Loader2, Send, ShieldCheck, Trash2 } from 'lucide-react';
import { api } from '../services/api';

export const AdminSettingsView: React.FC = () => {
  const [configured, setConfigured] = useState(false);
  const [webhookUrl, setWebhookUrl] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [clearing, setClearing] = useState(false);
  const [confirmClear, setConfirmClear] = useState(false);
  const [clearConfirmationText, setClearConfirmationText] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const loadSettings = async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await api.getDiscordWebhookSettings();
      setConfigured(result.data.discord_webhook_configured);
    } catch (err: any) {
      setError(err.message || 'No se pudo cargar la configuración.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadSettings();
  }, []);

  const handleSave = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setSuccess(null);
    if (!webhookUrl.trim()) {
      setError('Pega una URL de webhook nueva para guardarla.');
      return;
    }

    setSaving(true);
    try {
      const result = await api.saveDiscordWebhook(webhookUrl.trim());
      setConfigured(result.data.discord_webhook_configured);
      setWebhookUrl('');
      setSuccess(result.message);
      setConfirmClear(false);
    } catch (err: any) {
      setError(err.data?.errors?.webhook_url?.[0] || err.message || 'No se pudo guardar el webhook.');
    } finally {
      setSaving(false);
    }
  };

  const handleTest = async () => {
    setError(null);
    setSuccess(null);
    setTesting(true);
    try {
      const result = await api.sendDiscordWebhookTest();
      setSuccess(result.message);
    } catch (err: any) {
      setError(err.message || 'No se pudo enviar la notificación de prueba.');
    } finally {
      setTesting(false);
    }
  };

  const handleClear = async () => {
    if (clearConfirmationText.trim() !== 'Discord') return;
    setError(null);
    setSuccess(null);
    setClearing(true);
    try {
      const result = await api.clearDiscordWebhook();
      setConfigured(result.data.discord_webhook_configured);
      setConfirmClear(false);
      setClearConfirmationText('');
      setSuccess(result.message);
    } catch (err: any) {
      setError(err.message || 'No se pudo eliminar el webhook.');
    } finally {
      setClearing(false);
    }
  };

  return (
    <div>
      <section className="card">
        <div className="card-header" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            <BellRing size={22} color="#5865f2" />
            <div>
              <h2 className="card-title">Notificaciones de Discord</h2>
              <p style={{ margin: '0.2rem 0 0', color: 'var(--text-muted)', fontSize: '0.85rem' }}>
                Recibe avisos operativos de Meta y WhatsApp en un canal de Discord.
              </p>
            </div>
          </div>
          <span className={`badge ${configured ? 'badge-success' : 'badge-warning'}`}>
            {loading ? 'Consultando…' : configured ? 'Webhook configurado' : 'Pendiente'}
          </span>
        </div>

        <div style={{ padding: '1.25rem' }}>
          {error && (
            <div role="alert" className="alert alert-danger" style={{ marginBottom: '1rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <AlertCircle size={18} /> {error}
            </div>
          )}
          {success && (
            <div role="status" className="alert alert-success" style={{ marginBottom: '1rem', display: 'flex', alignItems: 'center', gap: '0.5rem', background: '#eaf1ee', border: '1px solid #a9c8bb', color: '#263c38' }}>
              <CheckCircle2 size={18} /> {success}
            </div>
          )}

          <form onSubmit={handleSave}>
            <label htmlFor="discord-webhook-url" className="form-label">URL del webhook</label>
            <input
              id="discord-webhook-url"
              type="password"
              className="form-input"
              value={webhookUrl}
              onChange={(event) => setWebhookUrl(event.target.value)}
              autoComplete="new-password"
              spellCheck={false}
              placeholder={configured ? 'Configurado — pega una URL nueva para reemplazarlo' : 'https://discord.com/api/webhooks/…'}
              disabled={loading || saving}
            />
            <p style={{ margin: '0.5rem 0 1rem', color: 'var(--text-muted)', fontSize: '0.82rem', display: 'flex', alignItems: 'flex-start', gap: '0.4rem' }}>
              <ShieldCheck size={16} style={{ flexShrink: 0, marginTop: 2 }} />
              La URL se cifra en la base de datos con la llave de Intera. Nunca se muestra de nuevo ni se incluye en los logs. Solo administradores pueden cambiarla.
            </p>

            <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '0.6rem' }}>
              <button type="submit" className="btn btn-primary" disabled={loading || saving || !webhookUrl.trim()}>
                {saving ? <Loader2 size={16} className="animate-spin" /> : <CheckCircle2 size={16} />}
                Guardar webhook
              </button>
              <button type="button" className="btn btn-secondary" onClick={handleTest} disabled={loading || testing || !configured}>
                {testing ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
                Enviar prueba
              </button>
              {configured && !confirmClear && (
                <button type="button" className="btn btn-secondary" onClick={() => { setClearConfirmationText(''); setConfirmClear(true); }} disabled={loading || clearing}>
                  <Trash2 size={16} /> Quitar webhook
                </button>
              )}
              {confirmClear && (
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                  <label htmlFor="clear-discord-webhook-confirm" style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>
                    Escribe <strong>Discord</strong> para quitar la conexión:
                  </label>
                  <input
                    id="clear-discord-webhook-confirm"
                    type="text"
                    className="form-input"
                    value={clearConfirmationText}
                    onChange={(event) => setClearConfirmationText(event.target.value)}
                    autoComplete="off"
                    style={{ maxWidth: '12rem' }}
                    disabled={clearing}
                  />
                  <button type="button" className="btn btn-danger btn-sm" onClick={handleClear} disabled={clearing || clearConfirmationText.trim() !== 'Discord'}>
                    {clearing ? 'Eliminando…' : 'Confirmar'}
                  </button>
                  <button type="button" className="btn btn-secondary btn-sm" onClick={() => { setConfirmClear(false); setClearConfirmationText(''); }} disabled={clearing}>Cancelar</button>
                </span>
              )}
            </div>
          </form>

          <div style={{ marginTop: '1.25rem', padding: '0.9rem 1rem', borderRadius: '0.6rem', background: 'var(--surface-muted, #f4f7f5)', color: 'var(--text-muted)', fontSize: '0.82rem' }}>
            En Discord: abre el canal deseado, entra en <strong>Editar canal → Integraciones → Webhooks</strong>, crea el webhook y copia su URL. No compartas esa URL: quien la tenga puede publicar en ese canal.
          </div>
        </div>
      </section>
    </div>
  );
};
