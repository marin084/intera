import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import type { Channel, Flow } from '../types';
import {
  Radio,
  Plus,
  CheckCircle,
  XCircle,
  Info,
  GitBranch,
  AlertTriangle,
  Edit2,
  Copy,
  Check,
  Eye,
  RefreshCw,
  Code,
  Globe,
  Sliders,
  MessageSquare,
  ExternalLink,
  Trash2,
  PowerOff,
  Send,
  MessageCircle,
  Camera,
} from 'lucide-react';
import { ConfirmModal } from '../components/common/ConfirmModal';
import { DiscardChangesPanel } from '../components/common/DiscardChangesPanel';
import { useAuth } from '../context/useAuth';

export const ChannelsView: React.FC = () => {
  const { hasPermission } = useAuth();
  const [channels, setChannels] = useState<Channel[]>([]);
  const [flows, setFlows] = useState<Flow[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editingChannel, setEditingChannel] = useState<Channel | null>(null);
  const [channelFormDirty, setChannelFormDirty] = useState(false);
  const [confirmDiscardChannelForm, setConfirmDiscardChannelForm] = useState(false);

  // Common Form states
  const [name, setName] = useState('');
  const [channelType, setChannelType] = useState<'webchat' | 'whatsapp' | 'facebook_messenger' | 'instagram' | 'telegram'>('webchat');
  const [entryFlowId, setEntryFlowId] = useState<string>('');
  const [welcomeMessage, setWelcomeMessage] = useState('¡Hola! ¿En qué podemos ayudarte?');
  const [submitting, setSubmitting] = useState(false);

  // Webchat specific states
  const [displayName, setDisplayName] = useState('Asistente Virtual');
  const [allowedOrigins, setAllowedOrigins] = useState('http://localhost:3000\nhttp://localhost:5173\nhttp://localhost:8080');
  const [accentColor, setAccentColor] = useState('#5d8f82');
  const [launcherPosition, setLauncherPosition] = useState<'bottom-right' | 'bottom-left'>('bottom-right');

  // WhatsApp & Meta specific states
  const [phoneNumberId, setPhoneNumberId] = useState('');
  const [businessAccountId, setBusinessAccountId] = useState('');
  const [metaAppId, setMetaAppId] = useState('');
  const [accessToken, setAccessToken] = useState('');
  const [verifyToken, setVerifyToken] = useState('');
  const [appSecret, setAppSecret] = useState('');
  const [apiVersion, setApiVersion] = useState('v27.0');

  // Facebook Messenger & Instagram specific states
  const [pageId, setPageId] = useState('');
  const [instagramAccountId, setInstagramAccountId] = useState('');

  // Telegram specific states
  const [botToken, setBotToken] = useState('');
  const [botUsername, setBotUsername] = useState('');
  const [webhookSecretToken, setWebhookSecretToken] = useState('');
  const [telegramSettingWebhook, setTelegramSettingWebhook] = useState(false);
  const [telegramWebhookFeedback, setTelegramWebhookFeedback] = useState<{ ok: boolean; msg: string } | null>(null);

  // Inactivity auto-close states
  const [inactivityEnabled, setInactivityEnabled] = useState(true);
  const [inactivityWarningEnabled, setInactivityWarningEnabled] = useState(true);
  const [inactivityWarningMinutes, setInactivityWarningMinutes] = useState(10);
  const [inactivityWarningMessage, setInactivityWarningMessage] = useState(
    'Hola {{nombre}}, notamos que no has respondido en los últimos minutos. ¿Sigues por aquí? Si no recibes o envías respuesta pronto, daremos por finalizada la sesión.'
  );
  const [inactivityCloseMinutes, setInactivityCloseMinutes] = useState(15);
  const [inactivityCloseMessage, setInactivityCloseMessage] = useState(
    'Por inactividad hemos dado por finalizada esta conversación. ¡Cuando gustes, vuelve a escribirnos!'
  );

  // UI state for copy feedback & modals
  const [copiedChannelId, setCopiedChannelId] = useState<string | null>(null);
  const [previewChannel, setPreviewChannel] = useState<Channel | null>(null);
  const [confirmRegenChannel, setConfirmRegenChannel] = useState<Channel | null>(null);
  const [metaWebhookChannel, setMetaWebhookChannel] = useState<Channel | null>(null);
  const [copiedWebhookUrl, setCopiedWebhookUrl] = useState(false);
  const [copiedVerifyToken, setCopiedVerifyToken] = useState(false);
  const [regenerating, setRegenerating] = useState(false);

  // Confirm Modal state for Channel Actions (toggle_active | delete)
  const [confirmModalState, setConfirmModalState] = useState<{
    isOpen: boolean;
    channel: Channel | null;
    type: 'toggle_active' | 'delete';
    isSubmitting: boolean;
  }>({
    isOpen: false,
    channel: null,
    type: 'toggle_active',
    isSubmitting: false,
  });
  const [actionSuccessMessage, setActionSuccessMessage] = useState<string | null>(null);
  const [undoChannelStatus, setUndoChannelStatus] = useState<{ channel: Channel; restoreActive: boolean } | null>(null);
  const [actionErrorMessage, setActionErrorMessage] = useState<string | null>(null);
  const [formErrorMessage, setFormErrorMessage] = useState<string | null>(null);

  const discardChannelForm = () => {
    setConfirmDiscardChannelForm(false);
    setChannelFormDirty(false);
    setShowCreateModal(false);
    setEditingChannel(null);
    setFormErrorMessage(null);
  };

  const requestCloseChannelForm = () => {
    if (submitting) return;
    if (channelFormDirty) setConfirmDiscardChannelForm(true);
    else discardChannelForm();
  };

  const handleUndoChannelStatus = async () => {
    if (!undoChannelStatus) return;
    try {
      await api.updateChannelStatus(undoChannelStatus.channel.id, undoChannelStatus.restoreActive);
      setActionSuccessMessage(`Se deshizo el cambio de estado de «${undoChannelStatus.channel.name}».`);
      setUndoChannelStatus(null);
      await fetchChannelsAndFlows();
    } catch (err: any) {
      setActionErrorMessage(err.message || 'No se pudo deshacer el cambio de estado del canal.');
    }
  };

  useEffect(() => {
    if (actionErrorMessage) {
      const timer = setTimeout(() => {
        setActionErrorMessage(null);
      }, 5000);
      return () => clearTimeout(timer);
    }
  }, [actionErrorMessage]);

  useEffect(() => {
    if (!undoChannelStatus) return;
    const timeout = window.setTimeout(() => setUndoChannelStatus(null), 6000);
    return () => window.clearTimeout(timeout);
  }, [undoChannelStatus]);

  const generateSecureToken = (prefix: string, byteLength: number) => {
    const bytes = new Uint8Array(byteLength);
    window.crypto.getRandomValues(bytes);
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${prefix}${hex}`;
  };

  const generateRandomVerifyToken = () => {
    setVerifyToken(generateSecureToken('conexa_meta_', 24));
  };

  const generateRandomTelegramSecret = () => {
    setWebhookSecretToken(generateSecureToken('conexa_tg_', 32));
  };

  const fetchChannelsAndFlows = React.useCallback(async () => {
    try {
      const [channelsRes, flowsRes] = await Promise.all([
        api.getChannels(),
        hasPermission('flows.view') ? api.getFlows().catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
      ]);
      setChannels(channelsRes.data);
      setFlows(flowsRes.data);
    } catch (err: any) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  }, [hasPermission]);

  useEffect(() => {
    let isMounted = true;
    Promise.all([
      api.getChannels(),
      hasPermission('flows.view') ? api.getFlows().catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
    ])
      .then(([channelsRes, flowsRes]) => {
        if (isMounted) {
          setChannels(channelsRes.data);
          setFlows(flowsRes.data);
        }
      })
      .catch((err) => console.error(err))
      .finally(() => {
        if (isMounted) setLoading(false);
      });

    return () => {
      isMounted = false;
    };
  }, [hasPermission]);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return;
      if (showCreateModal || editingChannel) requestCloseChannelForm();
      setPreviewChannel(null);
      setConfirmRegenChannel(null);
      setMetaWebhookChannel(null);
      setConfirmModalState((prev) => ({ ...prev, isOpen: false, channel: null }));
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [showCreateModal, editingChannel, channelFormDirty, submitting]);

  const getCompatiblePublishedFlows = (type?: string) => {
    return flows.filter((f) => {
      if (f.status !== 'published' || !f.active_version_number) {
        return false;
      }
      const applicability = f.channel_applicability && f.channel_applicability.length > 0
        ? f.channel_applicability
        : ['webchat'];
      return type ? applicability.includes(type) : true;
    });
  };

  const getEmbedCode = (publicKey?: string) => {
    if (!publicKey) return '';
    const origin = window.location.origin;
    return `<script\n  src="${origin}/widget/conexa.js"\n  data-channel="${publicKey}"\n  async>\n</script>`;
  };

  const handleCopyCode = async (channelId: string, publicKey?: string) => {
    if (!publicKey) return;
    try {
      const code = getEmbedCode(publicKey);
      await navigator.clipboard.writeText(code);
      setCopiedChannelId(channelId);
      setTimeout(() => {
        setCopiedChannelId(null);
      }, 2500);
    } catch {
      prompt('Copie el código de instalación manual:', getEmbedCode(publicKey));
    }
  };

  const handleCopyText = async (text: string, type: 'url' | 'token') => {
    try {
      await navigator.clipboard.writeText(text);
      if (type === 'url') {
        setCopiedWebhookUrl(true);
        setTimeout(() => setCopiedWebhookUrl(false), 2000);
      } else {
        setCopiedVerifyToken(true);
        setTimeout(() => setCopiedVerifyToken(false), 2000);
      }
    } catch {
      prompt('Copie el valor:', text);
    }
  };

  const handleSetupTelegramWebhook = async (channelId: string) => {
    setTelegramSettingWebhook(true);
    setTelegramWebhookFeedback(null);
    try {
      const res = await api.setupTelegramWebhook(channelId);
      setTelegramWebhookFeedback({ ok: true, msg: res.message || 'Webhook configurado exitosamente en Telegram.' });
    } catch (err: any) {
      setTelegramWebhookFeedback({ ok: false, msg: err.message || 'Error al configurar el webhook en Telegram.' });
    } finally {
      setTelegramSettingWebhook(false);
    }
  };

  const handleOpenCreate = () => {
    setName('');
    setChannelType('webchat');
    setEntryFlowId('');
    setWelcomeMessage('¡Hola! ¿En qué podemos ayudarte?');
    setDisplayName('Asistente Virtual');
    const defaultOrigins = [window.location.origin, 'http://localhost:3000', 'http://localhost:5173', 'http://localhost:8080'];
    setAllowedOrigins(Array.from(new Set(defaultOrigins)).join('\n'));
    setAccentColor('#5d8f82');
    setLauncherPosition('bottom-right');

    setPhoneNumberId('');
    setBusinessAccountId('');
    setMetaAppId('');
    setAccessToken('');
    generateRandomVerifyToken();
    setAppSecret('');
    setApiVersion('v27.0');

    setPageId('');
    setInstagramAccountId('');
    setBotToken('');
    setBotUsername('');
    generateRandomTelegramSecret();
    setTelegramWebhookFeedback(null);

    setInactivityEnabled(true);
    setInactivityWarningEnabled(true);
    setInactivityWarningMinutes(10);
    setInactivityWarningMessage(
      'Hola {{nombre}}, notamos que no has respondido en los últimos minutos. ¿Sigues por aquí? Si no recibes o envías respuesta pronto, daremos por finalizada la sesión.'
    );
    setInactivityCloseMinutes(15);
    setInactivityCloseMessage(
      'Por inactividad hemos dado por finalizada esta conversación. ¡Cuando gustes, vuelve a escribirnos!'
    );

    setShowCreateModal(true);
    setChannelFormDirty(false);
    setConfirmDiscardChannelForm(false);
  };

  const handleOpenEdit = (channel: Channel) => {
    setEditingChannel(channel);
    setChannelFormDirty(false);
    setConfirmDiscardChannelForm(false);
    setName(channel.name);
    setChannelType((channel.type as any) || 'webchat');
    setEntryFlowId(channel.entry_flow_id || '');
    const config = (channel.configuration || {}) as any;

    setInactivityEnabled(config.inactivity_enabled !== undefined ? Boolean(config.inactivity_enabled) : true);
    setInactivityWarningEnabled(config.inactivity_warning_enabled !== undefined ? Boolean(config.inactivity_warning_enabled) : true);
    setInactivityWarningMinutes(
      config.inactivity_warning_minutes !== undefined
        ? Number(config.inactivity_warning_minutes)
        : (channel.type === 'webchat' ? 10 : 15)
    );
    setInactivityWarningMessage(
      config.inactivity_warning_message ||
        'Hola {{nombre}}, notamos que no has respondido en los últimos minutos. ¿Sigues por aquí? Si no recibes o envías respuesta pronto, daremos por finalizada la sesión.'
    );
    setInactivityCloseMinutes(
      config.inactivity_close_minutes !== undefined
        ? Number(config.inactivity_close_minutes)
        : (channel.type === 'webchat' ? 15 : 30)
    );
    setInactivityCloseMessage(
      config.inactivity_close_message ||
        'Por inactividad hemos dado por finalizada esta conversación. ¡Cuando gustes, vuelve a escribirnos!'
    );

    if (channel.type === 'whatsapp') {
      setPhoneNumberId(config.phone_number_id || '');
      setBusinessAccountId(config.business_account_id || '');
      setMetaAppId(config.app_id || '');
      setAccessToken(''); // Blank by default, preserved if left blank
      setVerifyToken(''); // Blank by default, preserved if left blank
      setAppSecret(''); // Blank by default, preserved if left blank
      setApiVersion(config.api_version || 'v27.0');
    } else if (channelType === 'facebook_messenger' || channel.type === 'facebook_messenger') {
      setPageId(config.page_id || '');
      setMetaAppId(config.app_id || '');
      setAccessToken('');
      setVerifyToken('');
      setAppSecret('');
      setApiVersion(config.api_version || 'v27.0');
    } else if (channelType === 'instagram' || channel.type === 'instagram') {
      setInstagramAccountId(config.instagram_account_id || config.page_id || '');
      setPageId(config.page_id || '');
      setMetaAppId(config.app_id || '');
      setAccessToken('');
      setVerifyToken('');
      setAppSecret('');
      setApiVersion(config.api_version || 'v27.0');
    } else if (channelType === 'telegram' || channel.type === 'telegram') {
      setBotUsername(config.bot_username || '');
      setBotToken('');
      setWebhookSecretToken('');
      setTelegramWebhookFeedback(null);
    } else {
      setWelcomeMessage(config?.welcome_message || '¡Hola! ¿En qué podemos ayudarte?');
      const ws = channel.webchat_settings;
      setDisplayName(ws?.display_name || channel.name);
      const origins = ws?.allowed_origins && ws.allowed_origins.length > 0
        ? ws.allowed_origins.join('\n')
        : [window.location.origin, 'http://localhost:3000'].join('\n');
      setAllowedOrigins(origins);
      setAccentColor(ws?.accent_color || '#5d8f82');
      setLauncherPosition((ws?.launcher_position as any) || 'bottom-right');
    }
  };

  const parseOrigins = (text: string): string[] => {
    return text
      .split('\n')
      .map((line) => line.trim())
      .filter((line) => line.length > 0 && (line.startsWith('http://') || line.startsWith('https://')))
      .map((line) => {
        try {
          const url = new URL(line);
          return url.origin;
        } catch {
          return line.replace(/\/+$/, '');
        }
      });
  };

  const handleCreateChannel = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);

    if (channelType === 'webchat') {
      const origins = parseOrigins(allowedOrigins);
      if (origins.length === 0) {
        setFormErrorMessage('Debe especificar al menos un origen permitido válido (ej: http://localhost:3000 o https://midominio.com)');
        setSubmitting(false);
        return;
      }

      try {
        await api.createChannel({
          name,
          type: 'webchat',
          is_active: true,
          entry_flow_id: entryFlowId ? entryFlowId : null,
          configuration: {
            welcome_message: welcomeMessage,
            theme_color: accentColor,
            inactivity_enabled: inactivityEnabled,
            inactivity_warning_enabled: inactivityWarningEnabled,
            inactivity_warning_minutes: Number(inactivityWarningMinutes),
            inactivity_warning_message: inactivityWarningMessage,
            inactivity_close_minutes: Number(inactivityCloseMinutes),
            inactivity_close_message: inactivityCloseMessage,
          },
          webchat_settings: {
            display_name: displayName,
            allowed_origins: origins,
            accent_color: accentColor,
            launcher_position: launcherPosition,
          },
        });
        setShowCreateModal(false);
        fetchChannelsAndFlows();
      } catch (err: any) {
        setFormErrorMessage(err.message || 'Error al crear canal Webchat.');
      } finally {
        setSubmitting(false);
      }
    } else if (channelType === 'facebook_messenger') {
      if (!pageId.trim()) {
        setFormErrorMessage('El Page ID de Facebook es obligatorio.');
        setSubmitting(false);
        return;
      }
      if (!accessToken.trim()) {
        setFormErrorMessage('El Page Access Token es obligatorio.');
        setSubmitting(false);
        return;
      }
      if (!verifyToken.trim()) {
        setFormErrorMessage('El Webhook Verify Token es obligatorio.');
        setSubmitting(false);
        return;
      }
      if (!appSecret.trim()) {
        setFormErrorMessage('El App Secret de Meta es obligatorio para validar firmas HMAC.');
        setSubmitting(false);
        return;
      }

      try {
        await api.createChannel({
          name,
          type: 'facebook_messenger',
          is_active: true,
          entry_flow_id: entryFlowId ? entryFlowId : null,
          configuration: {
            page_id: pageId.trim(),
            app_id: metaAppId.trim() || null,
            access_token: accessToken.trim(),
            verify_token: verifyToken.trim(),
            app_secret: appSecret.trim(),
            api_version: apiVersion.trim() || 'v27.0',
            inactivity_enabled: inactivityEnabled,
            inactivity_warning_enabled: inactivityWarningEnabled,
            inactivity_warning_minutes: Number(inactivityWarningMinutes),
            inactivity_warning_message: inactivityWarningMessage,
            inactivity_close_minutes: Number(inactivityCloseMinutes),
            inactivity_close_message: inactivityCloseMessage,
          },
        });
        setShowCreateModal(false);
        fetchChannelsAndFlows();
      } catch (err: any) {
        setFormErrorMessage(err.message || 'Error al crear canal Facebook Messenger.');
      } finally {
        setSubmitting(false);
      }
    } else if (channelType === 'instagram') {
      if (!instagramAccountId.trim()) {
        setFormErrorMessage('El Instagram Account ID es obligatorio.');
        setSubmitting(false);
        return;
      }
      if (!pageId.trim()) {
        setFormErrorMessage('El Facebook Page ID vinculado es obligatorio para Instagram Direct.');
        setSubmitting(false);
        return;
      }
      if (!accessToken.trim()) {
        setFormErrorMessage('El Access Token de Meta/Instagram es obligatorio.');
        setSubmitting(false);
        return;
      }
      if (!verifyToken.trim()) {
        setFormErrorMessage('El Webhook Verify Token es obligatorio.');
        setSubmitting(false);
        return;
      }
      if (!appSecret.trim()) {
        setFormErrorMessage('El App Secret de Meta es obligatorio para validar firmas HMAC.');
        setSubmitting(false);
        return;
      }

      try {
        await api.createChannel({
          name,
          type: 'instagram',
          is_active: true,
          entry_flow_id: entryFlowId ? entryFlowId : null,
          configuration: {
            instagram_account_id: instagramAccountId.trim(),
            page_id: pageId.trim(),
            app_id: metaAppId.trim() || null,
            access_token: accessToken.trim(),
            verify_token: verifyToken.trim(),
            app_secret: appSecret.trim(),
            api_version: apiVersion.trim() || 'v27.0',
            inactivity_enabled: inactivityEnabled,
            inactivity_warning_enabled: inactivityWarningEnabled,
            inactivity_warning_minutes: Number(inactivityWarningMinutes),
            inactivity_warning_message: inactivityWarningMessage,
            inactivity_close_minutes: Number(inactivityCloseMinutes),
            inactivity_close_message: inactivityCloseMessage,
          },
        });
        setShowCreateModal(false);
        fetchChannelsAndFlows();
      } catch (err: any) {
        setFormErrorMessage(err.message || 'Error al crear canal Instagram.');
      } finally {
        setSubmitting(false);
      }
    } else if (channelType === 'telegram') {
      if (!botToken.trim()) {
        setFormErrorMessage('El Bot Token de Telegram (@BotFather) es obligatorio.');
        setSubmitting(false);
        return;
      }

      try {
        await api.createChannel({
          name,
          type: 'telegram',
          is_active: true,
          entry_flow_id: entryFlowId ? entryFlowId : null,
          configuration: {
            bot_token: botToken.trim(),
            bot_username: botUsername.trim() || null,
            webhook_secret_token: webhookSecretToken.trim() || null,
            inactivity_enabled: inactivityEnabled,
            inactivity_warning_enabled: inactivityWarningEnabled,
            inactivity_warning_minutes: Number(inactivityWarningMinutes),
            inactivity_warning_message: inactivityWarningMessage,
            inactivity_close_minutes: Number(inactivityCloseMinutes),
            inactivity_close_message: inactivityCloseMessage,
          },
        });
        setShowCreateModal(false);
        fetchChannelsAndFlows();
      } catch (err: any) {
        setFormErrorMessage(err.message || 'Error al crear canal Telegram.');
      } finally {
        setSubmitting(false);
      }
    } else {
      // WhatsApp validation
      if (!phoneNumberId.trim()) {
        setFormErrorMessage('El Phone Number ID de WhatsApp es obligatorio.');
        setSubmitting(false);
        return;
      }
      if (!/^\d+$/.test(metaAppId.trim())) {
        setFormErrorMessage('El App ID de Meta es obligatorio y debe contener solo números.');
        setSubmitting(false);
        return;
      }
      if (!accessToken.trim()) {
        setFormErrorMessage('El Permanent Access Token de Meta es obligatorio.');
        setSubmitting(false);
        return;
      }
      if (!verifyToken.trim()) {
        setFormErrorMessage('El Webhook Verify Token es obligatorio.');
        setSubmitting(false);
        return;
      }
      if (!appSecret.trim()) {
        setFormErrorMessage('El App Secret de Meta es obligatorio para validar firmas criptográficas HMAC.');
        setSubmitting(false);
        return;
      }

      try {
        await api.createChannel({
          name,
          type: 'whatsapp',
          is_active: true,
          entry_flow_id: entryFlowId ? entryFlowId : null,
          configuration: {
            phone_number_id: phoneNumberId.trim(),
            business_account_id: businessAccountId.trim() || null,
            app_id: metaAppId.trim(),
            access_token: accessToken.trim(),
            verify_token: verifyToken.trim(),
            app_secret: appSecret.trim(),
            api_version: apiVersion.trim() || 'v27.0',
            inactivity_enabled: inactivityEnabled,
            inactivity_warning_enabled: inactivityWarningEnabled,
            inactivity_warning_minutes: Number(inactivityWarningMinutes),
            inactivity_warning_message: inactivityWarningMessage,
            inactivity_close_minutes: Number(inactivityCloseMinutes),
            inactivity_close_message: inactivityCloseMessage,
          },
        });
        setShowCreateModal(false);
        fetchChannelsAndFlows();
      } catch (err: any) {
        setFormErrorMessage(err.message || 'Error al crear canal WhatsApp.');
      } finally {
        setSubmitting(false);
      }
    }
  };

  const handleUpdateChannel = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingChannel) return;
    setSubmitting(true);

    if (editingChannel.type === 'whatsapp') {
      if (!/^\d+$/.test(metaAppId.trim())) {
        setFormErrorMessage('El App ID de Meta es obligatorio y debe contener solo números.');
        setSubmitting(false);
        return;
      }

      try {
        const updatePayload: Record<string, any> = {
          name,
          entry_flow_id: entryFlowId ? entryFlowId : null,
          configuration: {
            phone_number_id: phoneNumberId.trim(),
            business_account_id: businessAccountId.trim() || null,
            app_id: metaAppId.trim(),
            api_version: apiVersion.trim() || 'v27.0',
            inactivity_enabled: inactivityEnabled,
            inactivity_warning_enabled: inactivityWarningEnabled,
            inactivity_warning_minutes: Number(inactivityWarningMinutes),
            inactivity_warning_message: inactivityWarningMessage,
            inactivity_close_minutes: Number(inactivityCloseMinutes),
            inactivity_close_message: inactivityCloseMessage,
          },
        };

        if (accessToken.trim()) {
          updatePayload.configuration.access_token = accessToken.trim();
        }
        if (verifyToken.trim()) {
          updatePayload.configuration.verify_token = verifyToken.trim();
        }
        if (appSecret.trim()) {
          updatePayload.configuration.app_secret = appSecret.trim();
        }

        await api.updateChannel(editingChannel.id, updatePayload);
        setEditingChannel(null);
        fetchChannelsAndFlows();
      } catch (err: any) {
        setFormErrorMessage(err.message || 'Error al actualizar canal WhatsApp.');
      } finally {
        setSubmitting(false);
      }
    } else if (editingChannel.type === 'facebook_messenger') {
      try {
        const updatePayload: Record<string, any> = {
          name,
          entry_flow_id: entryFlowId ? entryFlowId : null,
          configuration: {
            page_id: pageId.trim(),
            app_id: metaAppId.trim() || null,
            api_version: apiVersion.trim() || 'v27.0',
            inactivity_enabled: inactivityEnabled,
            inactivity_warning_enabled: inactivityWarningEnabled,
            inactivity_warning_minutes: Number(inactivityWarningMinutes),
            inactivity_warning_message: inactivityWarningMessage,
            inactivity_close_minutes: Number(inactivityCloseMinutes),
            inactivity_close_message: inactivityCloseMessage,
          },
        };

        if (accessToken.trim()) {
          updatePayload.configuration.access_token = accessToken.trim();
        }
        if (verifyToken.trim()) {
          updatePayload.configuration.verify_token = verifyToken.trim();
        }
        if (appSecret.trim()) {
          updatePayload.configuration.app_secret = appSecret.trim();
        }

        await api.updateChannel(editingChannel.id, updatePayload);
        setEditingChannel(null);
        fetchChannelsAndFlows();
      } catch (err: any) {
        setFormErrorMessage(err.message || 'Error al actualizar canal Facebook Messenger.');
      } finally {
        setSubmitting(false);
      }
    } else if (editingChannel.type === 'instagram') {
      if (!pageId.trim()) {
        setFormErrorMessage('El Facebook Page ID vinculado es obligatorio para Instagram Direct.');
        setSubmitting(false);
        return;
      }

      try {
        const updatePayload: Record<string, any> = {
          name,
          entry_flow_id: entryFlowId ? entryFlowId : null,
          configuration: {
            instagram_account_id: instagramAccountId.trim(),
            page_id: pageId.trim(),
            app_id: metaAppId.trim() || null,
            api_version: apiVersion.trim() || 'v27.0',
            inactivity_enabled: inactivityEnabled,
            inactivity_warning_enabled: inactivityWarningEnabled,
            inactivity_warning_minutes: Number(inactivityWarningMinutes),
            inactivity_warning_message: inactivityWarningMessage,
            inactivity_close_minutes: Number(inactivityCloseMinutes),
            inactivity_close_message: inactivityCloseMessage,
          },
        };

        if (accessToken.trim()) {
          updatePayload.configuration.access_token = accessToken.trim();
        }
        if (verifyToken.trim()) {
          updatePayload.configuration.verify_token = verifyToken.trim();
        }
        if (appSecret.trim()) {
          updatePayload.configuration.app_secret = appSecret.trim();
        }

        await api.updateChannel(editingChannel.id, updatePayload);
        setEditingChannel(null);
        fetchChannelsAndFlows();
      } catch (err: any) {
        setFormErrorMessage(err.message || 'Error al actualizar canal Instagram.');
      } finally {
        setSubmitting(false);
      }
    } else if (editingChannel.type === 'telegram') {
      try {
        const updatePayload: Record<string, any> = {
          name,
          entry_flow_id: entryFlowId ? entryFlowId : null,
          configuration: {
            bot_username: botUsername.trim() || null,
            inactivity_enabled: inactivityEnabled,
            inactivity_warning_enabled: inactivityWarningEnabled,
            inactivity_warning_minutes: Number(inactivityWarningMinutes),
            inactivity_warning_message: inactivityWarningMessage,
            inactivity_close_minutes: Number(inactivityCloseMinutes),
            inactivity_close_message: inactivityCloseMessage,
          },
        };

        if (botToken.trim()) {
          updatePayload.configuration.bot_token = botToken.trim();
        }
        if (webhookSecretToken.trim()) {
          updatePayload.configuration.webhook_secret_token = webhookSecretToken.trim();
        }

        await api.updateChannel(editingChannel.id, updatePayload);
        setEditingChannel(null);
        fetchChannelsAndFlows();
      } catch (err: any) {
        setFormErrorMessage(err.message || 'Error al actualizar canal Telegram.');
      } finally {
        setSubmitting(false);
      }
    } else {
      const origins = parseOrigins(allowedOrigins);
      if (origins.length === 0) {
        setFormErrorMessage('Debe especificar al menos un origen permitido válido (ej: http://localhost:3000 o https://midominio.com)');
        setSubmitting(false);
        return;
      }

      try {
        await api.updateChannel(editingChannel.id, {
          name,
          entry_flow_id: entryFlowId ? entryFlowId : null,
          configuration: {
            welcome_message: welcomeMessage,
            theme_color: accentColor,
            inactivity_enabled: inactivityEnabled,
            inactivity_warning_enabled: inactivityWarningEnabled,
            inactivity_warning_minutes: Number(inactivityWarningMinutes),
            inactivity_warning_message: inactivityWarningMessage,
            inactivity_close_minutes: Number(inactivityCloseMinutes),
            inactivity_close_message: inactivityCloseMessage,
          },
          webchat_settings: {
            display_name: displayName,
            allowed_origins: origins,
            accent_color: accentColor,
            launcher_position: launcherPosition,
          },
        });
        setEditingChannel(null);
        fetchChannelsAndFlows();
      } catch (err: any) {
        setFormErrorMessage(err.message || 'Error al actualizar canal.');
      } finally {
        setSubmitting(false);
      }
    }
  };

  const handleToggleStatus = async (channel: Channel) => {
    if (channel.is_active) {
      setConfirmModalState({
        isOpen: true,
        channel,
        type: 'toggle_active',
        isSubmitting: false,
      });
    } else {
      await executeToggleStatus(channel);
    }
  };

  const executeToggleStatus = async (channel: Channel) => {
    try {
      setConfirmModalState((prev) => ({ ...prev, isSubmitting: true }));
      await api.updateChannelStatus(channel.id, !channel.is_active);
      setActionSuccessMessage(`El canal «${channel.name}» fue ${channel.is_active ? 'desactivado' : 'activado'} correctamente.`);
      setUndoChannelStatus({ channel, restoreActive: channel.is_active });
      window.setTimeout(() => setActionSuccessMessage(null), 6000);
      setConfirmModalState({
        isOpen: false,
        channel: null,
        type: 'toggle_active',
        isSubmitting: false,
      });
      fetchChannelsAndFlows();
    } catch (err: any) {
      setActionErrorMessage(err.message || 'Error al cambiar estado del canal.');
      setConfirmModalState((prev) => ({ ...prev, isOpen: false, channel: null, isSubmitting: false }));
    }
  };

  const handleRegenerateKey = async () => {
    if (!confirmRegenChannel) return;
    setRegenerating(true);
    try {
      await api.regenerateWebchatKey(confirmRegenChannel.id);
      setConfirmRegenChannel(null);
      fetchChannelsAndFlows();
    } catch (err: any) {
      setActionErrorMessage(err.message || 'Error al regenerar clave pública.');
    } finally {
      setRegenerating(false);
    }
  };

  const handleOpenDeleteConfirm = (channel: Channel) => {
    setConfirmModalState({
      isOpen: true,
      channel,
      type: 'delete',
      isSubmitting: false,
    });
  };

  const executeDeleteChannel = async (channel: Channel) => {
    try {
      setConfirmModalState((prev) => ({ ...prev, isSubmitting: true }));
      let res;
      try {
        res = await api.deleteChannel(channel.id, false);
      } catch (err: any) {
        if (err.data?.requires_force) {
          res = await api.deleteChannel(channel.id, true);
        } else {
          throw err;
        }
      }
      setConfirmModalState({
        isOpen: false,
        channel: null,
        type: 'delete',
        isSubmitting: false,
      });
      setActionSuccessMessage(res?.message || 'Canal eliminado exitosamente.');
      setTimeout(() => {
        setActionSuccessMessage(null);
      }, 4000);
      await fetchChannelsAndFlows();
    } catch (err: any) {
      setActionErrorMessage(err.message || 'Error al eliminar el canal.');
      setConfirmModalState((prev) => ({ ...prev, isOpen: false, channel: null, isSubmitting: false }));
    }
  };

  const canManage = hasPermission('channels.manage');
  const activeChannelsWithoutFlow = channels.filter((c) => c.is_active && !c.entry_flow_id);

  const renderInactivityConfigSection = (modalPrefix: string) => (
    <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.25rem', marginTop: '1.25rem' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.75rem' }}>
        <h4 style={{ fontSize: '0.95rem', fontWeight: 600, margin: 0, display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          ⏱️ Auto-cierre por Inactividad
        </h4>
        <label
          htmlFor={`${modalPrefix}_inactivity_enabled`}
          style={{ display: 'inline-flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer', fontSize: '0.85rem', fontWeight: 500 }}
        >
          <input
            id={`${modalPrefix}_inactivity_enabled`}
            type="checkbox"
            checked={inactivityEnabled}
            onChange={(e) => setInactivityEnabled(e.target.checked)}
            style={{ width: '16px', height: '16px', cursor: 'pointer' }}
          />
          <span>Habilitar auto-cierre por inactividad</span>
        </label>
      </div>

      {inactivityEnabled && (
        <div style={{ background: '#f4f7f5', padding: '1rem', borderRadius: '8px', border: '1px solid #d9e3de', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          {/* Fase 1 - Advertencia */}
          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem', flexWrap: 'wrap', gap: '0.5rem' }}>
              <div style={{ fontSize: '0.825rem', fontWeight: 600, color: '#56736b' }}>
                Fase 1 - Advertencia
              </div>
              <label
                htmlFor={`${modalPrefix}_inactivity_warning_enabled`}
                style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer', fontSize: '0.8rem', fontWeight: 500 }}
              >
                <input
                  id={`${modalPrefix}_inactivity_warning_enabled`}
                  type="checkbox"
                  checked={inactivityWarningEnabled}
                  onChange={(e) => setInactivityWarningEnabled(e.target.checked)}
                  style={{ width: '15px', height: '15px', cursor: 'pointer' }}
                />
                <span>Enviar advertencia previa al usuario</span>
              </label>
            </div>

            {inactivityWarningEnabled ? (
              <>
                <div className="form-group" style={{ marginBottom: '0.75rem' }}>
                  <label htmlFor={`${modalPrefix}_inactivity_warning_minutes`} className="form-label" style={{ fontSize: '0.8rem' }}>
                    Tiempo para advertencia (minutos)
                  </label>
                  <input
                    id={`${modalPrefix}_inactivity_warning_minutes`}
                    type="number"
                    min="1"
                    className="form-input"
                    value={inactivityWarningMinutes}
                    onChange={(e) => setInactivityWarningMinutes(Math.max(1, parseInt(e.target.value) || 1))}
                    style={{ width: '120px' }}
                  />
                </div>
                <div className="form-group" style={{ marginBottom: 0 }}>
                  <label htmlFor={`${modalPrefix}_inactivity_warning_message`} className="form-label" style={{ fontSize: '0.8rem' }}>
                    Mensaje de advertencia
                  </label>
                  <textarea
                    id={`${modalPrefix}_inactivity_warning_message`}
                    className="form-input"
                    rows={2}
                    value={inactivityWarningMessage}
                    onChange={(e) => setInactivityWarningMessage(e.target.value)}
                  />
                  <p style={{ fontSize: '0.725rem', color: 'var(--text-muted)', marginTop: '0.25rem', marginBottom: 0 }}>
                    Puedes usar &#123;&#123;nombre&#125;&#125; para personalizar el texto.
                  </p>
                </div>
              </>
            ) : (
              <p style={{ fontSize: '0.775rem', color: '#61726d', fontStyle: 'italic', margin: '0.25rem 0 0 0' }}>
                La conversación se cerrará directamente al cumplirse el tiempo de cierre definitivo sin enviar un aviso previo.
              </p>
            )}
          </div>

          <div style={{ borderTop: '1px dashed #d9e3de' }} />

          {/* Fase 2 - Cierre Definitivo */}
          <div>
            <div style={{ fontSize: '0.825rem', fontWeight: 600, color: '#56736b', marginBottom: '0.5rem' }}>
              Fase 2 - Cierre Definitivo
            </div>
            <div className="form-group" style={{ marginBottom: '0.75rem' }}>
              <label htmlFor={`${modalPrefix}_inactivity_close_minutes`} className="form-label" style={{ fontSize: '0.8rem' }}>
                Tiempo para cierre definitivo (minutos)
              </label>
              <input
                id={`${modalPrefix}_inactivity_close_minutes`}
                type="number"
                min="1"
                className="form-input"
                value={inactivityCloseMinutes}
                onChange={(e) => setInactivityCloseMinutes(Math.max(1, parseInt(e.target.value) || 1))}
                style={{ width: '120px' }}
              />
            </div>
            <div className="form-group" style={{ marginBottom: 0 }}>
              <label htmlFor={`${modalPrefix}_inactivity_close_message`} className="form-label" style={{ fontSize: '0.8rem' }}>
                Mensaje de despedida
              </label>
              <textarea
                id={`${modalPrefix}_inactivity_close_message`}
                className="form-input"
                rows={2}
                value={inactivityCloseMessage}
                onChange={(e) => setInactivityCloseMessage(e.target.value)}
              />
            </div>
          </div>
        </div>
      )}
    </div>
  );

  return (
    <div>
      {actionSuccessMessage && (
        <div data-testid="channel-action-success-alert" className="alert alert-success" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '1rem', background: '#eaf1ee', borderColor: '#a9c8bb', color: '#263c38' }}>
          <CheckCircle size={18} color="#5d8f82" />
          <span>{actionSuccessMessage}</span>
          {undoChannelStatus && (
            <button type="button" className="btn btn-sm btn-secondary" onClick={handleUndoChannelStatus}>Deshacer</button>
          )}
        </div>
      )}

      {actionErrorMessage && (
        <div data-testid="channel-action-error-alert" className="alert alert-danger" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '1rem', background: '#fef2f2', borderColor: '#fca5a5', color: '#991b1b' }}>
          <AlertTriangle size={18} color="#dc2626" />
          <span>{actionErrorMessage}</span>
        </div>
      )}

      <div className="alert alert-info" style={{ display: 'flex', alignItems: 'flex-start', gap: '0.75rem', marginBottom: '1rem' }}>
        <Info size={20} style={{ flexShrink: 0, marginTop: '2px' }} />
        <div>
          <strong>Omnicanalidad Intera:</strong> Conecta canales <strong>Webchat Embebible</strong> para sitios web o el conector oficial de <strong>WhatsApp Cloud API (Meta Graph API)</strong>. Todos los mensajes se procesan a través del motor de flujos en tiempo real y convergen en el Inbox unificado.
        </div>
      </div>

      {activeChannelsWithoutFlow.length > 0 && (
        <div className="alert alert-warning" style={{ display: 'flex', alignItems: 'flex-start', gap: '0.75rem', marginBottom: '1rem', background: '#fffbeb', borderColor: '#fde68a', color: '#92400e' }}>
          <AlertTriangle size={20} style={{ flexShrink: 0, marginTop: '2px', color: '#d97706' }} />
          <div>
            <strong>Advertencia de configuración:</strong> Se detectaron {activeChannelsWithoutFlow.length} canal(es) activo(s) sin flujo inicial configurado. Las conversaciones en estos canales no ejecutarán automatizaciones hasta que les asigne un flujo publicado.
          </div>
        </div>
      )}

      <div className="card">
        <div className="card-header">
          <div>
            <h2 className="card-title">Canales de Comunicación</h2>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.875rem' }}>
              Gestión de conectores omnicanal, webhooks de Meta y snippets de integración web.
            </p>
          </div>
          {canManage && (
            <button className="btn btn-primary" onClick={handleOpenCreate}>
              <Plus size={16} /> Crear Canal
            </button>
          )}
        </div>

        {loading ? (
          <p style={{ color: 'var(--text-muted)' }}>Cargando canales...</p>
        ) : channels.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '3.5rem 1.5rem', color: 'var(--text-muted)' }}>
            <Radio size={44} style={{ opacity: 0.35, marginBottom: '1rem', color: '#5d8f82' }} />
            <h3 style={{ fontSize: '1.1rem', fontWeight: 600, color: '#35564f', marginBottom: '0.4rem' }}>
              No hay canales configurados aún
            </h3>
            <p style={{ maxWidth: '440px', margin: '0 auto 1.5rem', fontSize: '0.875rem', lineHeight: 1.5 }}>
              Crea tu primer canal de Webchat o WhatsApp Cloud API para comenzar a recibir conversaciones automatizadas.
            </p>
            {canManage && (
              <button className="btn btn-primary" onClick={handleOpenCreate}>
                <Plus size={16} /> Crear mi primer Canal
              </button>
            )}
          </div>
        ) : (
          <div className="table-wrapper channels-table-wrapper">
            <table className={`channels-table${canManage ? ' has-actions' : ''}`}>
              <thead>
                <tr>
                  <th>Canal</th>
                  <th>Flujo Inicial</th>
                  <th>Estado</th>
                  <th>Integración / Webhook</th>
                  <th>Fecha</th>
                  {canManage && <th>Acciones</th>}
                </tr>
              </thead>
              <tbody>
                {channels.map((c) => {
                  const ws = c.webchat_settings;
                  const isCopied = copiedChannelId === c.id;

                  const channelTypeLabels: Record<string, string> = {
                    webchat: 'Webchat',
                    whatsapp: 'WhatsApp',
                    facebook_messenger: 'Messenger',
                    instagram: 'Instagram',
                    telegram: 'Telegram',
                  };

                  const renderChannelIcon = () => {
                    const iconSources: Record<string, string> = {
                      whatsapp: '/channels/whatsapp.svg',
                      facebook_messenger: '/channels/messenger.svg',
                      instagram: '/channels/instagram.svg',
                      telegram: '/channels/telegram.svg',
                    };
                    const iconSource = iconSources[c.type];
                    if (iconSource) {
                      return <img className="flow-channel-brand-icon" src={iconSource} alt="" aria-hidden="true" />;
                    }
                    return <Globe size={15} aria-hidden="true" />;
                  };

                  const renderIntegrationAction = () => {
                    if (c.type === 'whatsapp' || c.type === 'facebook_messenger' || c.type === 'instagram' || c.type === 'telegram') {
                      const buttonLabel = c.type === 'telegram' ? 'Webhook Bot' : (c.type === 'whatsapp' ? 'Webhook Meta' : (c.type === 'facebook_messenger' ? 'Webhook Messenger' : 'Webhook Instagram'));
                      return (
                        <button
                          className="btn btn-sm btn-secondary"
                          onClick={() => {
                            setTelegramWebhookFeedback(null);
                            setMetaWebhookChannel(c);
                          }}
                          style={{ fontSize: '0.75rem', display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
                          title="Ver detalles de configuración del Webhook"
                        >
                          <ExternalLink size={12} /> {buttonLabel}
                        </button>
                      );
                    }

                    if (ws) {
                      return (
                        <div style={{ display: 'flex', gap: '0.35rem' }}>
                          <button
                            className="btn btn-sm btn-secondary"
                            onClick={() => handleCopyCode(c.id, ws.public_key)}
                            style={{ fontSize: '0.75rem', display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
                            title="Copiar código <script> del Webchat"
                          >
                            {isCopied ? <Check size={12} color="#5d8f82" /> : <Copy size={12} />}
                            {isCopied ? 'Copiado' : 'Código'}
                          </button>
                          <button
                            className="btn btn-sm btn-secondary"
                            onClick={() => setPreviewChannel(c)}
                            style={{ fontSize: '0.75rem', padding: '0.25rem 0.5rem' }}
                            title="Abrir vista previa del Webchat en vivo"
                          >
                            <Eye size={12} />
                          </button>
                        </div>
                      );
                    }

                    return null;
                  };

                  return (
                    <tr key={c.id}>
                      <td>
                        <div className="channel-name-with-icon" title={channelTypeLabels[c.type] || c.type}>
                          {renderChannelIcon()}
                          <span>{c.name}</span>
                        </div>
                        {ws?.public_key && (
                          <div style={{ marginTop: '0.25rem' }}>
                            <span
                              style={{
                                fontSize: '0.7rem',
                                background: '#eaf1ee',
                                color: '#61726d',
                                padding: '2px 6px',
                                borderRadius: '4px',
                                fontFamily: 'monospace',
                                border: '1px solid #d9e3de',
                              }}
                              title="Clave pública del widget"
                            >
                              key: {ws.public_key}
                            </span>
                          </div>
                        )}
                      </td>
                      <td>
                        {c.entry_flow ? (
                          <span className="badge badge-primary" style={{ display: 'inline-flex', gap: '0.35rem', alignItems: 'center', background: '#ddeae4', color: '#456c61' }}>
                            <GitBranch size={12} /> {c.entry_flow.name} (v{c.entry_flow.active_version_number})
                          </span>
                        ) : c.is_active ? (
                          <span className="badge badge-warning" style={{ display: 'inline-flex', gap: '0.35rem', alignItems: 'center', background: '#fef3c7', color: '#92400e' }}>
                            <AlertTriangle size={12} /> Sin flujo inicial
                          </span>
                        ) : (
                          <span style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>Sin flujo</span>
                        )}
                      </td>
                      <td>
                        {c.is_active ? (
                          <span className="badge badge-success" style={{ display: 'inline-flex', gap: '0.25rem', alignItems: 'center' }}>
                            <CheckCircle size={12} /> Activo
                          </span>
                        ) : (
                          <span className="badge badge-danger" style={{ display: 'inline-flex', gap: '0.25rem', alignItems: 'center' }}>
                            <XCircle size={12} /> Inactivo
                          </span>
                        )}
                      </td>
                      <td>
                        {renderIntegrationAction()}
                      </td>
                      <td style={{ color: 'var(--text-muted)', fontSize: '0.8rem' }}>
                        {new Date(c.created_at).toLocaleDateString()}
                      </td>
                      {canManage && (
                        <td>
                          <div style={{ display: 'flex', gap: '0.35rem' }}>
                            <button
                              className="btn btn-sm btn-secondary"
                              onClick={() => handleOpenEdit(c)}
                              title="Editar configuración del canal"
                            >
                              <Edit2 size={13} />
                            </button>
                            {ws && (
                              <button
                                className="btn btn-sm btn-secondary"
                                onClick={() => setConfirmRegenChannel(c)}
                                title="Regenerar clave pública del Webchat"
                              >
                                <RefreshCw size={13} />
                              </button>
                            )}
                            <button
                              className={`btn btn-sm ${c.is_active ? 'btn-danger' : 'btn-secondary'}`}
                              onClick={() => handleToggleStatus(c)}
                              title={c.is_active ? 'Desactivar este canal temporalmente' : 'Activar este canal'}
                              aria-label={c.is_active ? `Desactivar canal ${c.name}` : `Activar canal ${c.name}`}
                            >
                              {c.is_active ? 'Desactivar' : 'Activar'}
                            </button>
                            <button
                              className="btn btn-sm btn-secondary"
                              onClick={() => handleOpenDeleteConfirm(c)}
                              title="Eliminar este canal"
                              aria-label={`Eliminar canal ${c.name}`}
                              style={{ color: '#ef4444', borderColor: '#fecaca' }}
                            >
                              <Trash2 size={13} />
                            </button>
                          </div>
                        </td>
                      )}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Panel lateral Crear Canal */}
      {showCreateModal && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="create-channel-panel-title"
          className="channel-side-panel-overlay"
          onMouseDown={(event) => { if (event.target === event.currentTarget) requestCloseChannelForm(); }}
        >
          <div
            className="channel-side-panel modal-content"
          >
            <div
              className="shrink-0 border-b border-slate-100 dark:border-slate-800 p-6 flex items-center justify-between modal-header"
              style={{
                flexShrink: 0,
                borderBottom: '1px solid var(--border, #eaf1ee)',
                padding: '1.25rem 1.5rem',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                margin: 0,
              }}
            >
              <h3 id="create-channel-panel-title" style={{ fontSize: '1.15rem', fontWeight: 600, margin: 0 }}>Crear Nuevo Canal</h3>
              <button type="button" className="btn btn-secondary btn-sm" aria-label="Cerrar panel" onClick={requestCloseChannelForm}>✕</button>
            </div>

            <form
              onSubmit={handleCreateChannel}
              onChangeCapture={() => setChannelFormDirty(true)}
              className="flex flex-col flex-1 min-h-0 overflow-hidden"
              style={{ display: 'flex', flexDirection: 'column', flex: 1, minHeight: 0, overflow: 'hidden' }}
            >
              <div
                className="flex-1 overflow-y-auto p-6 space-y-6 overscroll-contain"
                style={{ flex: 1, overflowY: 'auto', padding: '1.5rem', overscrollBehavior: 'contain' }}
              >
                {formErrorMessage && (
                  <div className="alert alert-danger" style={{ marginBottom: '1.25rem', background: '#fef2f2', borderColor: '#fca5a5', color: '#991b1b', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                    <AlertTriangle size={18} color="#dc2626" />
                    <span>{formErrorMessage}</span>
                  </div>
                )}
                {/* Type Selector Tabs */}
              <div style={{ marginBottom: '1.25rem' }}>
                <label className="form-label" style={{ marginBottom: '0.5rem' }}>Tipo de Canal</label>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: '0.5rem' }}>
                  <button
                    type="button"
                    onClick={() => {
                      setChannelType('webchat');
                      setInactivityWarningMinutes(10);
                      setInactivityCloseMinutes(15);
                    }}
                    style={{
                      display: 'flex',
                      flexDirection: 'column',
                      alignItems: 'flex-start',
                      padding: '0.65rem 0.75rem',
                      borderRadius: '6px',
                      border: channelType === 'webchat' ? '2px solid #5d8f82' : '1px solid #d9e3de',
                      background: channelType === 'webchat' ? '#eaf1ee' : 'white',
                      cursor: 'pointer',
                      textAlign: 'left',
                    }}
                  >
                    <span style={{ fontWeight: 600, color: '#35564f', display: 'flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.85rem' }}>
                      <Globe size={14} color={channelType === 'webchat' ? '#5d8f82' : '#61726d'} /> Webchat
                    </span>
                    <span style={{ fontSize: '0.7rem', color: '#61726d', marginTop: '0.2rem' }}>
                      Widget para sitios web
                    </span>
                  </button>

                  <button
                    type="button"
                    onClick={() => {
                      setChannelType('whatsapp');
                      setInactivityWarningMinutes(15);
                      setInactivityCloseMinutes(30);
                    }}
                    style={{
                      display: 'flex',
                      flexDirection: 'column',
                      alignItems: 'flex-start',
                      padding: '0.65rem 0.75rem',
                      borderRadius: '6px',
                      border: channelType === 'whatsapp' ? '2px solid #5d8f82' : '1px solid #d9e3de',
                      background: channelType === 'whatsapp' ? '#eaf1ee' : 'white',
                      cursor: 'pointer',
                      textAlign: 'left',
                    }}
                  >
                    <span style={{ fontWeight: 600, color: '#35564f', display: 'flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.85rem' }}>
                      <MessageSquare size={14} color={channelType === 'whatsapp' ? '#5d8f82' : '#61726d'} /> WhatsApp
                    </span>
                    <span style={{ fontSize: '0.7rem', color: '#61726d', marginTop: '0.2rem' }}>
                      Cloud API oficial
                    </span>
                  </button>

                  <button
                    type="button"
                    onClick={() => {
                      setChannelType('facebook_messenger');
                      setInactivityWarningMinutes(15);
                      setInactivityCloseMinutes(30);
                    }}
                    style={{
                      display: 'flex',
                      flexDirection: 'column',
                      alignItems: 'flex-start',
                      padding: '0.65rem 0.75rem',
                      borderRadius: '6px',
                      border: channelType === 'facebook_messenger' ? '2px solid #5d8f82' : '1px solid #d9e3de',
                      background: channelType === 'facebook_messenger' ? '#eaf1ee' : 'white',
                      cursor: 'pointer',
                      textAlign: 'left',
                    }}
                  >
                    <span style={{ fontWeight: 600, color: '#35564f', display: 'flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.85rem' }}>
                      <MessageCircle size={14} color={channelType === 'facebook_messenger' ? '#5d8f82' : '#61726d'} /> Messenger
                    </span>
                    <span style={{ fontSize: '0.7rem', color: '#61726d', marginTop: '0.2rem' }}>
                      Facebook Page
                    </span>
                  </button>

                  <button
                    type="button"
                    onClick={() => {
                      setChannelType('instagram');
                      setInactivityWarningMinutes(15);
                      setInactivityCloseMinutes(30);
                    }}
                    style={{
                      display: 'flex',
                      flexDirection: 'column',
                      alignItems: 'flex-start',
                      padding: '0.65rem 0.75rem',
                      borderRadius: '6px',
                      border: channelType === 'instagram' ? '2px solid #db2777' : '1px solid #d9e3de',
                      background: channelType === 'instagram' ? '#fdf2f8' : 'white',
                      cursor: 'pointer',
                      textAlign: 'left',
                    }}
                  >
                    <span style={{ fontWeight: 600, color: '#35564f', display: 'flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.85rem' }}>
                      <Camera size={14} color={channelType === 'instagram' ? '#db2777' : '#61726d'} /> Instagram
                    </span>
                    <span style={{ fontSize: '0.7rem', color: '#61726d', marginTop: '0.2rem' }}>
                      DMs profesionales
                    </span>
                  </button>

                  <button
                    type="button"
                    onClick={() => {
                      setChannelType('telegram');
                      setInactivityWarningMinutes(15);
                      setInactivityCloseMinutes(30);
                    }}
                    style={{
                      display: 'flex',
                      flexDirection: 'column',
                      alignItems: 'flex-start',
                      padding: '0.65rem 0.75rem',
                      borderRadius: '6px',
                      border: channelType === 'telegram' ? '2px solid #0284c7' : '1px solid #d9e3de',
                      background: channelType === 'telegram' ? '#f0f9ff' : 'white',
                      cursor: 'pointer',
                      textAlign: 'left',
                    }}
                  >
                    <span style={{ fontWeight: 600, color: '#35564f', display: 'flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.85rem' }}>
                      <Send size={14} color={channelType === 'telegram' ? '#0284c7' : '#61726d'} /> Telegram
                    </span>
                    <span style={{ fontSize: '0.7rem', color: '#61726d', marginTop: '0.2rem' }}>
                      Bot (@BotFather)
                    </span>
                  </button>
                </div>
              </div>

              <div className="form-group">
                <label className="form-label">Nombre Administrativo del Canal</label>
                <input
                  type="text"
                  className="form-input"
                  required
                  placeholder={
                    channelType === 'webchat'
                      ? 'Ej. Webchat Sitio Corporativo'
                      : channelType === 'whatsapp'
                      ? 'Ej. WhatsApp Atención a Clientes'
                      : channelType === 'facebook_messenger'
                      ? 'Ej. Messenger Página Oficial'
                      : channelType === 'instagram'
                      ? 'Ej. Instagram DM Ventas'
                      : 'Ej. Telegram Bot Notificaciones'
                  }
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                />
              </div>

              <div className="form-group">
                <label className="form-label">Flujo Inicial (Entry Flow)</label>
                <select
                  className="form-select"
                  value={entryFlowId}
                  onChange={(e) => setEntryFlowId(e.target.value)}
                >
                  <option value="">(Sin flujo inicial — automatizaciones pausadas)</option>
                  {getCompatiblePublishedFlows(channelType).map((f) => (
                    <option key={f.id} value={f.id}>
                      {f.name} (Versión publicada: v{f.active_version_number})
                    </option>
                  ))}
                </select>
                {getCompatiblePublishedFlows(channelType).length === 0 && (
                  <p style={{ fontSize: '0.75rem', color: '#b91c1c', marginTop: '0.25rem' }}>
                    No hay flujos publicados compatibles con canales de tipo {channelType}.
                  </p>
                )}
                <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                  El flujo inicial publicado se ejecutará en cada conversación nueva iniciada en este canal (filtrado por compatibilidad de canal).
                </p>
              </div>

              {channelType === 'webchat' && (
                <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem', marginTop: '1rem' }}>
                  <h4 style={{ fontSize: '0.95rem', fontWeight: 600, marginBottom: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                    <Sliders size={16} /> Configuración del Widget Público
                  </h4>

                  <div className="form-group">
                    <label className="form-label">Nombre Visible en el Widget (Header)</label>
                    <input
                      type="text"
                      className="form-input"
                      required
                      placeholder="Ej. Soporte al Cliente"
                      value={displayName}
                      onChange={(e) => setDisplayName(e.target.value)}
                    />
                  </div>

                  <div className="form-group">
                    <label className="form-label">Orígenes / Dominios Permitidos (Allowed Origins)</label>
                    <textarea
                      className="form-input"
                      rows={3}
                      required
                      placeholder="https://example.com&#10;https://www.example.com&#10;http://localhost:3000"
                      value={allowedOrigins}
                      onChange={(e) => setAllowedOrigins(e.target.value)}
                      style={{ fontFamily: 'monospace', fontSize: '0.8rem' }}
                    />
                    <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                      Uno por línea. Incluya el protocolo y puerto exacto sin barra final (ej. <code>https://mi-sitio.com</code> o <code>http://localhost:3000</code>).
                    </p>
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
                    <div className="form-group">
                      <label className="form-label">Color de Acento</label>
                      <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                        <input
                          type="color"
                          value={accentColor}
                          onChange={(e) => setAccentColor(e.target.value)}
                          style={{ width: '40px', height: '36px', padding: 0, border: 'none', borderRadius: '4px', cursor: 'pointer' }}
                        />
                        <input
                          type="text"
                          className="form-input"
                          value={accentColor}
                          onChange={(e) => setAccentColor(e.target.value)}
                          style={{ fontFamily: 'monospace' }}
                        />
                      </div>
                    </div>

                    <div className="form-group">
                      <label className="form-label">Posición del Botón Flotante</label>
                      <select
                        className="form-select"
                        value={launcherPosition}
                        onChange={(e) => setLauncherPosition(e.target.value as any)}
                      >
                        <option value="bottom-right">Inferior Derecha (bottom-right)</option>
                        <option value="bottom-left">Inferior Izquierda (bottom-left)</option>
                      </select>
                    </div>
                  </div>
                </div>
              )}

              {channelType === 'whatsapp' && (
                <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem', marginTop: '1rem' }}>
                  <h4 style={{ fontSize: '0.95rem', fontWeight: 600, marginBottom: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.4rem', color: '#263c38' }}>
                    <MessageSquare size={16} /> Credenciales Meta Cloud API
                  </h4>

                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Phone Number ID *</label>
                      <input
                        type="text"
                        className="form-input"
                        required
                        placeholder="Ej. 109876543210"
                        value={phoneNumberId}
                        onChange={(e) => setPhoneNumberId(e.target.value)}
                      />
                      <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                        En Meta Dashboard &gt; WhatsApp &gt; Configuración de la API.
                      </p>
                    </div>

                    <div className="form-group">
                      <label className="form-label">WABA ID / Business Account ID</label>
                      <input
                        type="text"
                        className="form-input"
                        placeholder="Ej. 987654321098"
                        value={businessAccountId}
                        onChange={(e) => setBusinessAccountId(e.target.value)}
                      />
                      <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                        Identificador de tu WhatsApp Business Account (opcional).
                      </p>
                    </div>
                  </div>

                  <div className="form-group">
                    <label className="form-label">Meta App ID *</label>
                    <input
                      type="text"
                      className="form-input"
                      required
                      inputMode="numeric"
                      pattern="[0-9]+"
                      placeholder="Ej. 4365596607036352"
                      value={metaAppId}
                      onChange={(e) => setMetaAppId(e.target.value)}
                    />
                    <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                      Es necesario para consultar a Meta la expiración del token.
                    </p>
                  </div>

                  <div className="form-group">
                    <label className="form-label">Permanent Access Token (System User) *</label>
                    <input
                      type="password"
                      className="form-input"
                      required
                      placeholder="EAAG..."
                      value={accessToken}
                      onChange={(e) => setAccessToken(e.target.value)}
                    />
                    <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                      Token permanente con permisos <code>whatsapp_business_messaging</code>. Se almacena cifrado y nunca se expone.
                    </p>
                  </div>

                  <div className="form-group">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <label className="form-label">Webhook Verify Token *</label>
                      <button
                        type="button"
                        onClick={generateRandomVerifyToken}
                        style={{ fontSize: '0.7rem', color: '#5d8f82', background: 'none', border: 'none', cursor: 'pointer', textDecoration: 'underline' }}
                      >
                        Generar token seguro
                      </button>
                    </div>
                    <div style={{ display: 'flex', gap: '0.4rem' }}>
                      <input
                        type="text"
                        className="form-input"
                        required
                        placeholder="Token secreto de verificación"
                        value={verifyToken}
                        onChange={(e) => setVerifyToken(e.target.value)}
                        style={{ fontFamily: 'monospace', fontSize: '0.85rem' }}
                      />
                    </div>
                    <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                      Este token lo configurarás en Meta App Dashboard para validar el endpoint webhook.
                    </p>
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Meta App Secret (HMAC-SHA256) *</label>
                      <input
                        type="password"
                        className="form-input"
                        required
                        placeholder="Clave secreta de la app en Meta"
                        value={appSecret}
                        onChange={(e) => setAppSecret(e.target.value)}
                      />
                      <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                        Usado para verificar firmas <code>X-Hub-Signature-256</code>.
                      </p>
                    </div>

                    <div className="form-group">
                      <label className="form-label">Versión de Graph API</label>
                      <input
                        type="text"
                        className="form-input"
                        value={apiVersion}
                        onChange={(e) => setApiVersion(e.target.value)}
                        placeholder="v27.0"
                      />
                    </div>
                  </div>
                </div>
              )}

              {channelType === 'facebook_messenger' && (
                <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem', marginTop: '1rem' }}>
                  <h4 style={{ fontSize: '0.95rem', fontWeight: 600, marginBottom: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.4rem', color: '#456c61' }}>
                    <MessageCircle size={16} /> Credenciales Facebook Messenger
                  </h4>

                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Facebook Page ID *</label>
                      <input
                        type="text"
                        className="form-input"
                        required
                        placeholder="Ej. 102938475610293"
                        value={pageId}
                        onChange={(e) => setPageId(e.target.value)}
                      />
                      <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                        ID numérico de la Página de Facebook conectada.
                      </p>
                    </div>

                    <div className="form-group">
                      <label className="form-label">Meta App ID</label>
                      <input
                        type="text"
                        className="form-input"
                        placeholder="Ej. 4365596607036352"
                        value={metaAppId}
                        onChange={(e) => setMetaAppId(e.target.value)}
                      />
                      <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                        ID de la aplicación Meta Developers (opcional).
                      </p>
                    </div>
                  </div>

                  <div className="form-group">
                    <label className="form-label">Page Access Token *</label>
                    <input
                      type="password"
                      className="form-input"
                      required
                      placeholder="EAA..."
                      value={accessToken}
                      onChange={(e) => setAccessToken(e.target.value)}
                    />
                    <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                      Token de acceso de página con permisos <code>pages_messaging</code>. Almacenado cifrado.
                    </p>
                  </div>

                  <div className="form-group">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <label className="form-label">Webhook Verify Token *</label>
                      <button
                        type="button"
                        onClick={generateRandomVerifyToken}
                        style={{ fontSize: '0.7rem', color: '#5d8f82', background: 'none', border: 'none', cursor: 'pointer', textDecoration: 'underline' }}
                      >
                        Generar token seguro
                      </button>
                    </div>
                    <input
                      type="text"
                      className="form-input"
                      required
                      placeholder="Token secreto de verificación"
                      value={verifyToken}
                      onChange={(e) => setVerifyToken(e.target.value)}
                      style={{ fontFamily: 'monospace', fontSize: '0.85rem' }}
                    />
                    <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                      Configura este valor en Meta Developers &gt; Messenger &gt; Webhooks.
                    </p>
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Meta App Secret (HMAC-SHA256) *</label>
                      <input
                        type="password"
                        className="form-input"
                        required
                        placeholder="Clave secreta de la app en Meta"
                        value={appSecret}
                        onChange={(e) => setAppSecret(e.target.value)}
                      />
                      <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                        Obligatorio para verificar cabecera <code>X-Hub-Signature-256</code>.
                      </p>
                    </div>

                    <div className="form-group">
                      <label className="form-label">Versión Graph API</label>
                      <input
                        type="text"
                        className="form-input"
                        value={apiVersion}
                        onChange={(e) => setApiVersion(e.target.value)}
                        placeholder="v27.0"
                      />
                    </div>
                  </div>
                </div>
              )}

              {channelType === 'instagram' && (
                <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem', marginTop: '1rem' }}>
                  <h4 style={{ fontSize: '0.95rem', fontWeight: 600, marginBottom: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.4rem', color: '#be185d' }}>
                    <Camera size={16} /> Credenciales Instagram Direct (Solo DMs)
                  </h4>

                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Instagram Business Account ID *</label>
                      <input
                        type="text"
                        className="form-input"
                        required
                        placeholder="Ej. 17841400000000000"
                        value={instagramAccountId}
                        onChange={(e) => setInstagramAccountId(e.target.value)}
                      />
                      <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                        ID de la cuenta Profesional de Instagram (Business o Creador).
                      </p>
                    </div>

                    <div className="form-group">
                      <label className="form-label">Facebook Page ID Vinculada *</label>
                      <input
                        type="text"
                        className="form-input"
                        required
                        placeholder="Ej. 102938475610293"
                        value={pageId}
                        onChange={(e) => setPageId(e.target.value)}
                      />
                      <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                        ID de la Página de Facebook conectada con el perfil de Instagram.
                      </p>
                    </div>
                  </div>

                  <div className="form-group">
                    <label className="form-label">Instagram / Meta Access Token *</label>
                    <input
                      type="password"
                      className="form-input"
                      required
                      placeholder="EAA..."
                      value={accessToken}
                      onChange={(e) => setAccessToken(e.target.value)}
                    />
                    <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                      Token con permisos <code>instagram_manage_messages</code>. Almacenado cifrado.
                    </p>
                  </div>

                  <div className="form-group">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <label className="form-label">Webhook Verify Token *</label>
                      <button
                        type="button"
                        onClick={generateRandomVerifyToken}
                        style={{ fontSize: '0.7rem', color: '#5d8f82', background: 'none', border: 'none', cursor: 'pointer', textDecoration: 'underline' }}
                      >
                        Generar token seguro
                      </button>
                    </div>
                    <input
                      type="text"
                      className="form-input"
                      required
                      placeholder="Token secreto de verificación"
                      value={verifyToken}
                      onChange={(e) => setVerifyToken(e.target.value)}
                      style={{ fontFamily: 'monospace', fontSize: '0.85rem' }}
                    />
                    <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                      Configura este valor en Meta Developers &gt; Instagram &gt; Webhooks.
                    </p>
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Meta App Secret (HMAC-SHA256) *</label>
                      <input
                        type="password"
                        className="form-input"
                        required
                        placeholder="Clave secreta de la app en Meta"
                        value={appSecret}
                        onChange={(e) => setAppSecret(e.target.value)}
                      />
                      <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                        Obligatorio para verificar cabecera <code>X-Hub-Signature-256</code>.
                      </p>
                    </div>

                    <div className="form-group">
                      <label className="form-label">Versión Graph API</label>
                      <input
                        type="text"
                        className="form-input"
                        value={apiVersion}
                        onChange={(e) => setApiVersion(e.target.value)}
                        placeholder="v27.0"
                      />
                    </div>
                  </div>
                </div>
              )}

              {channelType === 'telegram' && (
                <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem', marginTop: '1rem' }}>
                  <h4 style={{ fontSize: '0.95rem', fontWeight: 600, marginBottom: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.4rem', color: '#0369a1' }}>
                    <Send size={16} /> Credenciales Telegram Bot API
                  </h4>

                  <div className="form-group">
                    <label className="form-label">Bot Token de Telegram (@BotFather) *</label>
                    <input
                      type="password"
                      className="form-input"
                      required
                      placeholder="123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ"
                      value={botToken}
                      onChange={(e) => setBotToken(e.target.value)}
                    />
                    <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                      Obtén el token creando o consultando tu bot con <code>@BotFather</code> en Telegram. Se almacena cifrado.
                    </p>
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Bot Username (opcional)</label>
                      <input
                        type="text"
                        className="form-input"
                        placeholder="MiEmpresaBot (sin @)"
                        value={botUsername}
                        onChange={(e) => setBotUsername(e.target.value.replace(/^@/, ''))}
                      />
                      <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                        Nombre de usuario público del bot en Telegram.
                      </p>
                    </div>

                    <div className="form-group">
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <label className="form-label">Webhook Secret Token</label>
                        <button
                          type="button"
                          onClick={generateRandomTelegramSecret}
                          style={{ fontSize: '0.7rem', color: '#5d8f82', background: 'none', border: 'none', cursor: 'pointer', textDecoration: 'underline' }}
                        >
                          Generar secret
                        </button>
                      </div>
                      <input
                        type="text"
                        className="form-input"
                        placeholder="Token secreto para Telegram"
                        value={webhookSecretToken}
                        onChange={(e) => setWebhookSecretToken(e.target.value)}
                        style={{ fontFamily: 'monospace', fontSize: '0.85rem' }}
                      />
                      <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                        Validado en la cabecera <code>X-Telegram-Bot-Api-Secret-Token</code>.
                      </p>
                    </div>
                  </div>
                </div>
              )}

              {renderInactivityConfigSection('create')}
              </div>

              <div
                className="shrink-0 border-t border-slate-100 dark:border-slate-800 p-4 sm:p-6 bg-slate-50/80 dark:bg-slate-800/50 backdrop-blur-sm flex justify-end gap-3 modal-footer"
                style={{
                  flexShrink: 0,
                  borderTop: '1px solid var(--border, #eaf1ee)',
                  padding: '1rem 1.5rem',
                  backgroundColor: 'rgba(248, 250, 252, 0.8)',
                  backdropFilter: 'blur(4px)',
                  display: 'flex',
                  justifyContent: 'flex-end',
                  gap: '0.75rem',
                  margin: 0,
                }}
              >
                <button type="button" className="btn btn-secondary" onClick={requestCloseChannelForm}>
                  Cancelar
                </button>
                <button type="submit" className="btn btn-primary" disabled={submitting}>
                  {submitting ? 'Creando...' : 'Crear Canal'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Panel lateral Editar Canal */}
      {editingChannel && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="edit-channel-panel-title"
          className="channel-side-panel-overlay"
          onMouseDown={(event) => { if (event.target === event.currentTarget) requestCloseChannelForm(); }}
        >
          <div
            className="channel-side-panel modal-content"
          >
            <div
              className="shrink-0 border-b border-slate-100 dark:border-slate-800 p-6 flex items-center justify-between modal-header"
              style={{
                flexShrink: 0,
                borderBottom: '1px solid var(--border, #eaf1ee)',
                padding: '1.25rem 1.5rem',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                margin: 0,
              }}
            >
              <h3 id="edit-channel-panel-title" style={{ fontSize: '1.15rem', fontWeight: 600, margin: 0 }}>
                Editar Canal: {editingChannel.name} ({
                  editingChannel.type === 'whatsapp'
                    ? 'WhatsApp Cloud API'
                    : editingChannel.type === 'facebook_messenger'
                    ? 'Facebook Messenger'
                    : editingChannel.type === 'instagram'
                    ? 'Instagram Direct'
                    : editingChannel.type === 'telegram'
                    ? 'Telegram Bot'
                    : 'Webchat'
                })
              </h3>
              <button type="button" className="btn btn-secondary btn-sm" aria-label="Cerrar panel" onClick={requestCloseChannelForm}>✕</button>
            </div>

            <form
              onSubmit={handleUpdateChannel}
              onChangeCapture={() => setChannelFormDirty(true)}
              className="flex flex-col flex-1 min-h-0 overflow-hidden"
              style={{ display: 'flex', flexDirection: 'column', flex: 1, minHeight: 0, overflow: 'hidden' }}
            >
              <div
                className="flex-1 overflow-y-auto p-6 space-y-6 overscroll-contain"
                style={{ flex: 1, overflowY: 'auto', padding: '1.5rem', overscrollBehavior: 'contain' }}
              >
                {formErrorMessage && (
                  <div className="alert alert-danger" style={{ marginBottom: '1.25rem', background: '#fef2f2', borderColor: '#fca5a5', color: '#991b1b', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                    <AlertTriangle size={18} color="#dc2626" />
                    <span>{formErrorMessage}</span>
                  </div>
                )}
                <div className="form-group">
                <label className="form-label">Nombre Administrativo</label>
                <input
                  type="text"
                  className="form-input"
                  required
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                />
              </div>

              <div className="form-group">
                <label className="form-label">Flujo Inicial (Entry Flow)</label>
                <select
                  className="form-select"
                  value={entryFlowId}
                  onChange={(e) => setEntryFlowId(e.target.value)}
                >
                  <option value="">(Sin flujo inicial)</option>
                  {getCompatiblePublishedFlows(editingChannel.type).map((f) => (
                    <option key={f.id} value={f.id}>
                      {f.name} (Versión publicada: v{f.active_version_number})
                    </option>
                  ))}
                </select>
                {getCompatiblePublishedFlows(editingChannel.type).length === 0 && (
                  <p style={{ fontSize: '0.75rem', color: '#b91c1c', marginTop: '0.25rem' }}>
                    No hay flujos publicados compatibles con canales de tipo {editingChannel.type}.
                  </p>
                )}
                <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                  Seleccione el flujo publicado que atenderá a los usuarios en este canal (filtrado por compatibilidad de canal).
                </p>
              </div>

              {editingChannel.type === 'whatsapp' && (
                <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem', marginTop: '1rem' }}>
                  <h4 style={{ fontSize: '0.95rem', fontWeight: 600, marginBottom: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.4rem', color: '#263c38' }}>
                    <MessageSquare size={16} /> Configuración WhatsApp Cloud API
                  </h4>

                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Phone Number ID</label>
                      <input
                        type="text"
                        className="form-input"
                        required
                        value={phoneNumberId}
                        onChange={(e) => setPhoneNumberId(e.target.value)}
                      />
                    </div>

                    <div className="form-group">
                      <label className="form-label">WABA ID / Business Account ID</label>
                      <input
                        type="text"
                        className="form-input"
                        value={businessAccountId}
                        onChange={(e) => setBusinessAccountId(e.target.value)}
                      />
                    </div>
                  </div>

                  <div className="form-group">
                    <label className="form-label">Meta App ID *</label>
                    <input
                      type="text"
                      className="form-input"
                      required
                      inputMode="numeric"
                      pattern="[0-9]+"
                      placeholder="Ej. 4365596607036352"
                      value={metaAppId}
                      onChange={(e) => setMetaAppId(e.target.value)}
                    />
                    <p style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                      Es necesario para consultar a Meta la expiración del token.
                    </p>
                  </div>

                  <div className="form-group">
                    <label className="form-label">Permanent Access Token</label>
                    <input
                      type="password"
                      className="form-input"
                      placeholder={editingChannel.configuration && (editingChannel.configuration as any).has_access_token ? '•••••••••••• (Guardado — dejar en blanco para mantener el actual)' : 'Ingresa el nuevo token'}
                      value={accessToken}
                      onChange={(e) => setAccessToken(e.target.value)}
                    />
                  </div>

                  <div className="form-group">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <label className="form-label">Webhook Verify Token</label>
                      <button
                        type="button"
                        onClick={generateRandomVerifyToken}
                        style={{ fontSize: '0.7rem', color: '#5d8f82', background: 'none', border: 'none', cursor: 'pointer', textDecoration: 'underline' }}
                      >
                        Generar nuevo token
                      </button>
                    </div>
                    <input
                      type="text"
                      className="form-input"
                      placeholder={editingChannel.configuration && (editingChannel.configuration as any).has_verify_token ? '•••••••••••• (Guardado — dejar en blanco para mantener el actual)' : 'Ingresa el verify token'}
                      value={verifyToken}
                      onChange={(e) => setVerifyToken(e.target.value)}
                      style={{ fontFamily: 'monospace' }}
                    />
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">App Secret (HMAC-SHA256)</label>
                      <input
                        type="password"
                        className="form-input"
                        placeholder={editingChannel.configuration && (editingChannel.configuration as any).has_app_secret ? '•••••••••••• (Guardado — dejar en blanco para mantener el actual)' : 'Ingresa el nuevo secret'}
                        value={appSecret}
                        onChange={(e) => setAppSecret(e.target.value)}
                      />
                    </div>

                    <div className="form-group">
                      <label className="form-label">Versión Graph API</label>
                      <input
                        type="text"
                        className="form-input"
                        placeholder="v27.0"
                        value={apiVersion}
                        onChange={(e) => setApiVersion(e.target.value)}
                      />
                    </div>
                  </div>
                </div>
              )}

              {editingChannel.type === 'facebook_messenger' && (
                <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem', marginTop: '1rem' }}>
                  <h4 style={{ fontSize: '0.95rem', fontWeight: 600, marginBottom: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.4rem', color: '#456c61' }}>
                    <MessageCircle size={16} /> Configuración Facebook Messenger
                  </h4>

                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Facebook Page ID *</label>
                      <input
                        type="text"
                        className="form-input"
                        required
                        value={pageId}
                        onChange={(e) => setPageId(e.target.value)}
                      />
                    </div>

                    <div className="form-group">
                      <label className="form-label">Meta App ID</label>
                      <input
                        type="text"
                        className="form-input"
                        value={metaAppId}
                        onChange={(e) => setMetaAppId(e.target.value)}
                      />
                    </div>
                  </div>

                  <div className="form-group">
                    <label className="form-label">Page Access Token</label>
                    <input
                      type="password"
                      className="form-input"
                      placeholder={editingChannel.configuration && (editingChannel.configuration as any).has_access_token ? '•••••••••••• (Guardado — dejar en blanco para mantener el actual)' : 'Ingresa nuevo token'}
                      value={accessToken}
                      onChange={(e) => setAccessToken(e.target.value)}
                    />
                  </div>

                  <div className="form-group">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <label className="form-label">Webhook Verify Token</label>
                      <button
                        type="button"
                        onClick={generateRandomVerifyToken}
                        style={{ fontSize: '0.7rem', color: '#5d8f82', background: 'none', border: 'none', cursor: 'pointer', textDecoration: 'underline' }}
                      >
                        Generar nuevo token
                      </button>
                    </div>
                    <input
                      type="text"
                      className="form-input"
                      placeholder={editingChannel.configuration && (editingChannel.configuration as any).has_verify_token ? '•••••••••••• (Guardado — dejar en blanco para mantener el actual)' : 'Ingresa el verify token'}
                      value={verifyToken}
                      onChange={(e) => setVerifyToken(e.target.value)}
                      style={{ fontFamily: 'monospace' }}
                    />
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Meta App Secret (HMAC-SHA256)</label>
                      <input
                        type="password"
                        className="form-input"
                        placeholder={editingChannel.configuration && (editingChannel.configuration as any).has_app_secret ? '•••••••••••• (Guardado — dejar en blanco para mantener el actual)' : 'Ingresa nuevo secret'}
                        value={appSecret}
                        onChange={(e) => setAppSecret(e.target.value)}
                      />
                    </div>

                    <div className="form-group">
                      <label className="form-label">Versión Graph API</label>
                      <input
                        type="text"
                        className="form-input"
                        placeholder="v27.0"
                        value={apiVersion}
                        onChange={(e) => setApiVersion(e.target.value)}
                      />
                    </div>
                  </div>
                </div>
              )}

              {editingChannel.type === 'instagram' && (
                <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem', marginTop: '1rem' }}>
                  <h4 style={{ fontSize: '0.95rem', fontWeight: 600, marginBottom: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.4rem', color: '#be185d' }}>
                    <Camera size={16} /> Configuración Instagram Direct (Solo DMs)
                  </h4>

                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Instagram Business Account ID *</label>
                      <input
                        type="text"
                        className="form-input"
                        required
                        value={instagramAccountId}
                        onChange={(e) => setInstagramAccountId(e.target.value)}
                      />
                    </div>

                    <div className="form-group">
                      <label className="form-label">Facebook Page ID Vinculada *</label>
                      <input
                        type="text"
                        className="form-input"
                        required
                        value={pageId}
                        onChange={(e) => setPageId(e.target.value)}
                      />
                    </div>
                  </div>

                  <div className="form-group">
                    <label className="form-label">Meta / Instagram Access Token</label>
                    <input
                      type="password"
                      className="form-input"
                      placeholder={editingChannel.configuration && (editingChannel.configuration as any).has_access_token ? '•••••••••••• (Guardado — dejar en blanco para mantener el actual)' : 'Ingresa nuevo token'}
                      value={accessToken}
                      onChange={(e) => setAccessToken(e.target.value)}
                    />
                  </div>

                  <div className="form-group">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <label className="form-label">Webhook Verify Token</label>
                      <button
                        type="button"
                        onClick={generateRandomVerifyToken}
                        style={{ fontSize: '0.7rem', color: '#5d8f82', background: 'none', border: 'none', cursor: 'pointer', textDecoration: 'underline' }}
                      >
                        Generar nuevo token
                      </button>
                    </div>
                    <input
                      type="text"
                      className="form-input"
                      placeholder={editingChannel.configuration && (editingChannel.configuration as any).has_verify_token ? '•••••••••••• (Guardado — dejar en blanco para mantener el actual)' : 'Ingresa el verify token'}
                      value={verifyToken}
                      onChange={(e) => setVerifyToken(e.target.value)}
                      style={{ fontFamily: 'monospace' }}
                    />
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Meta App Secret (HMAC-SHA256)</label>
                      <input
                        type="password"
                        className="form-input"
                        placeholder={editingChannel.configuration && (editingChannel.configuration as any).has_app_secret ? '•••••••••••• (Guardado — dejar en blanco para mantener el actual)' : 'Ingresa nuevo secret'}
                        value={appSecret}
                        onChange={(e) => setAppSecret(e.target.value)}
                      />
                    </div>

                    <div className="form-group">
                      <label className="form-label">Versión Graph API</label>
                      <input
                        type="text"
                        className="form-input"
                        placeholder="v27.0"
                        value={apiVersion}
                        onChange={(e) => setApiVersion(e.target.value)}
                      />
                    </div>
                  </div>
                </div>
              )}

              {editingChannel.type === 'telegram' && (
                <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem', marginTop: '1rem' }}>
                  <h4 style={{ fontSize: '0.95rem', fontWeight: 600, marginBottom: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.4rem', color: '#0369a1' }}>
                    <Send size={16} /> Configuración Telegram Bot API
                  </h4>

                  <div className="form-group">
                    <label className="form-label">Bot Token de Telegram (@BotFather)</label>
                    <input
                      type="password"
                      className="form-input"
                      placeholder={editingChannel.configuration && (editingChannel.configuration as any).has_bot_token ? '•••••••••••• (Guardado — dejar en blanco para mantener el actual)' : 'Ingresa nuevo bot token'}
                      value={botToken}
                      onChange={(e) => setBotToken(e.target.value)}
                    />
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
                    <div className="form-group">
                      <label className="form-label">Bot Username</label>
                      <input
                        type="text"
                        className="form-input"
                        placeholder="MiEmpresaBot (sin @)"
                        value={botUsername}
                        onChange={(e) => setBotUsername(e.target.value.replace(/^@/, ''))}
                      />
                    </div>

                    <div className="form-group">
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <label className="form-label">Webhook Secret Token</label>
                        <button
                          type="button"
                          onClick={generateRandomTelegramSecret}
                          style={{ fontSize: '0.7rem', color: '#5d8f82', background: 'none', border: 'none', cursor: 'pointer', textDecoration: 'underline' }}
                        >
                          Generar secret
                        </button>
                      </div>
                      <input
                        type="text"
                        className="form-input"
                        placeholder={editingChannel.configuration && (editingChannel.configuration as any).has_webhook_secret_token ? '•••••••••••• (Guardado — dejar en blanco para mantener el actual)' : 'Ingresa webhook secret token'}
                        value={webhookSecretToken}
                        onChange={(e) => setWebhookSecretToken(e.target.value)}
                        style={{ fontFamily: 'monospace' }}
                      />
                    </div>
                  </div>
                </div>
              )}

              {editingChannel.type === 'webchat' && (
                <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem', marginTop: '1rem' }}>
                  <h4 style={{ fontSize: '0.95rem', fontWeight: 600, marginBottom: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                    <Sliders size={16} /> Configuración del Widget Webchat
                  </h4>

                  <div className="form-group">
                    <label className="form-label">Nombre Visible en el Widget (Header)</label>
                    <input
                      type="text"
                      className="form-input"
                      required
                      value={displayName}
                      onChange={(e) => setDisplayName(e.target.value)}
                    />
                  </div>

                  <div className="form-group">
                    <label className="form-label">Orígenes / Dominios Permitidos (Allowed Origins)</label>
                    <textarea
                      className="form-input"
                      rows={3}
                      required
                      value={allowedOrigins}
                      onChange={(e) => setAllowedOrigins(e.target.value)}
                      style={{ fontFamily: 'monospace', fontSize: '0.8rem' }}
                    />
                    <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                      Uno por línea (ej: <code>https://mi-dominio.com</code> o <code>http://localhost:3000</code>).
                    </p>
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
                    <div className="form-group">
                      <label className="form-label">Color de Acento</label>
                      <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                        <input
                          type="color"
                          value={accentColor}
                          onChange={(e) => setAccentColor(e.target.value)}
                          style={{ width: '40px', height: '36px', padding: 0, border: 'none', borderRadius: '4px', cursor: 'pointer' }}
                        />
                        <input
                          type="text"
                          className="form-input"
                          value={accentColor}
                          onChange={(e) => setAccentColor(e.target.value)}
                          style={{ fontFamily: 'monospace' }}
                        />
                      </div>
                    </div>

                    <div className="form-group">
                      <label className="form-label">Posición del Botón Flotante</label>
                      <select
                        className="form-select"
                        value={launcherPosition}
                        onChange={(e) => setLauncherPosition(e.target.value as any)}
                      >
                        <option value="bottom-right">Inferior Derecha (bottom-right)</option>
                        <option value="bottom-left">Inferior Izquierda (bottom-left)</option>
                      </select>
                    </div>
                  </div>

                  {editingChannel.webchat_settings && (
                    <div style={{ marginTop: '1rem', background: '#f4f7f5', padding: '0.85rem', borderRadius: '6px', border: '1px solid #d9e3de' }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.4rem' }}>
                        <span style={{ fontWeight: 600, fontSize: '0.8rem', display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                          <Code size={14} /> Snippet de Instalación
                        </span>
                        <button
                          type="button"
                          className="btn btn-sm btn-secondary"
                          onClick={() => handleCopyCode(editingChannel.id, editingChannel.webchat_settings?.public_key)}
                          style={{ fontSize: '0.75rem', padding: '0.2rem 0.5rem' }}
                        >
                          {copiedChannelId === editingChannel.id ? '¡Copiado!' : 'Copiar código'}
                        </button>
                      </div>
                      <pre style={{ margin: 0, fontSize: '0.75rem', background: 'white', padding: '0.5rem', borderRadius: '4px', border: '1px solid #d9e3de', overflowX: 'auto' }}>
                        {getEmbedCode(editingChannel.webchat_settings?.public_key)}
                      </pre>
                    </div>
                  )}
                </div>
              )}

              {renderInactivityConfigSection('edit')}
              </div>

              <div
                className="shrink-0 border-t border-slate-100 dark:border-slate-800 p-4 sm:p-6 bg-slate-50/80 dark:bg-slate-800/50 backdrop-blur-sm flex justify-end gap-3 modal-footer"
                style={{
                  flexShrink: 0,
                  borderTop: '1px solid var(--border, #eaf1ee)',
                  padding: '1rem 1.5rem',
                  backgroundColor: 'rgba(248, 250, 252, 0.8)',
                  backdropFilter: 'blur(4px)',
                  display: 'flex',
                  justifyContent: 'flex-end',
                  gap: '0.75rem',
                  margin: 0,
                }}
              >
                <button type="button" className="btn btn-secondary" onClick={requestCloseChannelForm}>
                  Cancelar
                </button>
                <button type="submit" className="btn btn-primary" disabled={submitting}>
                  {submitting ? 'Guardando...' : 'Guardar Cambios'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Configuración Webhook (Meta / Telegram) */}
      {metaWebhookChannel && (
        <div className="modal-overlay flow-panel-overlay" style={{ zIndex: 1150 }} role="dialog" aria-modal="true" aria-labelledby="webhook-setup-panel-title">
          <div className="modal-content flow-side-panel">
            <div className="flow-side-panel-header">
              <h3
                id="webhook-setup-panel-title"
                style={{
                  fontSize: '1.15rem',
                  fontWeight: 600,
                  margin: 0,
                  display: 'flex',
                  alignItems: 'center',
                  gap: '0.4rem',
                  color:
                    metaWebhookChannel.type === 'whatsapp'
                      ? '#263c38'
                      : metaWebhookChannel.type === 'facebook_messenger'
                      ? '#456c61'
                      : metaWebhookChannel.type === 'instagram'
                      ? '#be185d'
                      : '#0369a1',
                }}
              >
                {metaWebhookChannel.type === 'whatsapp' && <MessageSquare size={18} />}
                {metaWebhookChannel.type === 'facebook_messenger' && <MessageCircle size={18} />}
                {metaWebhookChannel.type === 'instagram' && <Camera size={18} />}
                {metaWebhookChannel.type === 'telegram' && <Send size={18} />}
                {metaWebhookChannel.type === 'whatsapp' && 'Configuración de Webhook en Meta (WhatsApp Cloud API)'}
                {metaWebhookChannel.type === 'facebook_messenger' && 'Configuración de Webhook en Meta (Messenger)'}
                {metaWebhookChannel.type === 'instagram' && 'Configuración de Webhook en Meta (Instagram Direct)'}
                {metaWebhookChannel.type === 'telegram' && 'Configuración de Webhook en Telegram Bot'}
              </h3>
              <button className="btn btn-secondary btn-sm" aria-label="Cerrar panel" onClick={() => setMetaWebhookChannel(null)}>✕</button>
            </div>

            <div className="flow-side-panel-body" style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
              <p style={{ fontSize: '0.875rem', color: '#56736b', lineHeight: 1.5, margin: 0 }}>
                Datos de conexión e integración para el canal <strong>{metaWebhookChannel.name}</strong>:
              </p>

              {/* URL del Webhook */}
              <div>
                <label className="form-label" style={{ fontWeight: 600, fontSize: '0.8rem' }}>
                  {metaWebhookChannel.type === 'telegram' ? 'URL del Webhook (HTTPS obligatorio)' : 'Callback URL (URL de devolución de llamada)'}
                </label>
                <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                  <input
                    type="text"
                    readOnly
                    className="form-input"
                    value={
                      metaWebhookChannel.type === 'whatsapp'
                        ? `${window.location.origin}/api/v1/public/webhooks/whatsapp/${metaWebhookChannel.id}`
                        : metaWebhookChannel.type === 'facebook_messenger'
                        ? `${window.location.origin}/api/v1/public/webhooks/messenger/${metaWebhookChannel.id}`
                        : metaWebhookChannel.type === 'instagram'
                        ? `${window.location.origin}/api/v1/public/webhooks/instagram/${metaWebhookChannel.id}`
                        : `${window.location.origin}/api/v1/public/webhooks/telegram/${metaWebhookChannel.id}`
                    }
                    style={{ fontFamily: 'monospace', fontSize: '0.8rem', background: '#f4f7f5' }}
                  />
                  <button
                    type="button"
                    className="btn btn-sm btn-secondary"
                    onClick={() => {
                      const url =
                        metaWebhookChannel.type === 'whatsapp'
                          ? `${window.location.origin}/api/v1/public/webhooks/whatsapp/${metaWebhookChannel.id}`
                          : metaWebhookChannel.type === 'facebook_messenger'
                          ? `${window.location.origin}/api/v1/public/webhooks/messenger/${metaWebhookChannel.id}`
                          : metaWebhookChannel.type === 'instagram'
                          ? `${window.location.origin}/api/v1/public/webhooks/instagram/${metaWebhookChannel.id}`
                          : `${window.location.origin}/api/v1/public/webhooks/telegram/${metaWebhookChannel.id}`;
                      handleCopyText(url, 'url');
                    }}
                    style={{ whiteSpace: 'nowrap' }}
                  >
                    {copiedWebhookUrl ? <Check size={13} color="#5d8f82" /> : <Copy size={13} />}
                    {copiedWebhookUrl ? '¡Copiado!' : 'Copiar'}
                  </button>
                </div>
              </div>

              {/* Token de verificación / Secret token */}
              {metaWebhookChannel.type === 'telegram' ? (
                <div>
                  <label className="form-label" style={{ fontWeight: 600, fontSize: '0.8rem' }}>
                    Secret Token (Cabecera X-Telegram-Bot-Api-Secret-Token)
                  </label>
                  <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                    <input
                      type="text"
                      readOnly
                      className="form-input"
                      value={
                        (metaWebhookChannel.configuration as any)?.webhook_secret_token === '***REDACTED***'
                          ? 'Token configurado en el canal'
                          : ((metaWebhookChannel.configuration as any)?.webhook_secret_token || '(Sin secret token configurado)')
                      }
                      style={{ fontFamily: 'monospace', fontSize: '0.8rem', background: '#f4f7f5' }}
                    />
                    {(metaWebhookChannel.configuration as any)?.webhook_secret_token &&
                      (metaWebhookChannel.configuration as any)?.webhook_secret_token !== '***REDACTED***' && (
                        <button
                          type="button"
                          className="btn btn-sm btn-secondary"
                          onClick={() => handleCopyText((metaWebhookChannel.configuration as any).webhook_secret_token, 'token')}
                          style={{ whiteSpace: 'nowrap' }}
                        >
                          {copiedVerifyToken ? <Check size={13} color="#5d8f82" /> : <Copy size={13} />}
                          {copiedVerifyToken ? '¡Copiado!' : 'Copiar'}
                        </button>
                      )}
                  </div>
                  <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                    Telegram enviará este secret token en la cabecera <code>X-Telegram-Bot-Api-Secret-Token</code> para validar la autenticidad de cada update.
                  </p>
                </div>
              ) : (
                <div>
                  <label className="form-label" style={{ fontWeight: 600, fontSize: '0.8rem' }}>Verify Token (Identificador de verificación)</label>
                  <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                    <input
                      type="text"
                      readOnly
                      className="form-input"
                      value={
                        (metaWebhookChannel.configuration as any)?.verify_token === '***REDACTED***'
                          ? 'Token configurado en el canal'
                          : ((metaWebhookChannel.configuration as any)?.verify_token || '')
                      }
                      style={{ fontFamily: 'monospace', fontSize: '0.8rem', background: '#f4f7f5' }}
                    />
                    {(metaWebhookChannel.configuration as any)?.verify_token &&
                      (metaWebhookChannel.configuration as any)?.verify_token !== '***REDACTED***' && (
                        <button
                          type="button"
                          className="btn btn-sm btn-secondary"
                          onClick={() => handleCopyText((metaWebhookChannel.configuration as any).verify_token, 'token')}
                          style={{ whiteSpace: 'nowrap' }}
                        >
                          {copiedVerifyToken ? <Check size={13} color="#5d8f82" /> : <Copy size={13} />}
                          {copiedVerifyToken ? '¡Copiado!' : 'Copiar'}
                        </button>
                      )}
                  </div>
                  <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                    Ingresa exactamente el Verify Token configurado al crear este canal.
                  </p>
                </div>
              )}

              {/* Botón de configuración automática para Telegram */}
              {metaWebhookChannel.type === 'telegram' && (
                <div style={{ background: '#f0f9ff', border: '1px solid #bae6fd', padding: '0.85rem', borderRadius: '6px' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '0.5rem' }}>
                    <div>
                      <strong style={{ fontSize: '0.825rem', color: '#0369a1', display: 'block' }}>Configuración Automática en Telegram:</strong>
                      <span style={{ fontSize: '0.75rem', color: '#0c4a6e' }}>
                        Registra la URL del webhook y el secret token directamente en la API de Telegram con un clic.
                      </span>
                    </div>
                    <button
                      type="button"
                      className="btn btn-sm btn-primary"
                      onClick={() => handleSetupTelegramWebhook(metaWebhookChannel.id)}
                      disabled={telegramSettingWebhook}
                      style={{ fontSize: '0.8rem', display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
                    >
                      <RefreshCw size={13} className={telegramSettingWebhook ? 'animate-spin' : ''} />
                      {telegramSettingWebhook ? 'Registrando...' : 'Registrar en Telegram'}
                    </button>
                  </div>
                  {telegramWebhookFeedback && (
                    <div
                      style={{
                        marginTop: '0.5rem',
                        fontSize: '0.775rem',
                        padding: '0.4rem 0.6rem',
                        borderRadius: '4px',
                        background: telegramWebhookFeedback.ok ? '#eaf1ee' : '#fee2e2',
                        color: telegramWebhookFeedback.ok ? '#263c38' : '#991b1b',
                        border: `1px solid ${telegramWebhookFeedback.ok ? '#a9c8bb' : '#fca5a5'}`,
                      }}
                    >
                      {telegramWebhookFeedback.msg}
                    </div>
                  )}
                </div>
              )}

              {/* Instrucciones por tipo de plataforma */}
              {metaWebhookChannel.type === 'whatsapp' && (
                <div style={{ background: '#eaf1ee', border: '1px solid #a9c8bb', padding: '0.85rem', borderRadius: '6px', fontSize: '0.8rem', color: '#263c38' }}>
                  <strong style={{ display: 'block', marginBottom: '0.35rem' }}>Pasos en Meta App Dashboard:</strong>
                  <ol style={{ margin: 0, paddingLeft: '1.2rem', lineHeight: 1.5 }}>
                    <li>Ve a <strong>WhatsApp &gt; Configuration &gt; Webhook</strong> y presiona <strong>Edit</strong>.</li>
                    <li>Pega la <strong>Callback URL</strong> y el <strong>Verify Token</strong> de arriba y haz clic en <strong>Verify and save</strong>.</li>
                    <li>En la tabla de <strong>Webhook fields</strong>, suscríbete al campo <code>messages</code>.</li>
                  </ol>
                </div>
              )}

              {metaWebhookChannel.type === 'facebook_messenger' && (
                <div style={{ background: '#eaf1ee', border: '1px solid #a9c8bb', padding: '0.85rem', borderRadius: '6px', fontSize: '0.8rem', color: '#456c61' }}>
                  <strong style={{ display: 'block', marginBottom: '0.35rem' }}>Pasos en Meta App Dashboard (Messenger):</strong>
                  <ol style={{ margin: 0, paddingLeft: '1.2rem', lineHeight: 1.5 }}>
                    <li>Ve a <strong>Messenger &gt; Settings &gt; Webhooks</strong> y presiona <strong>Add Callback URL</strong> (o Edit).</li>
                    <li>Pega la <strong>Callback URL</strong> y el <strong>Verify Token</strong> de arriba y haz clic en <strong>Verify and save</strong>.</li>
                    <li>En la tabla de suscripciones de la Página, suscríbete a los campos <code>messages</code> y <code>messaging_postbacks</code>.</li>
                    <li>Asegúrate de haber generado el <strong>Page Access Token</strong> para esa misma Página.</li>
                  </ol>
                </div>
              )}

              {metaWebhookChannel.type === 'instagram' && (
                <div style={{ background: '#fdf2f8', border: '1px solid #fbcfe8', padding: '0.85rem', borderRadius: '6px', fontSize: '0.8rem', color: '#9d174d' }}>
                  <strong style={{ display: 'block', marginBottom: '0.35rem' }}>Pasos en Meta App Dashboard (Instagram Direct - Solo DMs):</strong>
                  <ol style={{ margin: 0, paddingLeft: '1.2rem', lineHeight: 1.5 }}>
                    <li>Ve a <strong>Instagram &gt; Basic Display</strong> o <strong>Webhooks &gt; Instagram</strong> y presiona <strong>Edit Subscription</strong>.</li>
                    <li>Pega la <strong>Callback URL</strong> y el <strong>Verify Token</strong> de arriba y haz clic en <strong>Verify and save</strong>.</li>
                    <li>Suscríbete exclusivamente a los campos de mensajería directa: <code>messages</code> y <code>messaging_postbacks</code> (no comentarios ni feed).</li>
                    <li>Verifica que la cuenta de Instagram sea <strong>Profesional (Business o Creador)</strong>, esté vinculada a la Página de Facebook y cuente con el permiso <code>instagram_manage_messages</code>.</li>
                  </ol>
                </div>
              )}

              {metaWebhookChannel.type === 'telegram' && (
                <div style={{ background: '#f4f7f5', border: '1px solid #d9e3de', padding: '0.85rem', borderRadius: '6px', fontSize: '0.8rem', color: '#56736b' }}>
                  <strong style={{ display: 'block', marginBottom: '0.35rem' }}>Configuración manual alternativa (cURL):</strong>
                  <p style={{ margin: '0 0 0.5rem 0', lineHeight: 1.4 }}>
                    Si prefieres registrar el webhook manualmente mediante la API oficial de Telegram:
                  </p>
                  <pre style={{ margin: 0, fontSize: '0.725rem', background: '#263c38', color: '#f4f7f5', padding: '0.5rem', borderRadius: '4px', overflowX: 'auto' }}>
{`curl -X POST "https://api.telegram.org/bot<TU_BOT_TOKEN>/setWebhook" \\
  -d "url=${window.location.origin}/api/v1/public/webhooks/telegram/${metaWebhookChannel.id}" \\
  -d "secret_token=<TU_SECRET_TOKEN>" \\
  -d "allowed_updates=[\\"message\\",\\"callback_query\\"]"`}
                  </pre>
                </div>
              )}
            </div>

            <div className="modal-footer flow-side-panel-footer">
              <button type="button" className="btn btn-primary" onClick={() => setMetaWebhookChannel(null)}>
                Entendido
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal Vista Previa del Widget */}
      {previewChannel && previewChannel.webchat_settings && (
        <div className="modal-overlay" style={{ zIndex: 1100 }}>
          <div className="modal-content side-panel-wide" style={{ maxWidth: '850px', width: '90%', height: '80vh', display: 'flex', flexDirection: 'column', padding: 0, overflow: 'hidden' }}>
            <div className="flow-side-panel-header">
              <div>
                <h3 style={{ fontSize: '1.05rem', fontWeight: 600, margin: 0 }}>
                  Vista Previa del Webchat: {previewChannel.name}
                </h3>
                <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', margin: 0 }}>
                  Probando widget público con clave: <code style={{ color: '#5d8f82' }}>{previewChannel.webchat_settings.public_key}</code>
                </p>
              </div>
              <button className="btn btn-secondary btn-sm" aria-label="Cerrar panel" onClick={() => setPreviewChannel(null)}>✕</button>
            </div>

            <div className="flow-side-panel-body" style={{ flex: 1, display: 'flex', background: '#eaf1ee', position: 'relative' }}>
              <div style={{ flex: 1, padding: '2rem', display: 'flex', flexDirection: 'column', gap: '1rem', color: '#61726d' }}>
                <div style={{ background: 'white', padding: '1.5rem', borderRadius: '8px', border: '1px solid #d9e3de', maxWidth: '480px' }}>
                  <h4 style={{ fontWeight: 600, color: '#35564f', marginBottom: '0.5rem' }}>Simulación de Sitio Web Externo</h4>
                  <p style={{ fontSize: '0.85rem', lineHeight: 1.5, margin: 0 }}>
                    Este contenedor simula una página anfitriona donde se ha cargado el widget. En la esquina inferior derecha (o izquierda según su configuración) puede interactuar directamente con el widget embebido en tiempo real.
                  </p>
                </div>
              </div>

              {/* Iframe del widget en vivo */}
              <div
                style={{
                  position: 'absolute',
                  bottom: '20px',
                  right: previewChannel.webchat_settings.launcher_position === 'bottom-left' ? 'auto' : '20px',
                  left: previewChannel.webchat_settings.launcher_position === 'bottom-left' ? '20px' : 'auto',
                  width: '380px',
                  height: '560px',
                  maxHeight: 'calc(100% - 40px)',
                  boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04)',
                  borderRadius: '16px',
                  overflow: 'hidden',
                  border: '1px solid #d9e3de',
                  background: 'white',
                  zIndex: 10,
                }}
              >
                <iframe
                  title="Webchat Preview"
                  src={`/widget.html?channel=${previewChannel.webchat_settings.public_key}`}
                  style={{ width: '100%', height: '100%', border: 'none' }}
                />
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Modal Confirmar Regeneración de Clave */}
      {confirmRegenChannel && confirmRegenChannel.webchat_settings && (
        <div className="modal-overlay" style={{ zIndex: 1200 }}>
          <div className="modal-content side-panel-compact" style={{ maxWidth: '480px' }}>
            <div className="modal-header">
              <h3 style={{ fontSize: '1.1rem', fontWeight: 600, color: '#b91c1c', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                <AlertTriangle size={18} /> Regenerar Clave Pública
              </h3>
              <button className="btn btn-secondary btn-sm" onClick={() => setConfirmRegenChannel(null)}>✕</button>
            </div>

            <div style={{ padding: '0.5rem 0' }}>
              <p style={{ fontSize: '0.9rem', color: '#56736b', lineHeight: 1.5 }}>
                ¿Está seguro de que desea regenerar el identificador público para el canal <strong>{confirmRegenChannel.name}</strong>?
              </p>
              <div className="alert alert-warning" style={{ background: '#fef2f2', borderColor: '#fecaca', color: '#991b1b', fontSize: '0.8rem', marginTop: '0.75rem' }}>
                <strong>Advertencia de impacto:</strong> Todos los sitios web externos que tengan instalado el widget con la clave actual (<code>{confirmRegenChannel.webchat_settings.public_key}</code>) dejarán de conectar hasta que reemplacen el snippet por la nueva clave generada.
              </div>
            </div>

            <div className="modal-footer">
              <button type="button" className="btn btn-secondary" onClick={() => setConfirmRegenChannel(null)} disabled={regenerating}>
                Cancelar
              </button>
              <button type="button" className="btn btn-danger" onClick={handleRegenerateKey} disabled={regenerating}>
                {regenerating ? 'Regenerando...' : 'Sí, regenerar clave pública'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal de Confirmación para Desactivar y Eliminar Canal */}
      <DiscardChangesPanel
        isOpen={confirmDiscardChannelForm}
        onContinueEditing={() => setConfirmDiscardChannelForm(false)}
        onDiscard={discardChannelForm}
      />
      <ConfirmModal
        isOpen={confirmModalState.isOpen}
        onClose={() => {
          if (!confirmModalState.isSubmitting) {
            setConfirmModalState((prev) => ({ ...prev, isOpen: false, channel: null }));
          }
        }}
        onConfirm={() => {
          if (!confirmModalState.channel) return;
          if (confirmModalState.type === 'toggle_active') {
            executeToggleStatus(confirmModalState.channel);
          } else {
            executeDeleteChannel(confirmModalState.channel);
          }
        }}
        title={
          confirmModalState.type === 'toggle_active'
            ? `¿Desactivar canal «${confirmModalState.channel?.name}»?`
            : `¿Eliminar canal «${confirmModalState.channel?.name}»?`
        }
        description={
          confirmModalState.type === 'toggle_active'
            ? 'El canal dejará de recibir y procesar mensajes en tiempo real hasta que vuelvas a activarlo. Las conversaciones existentes permanecerán en el Inbox.'
            : 'Esta acción eliminará la configuración del canal y no se podrá deshacer. Asegúrate de haber desconectado los webhooks o widgets asociados.'
        }
        confirmText={
          confirmModalState.type === 'toggle_active' ? 'Sí, desactivar canal' : 'Sí, eliminar canal'
        }
        cancelText="Cancelar"
        confirmVariant={confirmModalState.type === 'toggle_active' ? 'primary' : 'danger'}
        icon={
          confirmModalState.type === 'toggle_active' ? (
            <PowerOff size={24} />
          ) : (
            <Trash2 size={24} />
          )
        }
        isLoading={confirmModalState.isSubmitting}
        testId={
          confirmModalState.type === 'toggle_active'
            ? 'confirm-toggle-status-modal'
            : 'confirm-delete-channel-modal'
        }
        confirmTestId={
          confirmModalState.type === 'toggle_active'
            ? 'confirm-toggle-status-btn'
            : 'confirm-delete-channel-btn'
        }
        cancelTestId={
          confirmModalState.type === 'toggle_active'
            ? 'cancel-toggle-status-btn'
            : 'cancel-delete-channel-btn'
        }
        requiredText={confirmModalState.type === 'delete' ? confirmModalState.channel?.name : undefined}
        requiredTextLabel="canal"
      />
    </div>
  );
};
