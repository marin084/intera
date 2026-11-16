import React, { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { api } from '../services/api';
import { createEcho } from '../services/echo';
import type { Conversation, FlowExecution, User, Message, Contact, InternalNote, CannedResponse, Tag } from '../types';
import {
  MessageSquare,
  Send,
  UserCheck,
  User as UserIcon,
  Bot,
  Radio,
  Clock,
  CheckCircle2,
  Zap,
  ChevronDown,
  ChevronUp,
  AlertCircle,
  GitBranch,
  Wifi,
  XCircle,
  Search,
  Filter,
  Phone,
  Mail,
  Paperclip,
  FileText,
  Download,
  X,
  ArrowLeft,
  Bell,
  BellOff,
  PanelRightOpen,
  PanelRightClose,
  Power,
  RotateCcw,
  PauseCircle,
  AlertTriangle,
  Mic,
  Trash2,
  Tag as TagIcon,
  ArrowRightLeft,
  MoreHorizontal,
  Check,
} from 'lucide-react';
import { ContactDetailPanel } from '../components/inbox/ContactDetailPanel';
import { CannedResponseAutocomplete } from '../components/inbox/CannedResponseAutocomplete';
import { CannedResponsesTab } from '../components/inbox/CannedResponsesTab';
import { CannedResponseModal } from '../components/inbox/CannedResponseModal';
import { useToast } from '../context/ToastContext';
import { WhatsAppTemplateModal } from '../components/inbox/WhatsAppTemplateModal';
import { MessageDeliveryStatus } from '../components/inbox/MessageDeliveryStatus';
import { AudioMessagePlayer } from '../components/common/AudioMessagePlayer';
import { useVoiceRecorder } from '../hooks/useVoiceRecorder';
import { useInboxNotifications } from '../hooks/useInboxNotifications';
import { ConfirmModal } from '../components/common/ConfirmModal';
import { useAuth } from '../context/useAuth';
import { useIsMobile } from '../hooks/useMediaQuery';
import type Echo from 'laravel-echo';
import { sortAndDeduplicateInboxMessages } from '../utils/conversationUtils';
import { LinkifiedText } from '../components/common/LinkifiedText';
import { resolveMediaUrl } from '../utils/media';
import { SessionDivider } from '../components/common/SessionDivider';
import './ConversationsView.css';

interface InteractiveOption {
  id: string;
  title: string;
}

function extractInteractiveOptions(msg: Message): InteractiveOption[] {
  const rawList =
    (msg.payload as any)?.options ||
    (msg.payload as any)?.buttons ||
    (msg.metadata as any)?.options ||
    (msg.metadata as any)?.buttons ||
    (msg.metadata as any)?.choices;

  if (!Array.isArray(rawList) || rawList.length === 0) {
    return [];
  }

  return rawList
    .map((item, idx) => {
      if (typeof item === 'string') {
        const text = item.trim();
        return text ? { id: String(idx + 1), title: text } : null;
      }
      if (typeof item === 'number') {
        return { id: String(item), title: String(item) };
      }
      if (item && typeof item === 'object') {
        const isHidden = (item as any).is_hidden === true || (item as any).visible === false;
        if (isHidden) {
          return null;
        }
        const title = (item.title || item.text || item.label || item.value || String(item.id || idx + 1)).trim();
        const id = String(item.id ?? idx + 1);
        return title ? { id, title } : null;
      }
      return null;
    })
    .filter((opt): opt is InteractiveOption => opt !== null);
}

export const ConversationsView: React.FC = () => {
  const { showToast } = useToast();
  const { hasPermission, user } = useAuth();
  const isMobile = useIsMobile();
  const [inboxPreferences] = useState(() => {
    try {
      const raw = localStorage.getItem(`intera-inbox-preferences:${user?.id ?? 'guest'}`);
      return raw ? JSON.parse(raw) as {
        activeTab?: string;
        searchQuery?: string;
        selectedAgentFilter?: string;
        selectedChannelFilter?: string;
        selectedTagFilter?: string;
        currentPage?: number;
      } : {};
    } catch {
      return {};
    }
  });
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [tabCounts, setTabCounts] = useState<Record<string, number>>({});
  const [selectedConversation, setSelectedConversation] = useState<Conversation | null>(null);
  const [execution, setExecution] = useState<FlowExecution | null>(null);
  const [showExecutionPanel, setShowExecutionPanel] = useState<boolean>(false);
  const [isContactPanelOpen, setIsContactPanelOpen] = useState<boolean>(() => {
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        // A desktop preference must not make the mobile drawer start open
        // behind the conversation thread, where it can look like it did not open.
        if (window.innerWidth <= 768) return false;
        const saved = window.localStorage.getItem('conexa_contact_panel_open');
        if (saved !== null) {
          return saved === 'true';
        }
        return window.innerWidth > 1024;
      }
    } catch {
      // fallback
    }
    return true;
  });
  const [activeTab, setActiveTab] = useState<string>(inboxPreferences.activeTab || 'all');
  const [loading, setLoading] = useState(true);
  const [replyText, setReplyText] = useState('');
  const [sending, setSending] = useState(false);
  const [claiming, setClaiming] = useState(false);
  const [closing, setClosing] = useState(false);
  const [togglingBot, setTogglingBot] = useState(false);
  const [restartingFlow, setRestartingFlow] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [hasMoreMessages, setHasMoreMessages] = useState(false);
  const [oldestMessageId, setOldestMessageId] = useState<string | null>(null);
  const [agents, setAgents] = useState<User[]>([]);
  const [channels, setChannels] = useState<{ id: string; name: string }[]>([]);
  const [searchQuery, setSearchQuery] = useState(inboxPreferences.searchQuery || '');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [selectedAgentFilter, setSelectedAgentFilter] = useState<string>(inboxPreferences.selectedAgentFilter || 'all');
  const [selectedChannelFilter, setSelectedChannelFilter] = useState<string>(inboxPreferences.selectedChannelFilter || 'all');
  const [realtimeStatus, setRealtimeStatus] = useState<'connecting' | 'connected' | 'offline'>('connecting');
  const [echoReadyVersion, setEchoReadyVersion] = useState(0);
  const [currentPage, setCurrentPage] = useState<number>(Math.max(1, Number(inboxPreferences.currentPage) || 1));
  const [lastPage, setLastPage] = useState(1);
  const [totalConversations, setTotalConversations] = useState(0);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [filePreviewUrl, setFilePreviewUrl] = useState<string | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);
  const [newChatAlert, setNewChatAlert] = useState<{ id: string; name: string } | null>(null);
  const alertTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const [centerTab, setCenterTab] = useState<'chat' | 'canned'>('chat');
  const [cannedResponses, setCannedResponses] = useState<CannedResponse[]>([]);
  const [loadingCanned, setLoadingCanned] = useState(false);
  const [isSlashMenuOpen, setIsSlashMenuOpen] = useState(false);
  const [slashFilter, setSlashFilter] = useState('');
  const [selectedSlashIndex, setSelectedSlashIndex] = useState(0);
  const [cannedModalOpen, setCannedModalOpen] = useState(false);
  const [editingCanned, setEditingCanned] = useState<CannedResponse | null>(null);
  const [isTemplateModalOpen, setIsTemplateModalOpen] = useState(false);
  const [isRestartFlowModalOpen, setIsRestartFlowModalOpen] = useState(false);
  const [isCloseConversationModalOpen, setIsCloseConversationModalOpen] = useState(false);
  const replyInputRef = useRef<HTMLInputElement | null>(null);

  // Tags & Transfer states
  const [allTags, setAllTags] = useState<Tag[]>([]);
  const [selectedTagFilter, setSelectedTagFilter] = useState<string>(inboxPreferences.selectedTagFilter || 'all');
  const selectedTagFilterRef = useRef(selectedTagFilter);
  const [showTagDropdown, setShowTagDropdown] = useState(false);
  const [newTagName, setNewTagName] = useState('');
  const [newTagColor, setNewTagColor] = useState('#5d8f82');
  const [creatingTag, setCreatingTag] = useState(false);

  const [showTransferModal, setShowTransferModal] = useState(false);
  const [transferTargetUserId, setTransferTargetUserId] = useState<number | null>(null);
  const [transferNote, setTransferNote] = useState('');
  const [transferring, setTransferring] = useState(false);
  const [currentNow, setCurrentNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = setInterval(() => setCurrentNow(Date.now()), 15000);
    return () => clearInterval(timer);
  }, []);

  const formatRemainingTime = (seconds: number | null | undefined, expiresAt?: string | null): string => {
    let effectiveSeconds = seconds ?? 0;
    if (expiresAt) {
      const diff = Math.floor((new Date(expiresAt).getTime() - currentNow) / 1000);
      effectiveSeconds = Math.max(0, diff);
    }
    if (effectiveSeconds <= 0) return '0m';
    const hours = Math.floor(effectiveSeconds / 3600);
    const minutes = Math.floor((effectiveSeconds % 3600) / 60);
    if (hours > 0) {
      return `${hours}h ${minutes}m`;
    }
    return `${minutes}m`;
  };



  useEffect(() => {
    return () => {
      if (alertTimerRef.current) {
        clearTimeout(alertTimerRef.current);
      }
    };
  }, []);

  const echoRef = useRef<Echo<any> | null>(null);
  const messagesEndRef = useRef<HTMLDivElement | null>(null);
  const messagesContainerRef = useRef<HTMLDivElement | null>(null);
  const messagesContentRef = useRef<HTMLDivElement | null>(null);
  const userScrolledUpRef = useRef<boolean>(false);
  const selectedConversationRef = useRef<Conversation | null>(null);
  const prevConversationIdRef = useRef<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const activeTabRef = useRef(activeTab);
  const selectedAgentFilterRef = useRef(selectedAgentFilter);
  const selectedChannelFilterRef = useRef(selectedChannelFilter);
  const debouncedSearchRef = useRef(debouncedSearch);
  const currentPageRef = useRef(currentPage);
  const requestSeqRef = useRef(0);
  const firstFilterLoadRef = useRef(true);

  const [cannedToDelete, setCannedToDelete] = useState<{ id: string; title: string } | null>(null);

  useEffect(() => {
    currentPageRef.current = currentPage;
  }, [currentPage]);

  useEffect(() => {
    selectedConversationRef.current = selectedConversation;
  }, [selectedConversation]);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  const handleScroll = useCallback(() => {
    const el = messagesContainerRef.current;
    if (!el) return;
    const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
    // User is considered scrolled up if > 150px away from the bottom
    userScrolledUpRef.current = distanceFromBottom > 150;
  }, []);

  const scrollToBottom = useCallback((behavior: ScrollBehavior = 'smooth', force = false) => {
    if (!force && userScrolledUpRef.current) {
      return;
    }
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        const container = messagesContainerRef.current;
        if (container) {
          if (typeof container.scrollTo === 'function') {
            container.scrollTo({
              top: container.scrollHeight,
              behavior,
            });
          } else {
            container.scrollTop = container.scrollHeight;
          }
        }
        if (messagesEndRef.current?.scrollIntoView) {
          messagesEndRef.current.scrollIntoView({ behavior, block: 'end' });
        }
      });
    });
  }, []);

  const isSupervisorOrAdmin = hasPermission('conversations.view_all');
  const isSupervisorOrAdminRef = useRef(isSupervisorOrAdmin);

  useEffect(() => {
    activeTabRef.current = activeTab;
    selectedAgentFilterRef.current = selectedAgentFilter;
    selectedChannelFilterRef.current = selectedChannelFilter;
    selectedTagFilterRef.current = selectedTagFilter;
    debouncedSearchRef.current = debouncedSearch;
    isSupervisorOrAdminRef.current = isSupervisorOrAdmin;
  }, [activeTab, selectedAgentFilter, selectedChannelFilter, selectedTagFilter, debouncedSearch, isSupervisorOrAdmin]);

  useEffect(() => {
    try {
      localStorage.setItem(`intera-inbox-preferences:${user?.id ?? 'guest'}`, JSON.stringify({
        activeTab,
        searchQuery,
        selectedAgentFilter,
        selectedChannelFilter,
        selectedTagFilter,
        currentPage,
      }));
    } catch {
      // Keep the inbox usable when browser storage is unavailable.
    }
  }, [user?.id, activeTab, searchQuery, selectedAgentFilter, selectedChannelFilter, selectedTagFilter, currentPage]);

  const matchesCurrentFilters = React.useCallback((conv: Partial<Conversation>) => {
    const tab = activeTabRef.current;
    if (tab === 'all' && conv.status === 'closed') return false;
    if (tab !== 'all' && conv.status !== tab) return false;

    const agentFilter = selectedAgentFilterRef.current;
    if (agentFilter === 'unassigned' && conv.assigned_user_id) return false;
    if (agentFilter !== 'all' && agentFilter !== 'unassigned' && String(conv.assigned_user_id) !== agentFilter) return false;

    const chFilter = selectedChannelFilterRef.current;
    if (chFilter !== 'all' && conv.channel_id !== chFilter) return false;

    const tagFilter = selectedTagFilterRef.current;
    if (tagFilter !== 'all') {
      const hasTag = conv.tags?.some((t: any) =>
        typeof t === 'string' ? t === tagFilter : t.id === tagFilter || t.name === tagFilter
      );
      if (!hasTag) return false;
    }

    const search = debouncedSearchRef.current.toLowerCase().trim();
    if (search) {
      const name = conv.contact?.display_name?.toLowerCase() || '';
      const email = conv.contact?.email?.toLowerCase() || '';
      const phone = conv.contact?.phone?.toLowerCase() || '';
      const ext = conv.contact?.external_identifier?.toLowerCase() || '';
      if (!name.includes(search) && !email.includes(search) && !phone.includes(search) && !ext.includes(search)) {
        return false;
      }
    }
    return true;
  }, []);

  const fetchConversations = React.useCallback(
    async (preserveSelection = true, pageToLoad?: number) => {
      const seq = ++requestSeqRef.current;
      try {
        const targetPage = pageToLoad ?? currentPageRef.current;
        const params: {
          status?: string;
          assigned_user_id?: string;
          channel_id?: string;
          tag_id?: string;
          search?: string;
          page?: number;
        } = {
          page: targetPage,
        };

        if (activeTab && activeTab !== 'all') {
          params.status = activeTab;
        }
        if (selectedAgentFilter && selectedAgentFilter !== 'all') {
          params.assigned_user_id = selectedAgentFilter;
        }
        if (selectedChannelFilter && selectedChannelFilter !== 'all') {
          params.channel_id = selectedChannelFilter;
        }
        if (selectedTagFilter && selectedTagFilter !== 'all') {
          params.tag_id = selectedTagFilter;
        }
        if (debouncedSearch && debouncedSearch.trim()) {
          params.search = debouncedSearch.trim();
        }

        const res = await api.getConversations(params);
        if (seq !== requestSeqRef.current) return;
        setConversations(res.data);
        setCurrentPage(res.current_page || 1);
        setLastPage(res.last_page || 1);
        setTotalConversations(res.total ?? res.data.length);
        if (res.counts) {
          setTabCounts(res.counts);
        }
        if (preserveSelection && selectedConversationRef.current) {
          if (selectedConversationRef.current.status !== 'closed') {
            const stillExists = res.data.some((c) => c.id === selectedConversationRef.current?.id);
            if (!stillExists) {
              setSelectedConversation(null);
            }
          }
        }
      } catch (err: any) {
        if (seq !== requestSeqRef.current) return;
        console.error(err);
      } finally {
        if (seq === requestSeqRef.current) {
          setLoading(false);
        }
      }
    },
    [activeTab, selectedAgentFilter, selectedChannelFilter, selectedTagFilter, debouncedSearch]
  );
  const fetchConversationsRef = useRef(fetchConversations);
  fetchConversationsRef.current = fetchConversations;

  const loadConversationDetails = async (id: string) => {
    try {
      if (selectedConversationRef.current?.id !== id) {
        userScrolledUpRef.current = false;
      }
      const res = await api.getConversation(id);
      setSelectedConversation(res.data);
      if (res.messages) {
        setHasMoreMessages(Boolean(res.messages.has_more));
        setOldestMessageId(res.messages.oldest_message_id || null);
      } else {
        setHasMoreMessages(false);
        setOldestMessageId(null);
      }

      // Clear unread count for this conversation in list
      setConversations((prev) =>
        prev.map((c) => (c.id === id ? { ...c, unread_count: 0 } : c))
      );

      // Mark read in backend
      api.markConversationAsRead(id).catch(() => {});

      try {
        const execRes = await api.getConversationExecution(id);
        setExecution(execRes.data);
      } catch {
        setExecution(null);
      }
      scrollToBottom('auto', true);
    } catch (err: any) {
      console.error(err);
    }
  };

  const unreadOrWaitingCount = useMemo(() => {
    const activeAttentionIds = new Set<string>();
    conversations.forEach((c) => {
      if (
        c.status === 'waiting_agent' ||
        (c.status as string) === 'waiting_human' ||
        (c.unread_count && c.unread_count > 0)
      ) {
        activeAttentionIds.add(c.id);
      }
    });

    const waitingFromTab = tabCounts['waiting_agent'] || 0;
    const unreadNonWaiting = conversations.filter(
      (c) =>
        c.unread_count &&
        c.unread_count > 0 &&
        c.status !== 'waiting_agent' &&
        (c.status as string) !== 'waiting_human'
    ).length;

    return Math.max(activeAttentionIds.size, waitingFromTab + unreadNonWaiting);
  }, [tabCounts, conversations]);

  const inboxNotifications = useInboxNotifications({
    unreadOrWaitingCount,
    onSelectConversation: (id) => {
      loadConversationDetails(id);
    },
  });

  const inboxNotificationsRef = useRef(inboxNotifications);
  useEffect(() => {
    inboxNotificationsRef.current = inboxNotifications;
  }, [inboxNotifications]);

  const conversationsRef = useRef(conversations);
  useEffect(() => {
    conversationsRef.current = conversations;
  }, [conversations]);

  const userRef = useRef(user);
  useEffect(() => {
    userRef.current = user;
  }, [user]);

  const triggerNewChatNotification = React.useCallback((id: string, name: string) => {
    inboxNotificationsRef.current.playAssignmentSound();
    inboxNotificationsRef.current.notifyDesktop({
      title: '🔔 Nueva conversación asignada',
      body: name,
      conversationId: id,
    });
    setNewChatAlert({ id, name });
    if (alertTimerRef.current) {
      clearTimeout(alertTimerRef.current);
    }
    alertTimerRef.current = setTimeout(() => {
      setNewChatAlert(null);
    }, 8000);
  }, []);

  const triggerWaitingQueueNotification = React.useCallback((id: string, name: string) => {
    inboxNotificationsRef.current.playAssignmentSound();
    inboxNotificationsRef.current.notifyDesktop({
      title: '⏳ Conversación esperando agente',
      body: name ? `El contacto ${name} necesita atención de un operador.` : 'Conversación en espera de agente',
      conversationId: id,
    });
    setNewChatAlert({ id, name: `⏳ En espera: ${name}` });
    if (alertTimerRef.current) {
      clearTimeout(alertTimerRef.current);
    }
    alertTimerRef.current = setTimeout(() => {
      setNewChatAlert(null);
    }, 8000);
  }, []);

  const handleLoadOlderMessages = async () => {
    if (!selectedConversation || !oldestMessageId || loadingMore) return;
    setLoadingMore(true);
    const container = messagesContainerRef.current;
    const prevScrollHeight = container?.scrollHeight || 0;
    const prevScrollTop = container?.scrollTop || 0;

    try {
      const res = await api.getConversationMessages(selectedConversation.id, {
        before: oldestMessageId,
        limit: 50,
      });

      setSelectedConversation((prev) => {
        if (!prev) return null;
        const existingIds = new Set((prev.messages || []).map((m) => m.id));
        const newUnique = res.data.filter((m) => !existingIds.has(m.id));
        return {
          ...prev,
          messages: [...newUnique, ...(prev.messages || [])],
        };
      });

      setHasMoreMessages(Boolean(res.has_more));
      setOldestMessageId(res.oldest_message_id || null);

      requestAnimationFrame(() => {
        if (container) {
          container.scrollTop = container.scrollHeight - prevScrollHeight + prevScrollTop;
        }
      });
    } catch (err: any) {
      showToast(err.message || 'Error al cargar mensajes anteriores.', 'error');
    } finally {
      setLoadingMore(false);
    }
  };

  const handleClaim = async (convId?: string) => {
    const id = convId || selectedConversation?.id;
    if (!id) return;
    setClaiming(true);
    try {
      const res = await api.claimConversation(id);
      if (selectedConversation?.id === id) {
        setSelectedConversation(res.data);
      }
      fetchConversations(true);
    } catch (err: any) {
      if (err.status === 409 || err.code === 'conversation_already_claimed') {
        showToast('Esta conversación ya fue tomada por otro agente.', 'error');
        if (selectedConversation?.id === id) {
          setSelectedConversation(null);
        }
      } else {
        showToast(err.message || 'Error al tomar la conversación.', 'error');
      }
      fetchConversations(true);
    } finally {
      setClaiming(false);
    }
  };

  const handleClose = () => {
    if (!selectedConversation || closing) return;
    setIsCloseConversationModalOpen(true);
  };

  const handleConfirmClose = async () => {
    if (!selectedConversation || closing) return;
    setClosing(true);
    try {
      const res = await api.closeConversation(selectedConversation.id);
      setIsCloseConversationModalOpen(false);
      setSelectedConversation((prev) =>
        prev ? { ...prev, status: 'closed', closed_at: res.data.closed_at } : null
      );
      fetchConversations(true);
    } catch (err: any) {
      showToast(err.message || 'Error al cerrar conversación.', 'error');
    } finally {
      setClosing(false);
    }
  };

  const handleToggleBot = async () => {
    if (!selectedConversation || togglingBot) return;
    const currentEnabled = selectedConversation.bot_enabled !== false;
    const nextEnabled = !currentEnabled;

    // Optimistic update
    setSelectedConversation((prev) => (prev ? { ...prev, bot_enabled: nextEnabled } : null));
    setConversations((prev) =>
      prev.map((c) => (c.id === selectedConversation.id ? { ...c, bot_enabled: nextEnabled } : c))
    );

    setTogglingBot(true);
    try {
      const res = await api.toggleConversationBot(selectedConversation.id);
      if (res?.data) {
        setSelectedConversation((prev) => (prev ? { ...prev, ...res.data } : null));
        setConversations((prev) =>
          prev.map((c) => (c.id === selectedConversation.id ? { ...c, ...res.data } : c))
        );
      }
    } catch (err: any) {
      // Revert optimistic update
      setSelectedConversation((prev) => (prev ? { ...prev, bot_enabled: currentEnabled } : null));
      setConversations((prev) =>
        prev.map((c) => (c.id === selectedConversation.id ? { ...c, bot_enabled: currentEnabled } : c))
      );
      showToast(err.message || 'Error al cambiar estado del bot.', 'error');
    } finally {
      setTogglingBot(false);
    }
  };

  const handleRestartFlow = () => {
    if (!selectedConversation || restartingFlow) return;
    setIsRestartFlowModalOpen(true);
  };

  const handleConfirmRestartFlow = async () => {
    if (!selectedConversation || restartingFlow) return;

    setRestartingFlow(true);
    try {
      const res = await api.restartConversationFlow(selectedConversation.id);
      setIsRestartFlowModalOpen(false);
      if (res?.data?.conversation) {
        const updated = res.data.conversation;
        setSelectedConversation((prev) => (prev ? { ...prev, ...updated } : null));
        setConversations((prev) =>
          prev.map((c) => (c.id === selectedConversation.id ? { ...c, ...updated } : c))
        );
        // Refresh conversation messages from API
        api.getConversation(selectedConversation.id).then((fresh) => {
          if (fresh?.data) {
            setSelectedConversation(fresh.data);
          }
        }).catch(() => {});
      }
    } catch (err: any) {
      showToast(err.message || 'Error al reiniciar el flujo.', 'error');
    } finally {
      setRestartingFlow(false);
    }
  };

  const handleOpenContactPanel = () => {
    setIsContactPanelOpen(true);
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        window.localStorage.setItem('conexa_contact_panel_open', 'true');
      }
    } catch {
      // ignore
    }
  };

  const handleCloseContactPanel = useCallback(() => {
    setIsContactPanelOpen(false);
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        window.localStorage.setItem('conexa_contact_panel_open', 'false');
      }
    } catch {
      // ignore
    }
  }, []);

  const handleContactUpdated = (updatedContact: Contact) => {
    const newDisplayName = updatedContact.name || updatedContact.display_name || null;
    setSelectedConversation((prev) => {
      if (!prev) return null;
      return {
        ...prev,
        contact: {
          ...prev.contact,
          ...updatedContact,
          display_name: newDisplayName || prev.contact?.display_name || null,
        },
      };
    });
    setConversations((prev) =>
      prev.map((c) =>
        c.contact_id === updatedContact.id || c.contact?.id === updatedContact.id || c.id === selectedConversationRef.current?.id
          ? {
              ...c,
              contact: {
                ...c.contact,
                ...updatedContact,
                display_name: newDisplayName || c.contact?.display_name || null,
              },
            }
          : c
      )
    );
  };

  const handleTagAdded = (newTag: string) => {
    setSelectedConversation((prev) => {
      if (!prev) return null;
      const currentTags = prev.tags || [];
      if (currentTags.includes(newTag)) return prev;
      const updatedTags = [...currentTags, newTag];
      return {
        ...prev,
        tags: updatedTags,
        metadata: {
          ...(prev.metadata || {}),
          tags: updatedTags,
        },
      };
    });
  };

  const handleTagRemoved = (tagToRemove: string) => {
    setSelectedConversation((prev) => {
      if (!prev) return null;
      const currentTags = prev.tags || [];
      const updatedTags = currentTags.filter((t) => t !== tagToRemove);
      return {
        ...prev,
        tags: updatedTags,
        metadata: {
          ...(prev.metadata || {}),
          tags: updatedTags,
        },
      };
    });
  };

  const handleNoteAdded = (note: InternalNote) => {
    setSelectedConversation((prev) => {
      if (!prev) return null;
      return {
        ...prev,
        internal_notes: [note, ...(prev.internal_notes || [])],
      };
    });
  };

  const canManageCanned = useMemo(() => {
    if (hasPermission('canned_responses.manage')) return true;
    if (hasPermission('conversations.view_all')) return true;
    const roles = user?.roles || [];
    return roles.some((r) => {
      const name = typeof r === 'string' ? r : r.name;
      return ['super_admin', 'admin', 'supervisor'].includes(name);
    });
  }, [hasPermission, user]);

  useEffect(() => {
    let isMounted = true;
    if (typeof api.getCannedResponses === 'function') {
      api.getCannedResponses({ include_inactive: canManageCanned })
        .then((res) => {
          if (isMounted) setCannedResponses(res.data);
        })
        .catch((err) => console.error('Error cargando respuestas rápidas:', err))
        .finally(() => {
          if (isMounted) setLoadingCanned(false);
        });
    }

    return () => {
      isMounted = false;
    };
  }, [canManageCanned]);

  const handleReplyChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    setReplyText(val);

    const cursor = e.target.selectionEnd ?? val.length;
    const beforeCursor = val.slice(0, cursor);

    const slashMatch = beforeCursor.match(/(?:^|\s)\/([^\s/]*)$/);

    if (slashMatch) {
      setSlashFilter(slashMatch[1]);
      setIsSlashMenuOpen(true);
      setSelectedSlashIndex(0);
    } else {
      setIsSlashMenuOpen(false);
    }
  };

  const handleSelectCannedResponse = (response: CannedResponse) => {
    setReplyText((prev) => {
      if (/(?:^|\s)\/[^\s/]*$/.test(prev)) {
        return prev.replace(/(?:^|\s)\/[^\s/]*$/, (match) => {
          const leadingSpace = match.startsWith(' ') ? ' ' : '';
          return leadingSpace + response.content;
        });
      }
      return prev ? `${prev} ${response.content}` : response.content;
    });
    setIsSlashMenuOpen(false);
    setTimeout(() => {
      replyInputRef.current?.focus();
    }, 50);
  };

  const handleToggleCannedMenu = () => {
    if (isSlashMenuOpen) {
      setIsSlashMenuOpen(false);
      return;
    }

    setSlashFilter('');
    setIsSlashMenuOpen(true);
    setSelectedSlashIndex(0);

    if (!replyText.endsWith('/')) {
      setReplyText((prev) => (prev ? (prev.endsWith(' ') ? `${prev}/` : `${prev} /`) : '/'));
    }

    setTimeout(() => {
      replyInputRef.current?.focus();
    }, 10);
  };

  const handleReplyKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (!isSlashMenuOpen) return;

    const cleanFilter = slashFilter.toLowerCase().trim();
    const filtered = cannedResponses
      .filter((r) => r.is_active !== false)
      .filter((r) => {
        if (!cleanFilter) return true;
        const shortcutMatch = r.shortcut ? r.shortcut.toLowerCase().includes(cleanFilter) : false;
        const titleMatch = r.title.toLowerCase().includes(cleanFilter);
        const contentMatch = r.content.toLowerCase().includes(cleanFilter);
        return shortcutMatch || titleMatch || contentMatch;
      });

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (filtered.length > 0) {
        setSelectedSlashIndex((prev) => (prev + 1) % filtered.length);
      }
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (filtered.length > 0) {
        setSelectedSlashIndex((prev) => (prev - 1 + filtered.length) % filtered.length);
      }
    } else if (e.key === 'Enter' || e.key === 'Tab') {
      if (filtered.length > 0 && selectedSlashIndex >= 0 && selectedSlashIndex < filtered.length) {
        e.preventDefault();
        handleSelectCannedResponse(filtered[selectedSlashIndex]);
      }
    } else if (e.key === 'Escape') {
      e.preventDefault();
      setIsSlashMenuOpen(false);
    }
  };

  const handleUseInChat = (content: string) => {
    setReplyText((prev) => (prev ? `${prev} ${content}` : content));
    setCenterTab('chat');
    setTimeout(() => {
      replyInputRef.current?.focus();
    }, 100);
  };

  const handleOpenCreateCanned = () => {
    setEditingCanned(null);
    setCannedModalOpen(true);
  };

  const handleOpenEditCanned = (item: CannedResponse) => {
    setEditingCanned(item);
    setCannedModalOpen(true);
  };

  const handleDeleteCanned = (id: string) => {
    const cannedResponse = cannedResponses.find((response) => response.id === id);
    if (cannedResponse) setCannedToDelete({ id: cannedResponse.id, title: cannedResponse.title });
  };

  const executeDeleteCanned = async () => {
    if (!cannedToDelete) return;
    try {
      await api.deleteCannedResponse(cannedToDelete.id);
      setCannedResponses((prev) => prev.filter((r) => r.id !== cannedToDelete.id));
      showToast('Respuesta rápida eliminada exitosamente.', 'success');
    } catch (err: any) {
      showToast(err.message || 'Error al eliminar la respuesta rápida.', 'error');
    } finally {
      setCannedToDelete(null);
    }
  };

  const handleSavedCanned = (saved: CannedResponse) => {
    setCannedResponses((prev) => {
      const exists = prev.some((r) => r.id === saved.id);
      if (exists) {
        return prev.map((r) => (r.id === saved.id ? saved : r));
      }
      return [saved, ...prev];
    });
  };

  useEffect(() => {
    let isMounted = true;
    const seq = ++requestSeqRef.current;
    const initialPage = firstFilterLoadRef.current ? currentPage : 1;
    firstFilterLoadRef.current = false;
    currentPageRef.current = initialPage;
    setCurrentPage(initialPage);
    const params: {
      status?: string;
      assigned_user_id?: string;
      channel_id?: string;
      tag_id?: string;
      search?: string;
      page?: number;
    } = { page: initialPage };

    if (activeTab && activeTab !== 'all') {
      params.status = activeTab;
    }
    if (selectedAgentFilter && selectedAgentFilter !== 'all') {
      params.assigned_user_id = selectedAgentFilter;
    }
    if (selectedChannelFilter && selectedChannelFilter !== 'all') {
      params.channel_id = selectedChannelFilter;
    }
    if (selectedTagFilter && selectedTagFilter !== 'all') {
      params.tag_id = selectedTagFilter;
    }
    if (debouncedSearch && debouncedSearch.trim()) {
      params.search = debouncedSearch.trim();
    }

    api.getConversations(params)
      .then((res) => {
        if (!isMounted || seq !== requestSeqRef.current) return;
        setConversations(res.data);
        setCurrentPage(res.current_page || 1);
        setLastPage(res.last_page || 1);
        setTotalConversations(res.total ?? res.data.length);
        if (res.counts) {
          setTabCounts(res.counts);
        }
        if (selectedConversationRef.current) {
          if (selectedConversationRef.current.status !== 'closed') {
            const stillExists = res.data.some((c) => c.id === selectedConversationRef.current?.id);
            if (!stillExists) {
              setSelectedConversation(null);
            }
          }
        }
      })
      .catch((err) => {
        if (isMounted && seq === requestSeqRef.current) console.error(err);
      })
      .finally(() => {
        if (isMounted && seq === requestSeqRef.current) setLoading(false);
      });

    return () => {
      isMounted = false;
    };
  }, [activeTab, selectedAgentFilter, selectedChannelFilter, selectedTagFilter, debouncedSearch]);

  const goToPage = (page: number) => {
    if (page < 1 || page > lastPage || loading) return;
    setCurrentPage(page);
    currentPageRef.current = page;
    setLoading(true);
    fetchConversations(true, page);
  };

  useEffect(() => {
    let isMounted = true;
    if (hasPermission('conversations.assign') || hasPermission('conversations.reply') || isSupervisorOrAdmin) {
      api.getUsers()
        .then((res) => {
          if (isMounted) setAgents(res.data);
        })
        .catch((err) => console.error(err));
    }
    if (hasPermission('channels.view') || hasPermission('channels.manage')) {
      api.getChannels()
        .then((res) => {
          if (isMounted) setChannels(res.data);
        })
        .catch((err) => console.error(err));
    }
    if (typeof api.getTags === 'function') {
      api.getTags()
        .then((res) => {
          if (isMounted) setAllTags(res.data || []);
        })
        .catch((err) => console.error(err));
    }

    return () => {
      isMounted = false;
    };
  }, [hasPermission, isSupervisorOrAdmin]);

  // Auto-scroll when selected conversation changes (immediate 'auto' scroll)
  useEffect(() => {
    if (selectedConversation?.id) {
      const isNewConversation = prevConversationIdRef.current !== selectedConversation.id;
      prevConversationIdRef.current = selectedConversation.id;
      if (isNewConversation) {
        userScrolledUpRef.current = false;
        scrollToBottom('auto', true);
      }
    } else {
      prevConversationIdRef.current = null;
    }
  }, [selectedConversation?.id, scrollToBottom]);

  // Auto-scroll when new messages arrive or are updated
  useEffect(() => {
    if (!userScrolledUpRef.current) {
      scrollToBottom('smooth');
    }
  }, [selectedConversation?.messages?.length, scrollToBottom]);

  // ResizeObserver: auto-scroll when message container or contents resize (e.g. interactive bot options, images rendered)
  useEffect(() => {
    const target = messagesContentRef.current || messagesContainerRef.current;
    if (!target || typeof ResizeObserver === 'undefined') return;

    const observer = new ResizeObserver(() => {
      if (!userScrolledUpRef.current) {
        scrollToBottom('smooth');
      }
    });

    observer.observe(target);
    return () => {
      observer.disconnect();
    };
  }, [scrollToBottom]);

  // Setup Realtime Echo for general inbox & user assignments
  useEffect(() => {
    let cancelled = false;
    let activeEcho: Echo<any> | null = null;

    const setupRealtime = async () => {
      const echo = await createEcho({
        authEndpoint: '/api/v1/broadcasting/auth',
      });
      if (cancelled) {
        echo.disconnect();
        return;
      }
      activeEcho = echo;
      echoRef.current = echo;
      setEchoReadyVersion((version) => version + 1);

      const connection = echo.connector?.pusher?.connection;
      connection?.bind?.('connected', () => {
        setRealtimeStatus('connected');
      });

      connection?.bind?.('connecting', () => setRealtimeStatus('connecting'));
      const markDisconnected = () => setRealtimeStatus('offline');
      connection?.bind?.('disconnected', markDisconnected);
      connection?.bind?.('unavailable', markDisconnected);
      connection?.bind?.('failed', markDisconnected);

      const inboxChannel = echo.private('inbox');

    inboxChannel.listen('.ConversationCreated', (event: any) => {
      if (!isSupervisorOrAdminRef.current && event.status === 'bot') {
        return;
      }
      const newConv: Conversation = {
        id: event.id,
        channel_id: event.channel_id,
        channel: event.channel,
        contact_id: event.contact_id,
        contact: event.contact,
        status: event.status,
        assigned_user_id: null,
        active_flow_id: null,
        closed_at: null,
        started_at: event.started_at,
        last_message_at: event.last_message_at,
        created_at: event.created_at,
        updated_at: event.created_at,
      };
      if (matchesCurrentFilters(newConv)) {
        setConversations((prev) => {
          if (prev.some((c) => c.id === event.id)) return prev;
          return [newConv, ...prev];
        });
      }
    });

    inboxChannel.listen('.ConversationWaitingAgent', (event: any) => {
      triggerWaitingQueueNotification(event.id, event.contact?.display_name || 'Nuevo contacto');
      const nowIso = new Date().toISOString();
      const newConv: Conversation = {
        id: event.id,
        channel_id: event.channel_id || '',
        channel: event.channel,
        contact_id: event.contact_id,
        contact: event.contact,
        status: 'waiting_agent',
        assigned_user_id: null,
        active_flow_id: null,
        closed_at: null,
        started_at: event.started_at || nowIso,
        last_message_at: event.last_message_at || nowIso,
        created_at: event.created_at || nowIso,
        updated_at: event.updated_at || nowIso,
      };

      setConversations((prev) => {
        const exists = prev.some((c) => c.id === event.id);
        if (exists) {
          return prev.map((c) => (c.id === event.id ? { ...c, status: 'waiting_agent' } : c));
        }
        if (matchesCurrentFilters(newConv)) {
          return [newConv, ...prev];
        }
        return prev;
      });
      if (selectedConversationRef.current?.id === event.id) {
        setSelectedConversation((prev) => (prev ? { ...prev, status: 'waiting_agent' } : null));
        api.getConversationExecution(event.id).then((res) => setExecution(res.data)).catch(() => {});
      }
    });

    inboxChannel.listen('.ContactUpdated', (event: any) => {
      const updatedContact = event.contact;
      const convId = event.conversation_id;
      setConversations((prev) =>
        prev.map((c) => {
          if (c.id === convId || c.contact_id === updatedContact?.id || c.contact?.id === updatedContact?.id) {
            return {
              ...c,
              contact: {
                ...c.contact,
                ...updatedContact,
              },
            };
          }
          return c;
        })
      );
      if (
        selectedConversationRef.current &&
        (selectedConversationRef.current.id === convId ||
          selectedConversationRef.current.contact_id === updatedContact?.id ||
          selectedConversationRef.current.contact?.id === updatedContact?.id)
      ) {
        setSelectedConversation((prev) =>
          prev
            ? {
                ...prev,
                contact: {
                  ...prev.contact,
                  ...updatedContact,
                },
              }
            : null
        );
      }
    });

    inboxChannel.listen('.ConversationStatusChanged', (event: any) => {
      setConversations((prev) =>
        prev.map((c) => (c.id === event.id ? { ...c, status: event.status } : c))
      );
      if (selectedConversationRef.current?.id === event.id) {
        setSelectedConversation((prev) => (prev ? { ...prev, status: event.status } : null));
      }
      if (event.status === 'closed' || event.previous_status === 'closed') {
        fetchConversationsRef.current(true);
      }
    });

    inboxChannel.listen('.ConversationUpdated', (event: any) => {
      const isAgentOnly = !hasPermission('conversations.view_all');
      const isAssignedToCurrent = event.assigned_user_id === user?.id;
      const isClosingAssignedToCurrent = event.status === 'closed' && event.closing_assigned_user_id === user?.id;

      if (isAgentOnly && !isAssignedToCurrent && !isClosingAssignedToCurrent) {
        // Agent should not see foreign or unassigned conversations
        setConversations((prev) => prev.filter((c) => c.id !== event.id));
        if (selectedConversationRef.current?.id === event.id) {
          setSelectedConversation(null);
        }
        return;
      }

      setConversations((prev) => {
        const existing = prev.find((c) => c.id === event.id);
        const wasAssignedToMe = existing?.assigned_user_id === user?.id;

        if (isAssignedToCurrent && !wasAssignedToMe) {
          triggerNewChatNotification(event.id, event.contact?.display_name || 'Nuevo contacto');
        } else if (
          (event.status === 'waiting_agent' || event.status === 'waiting_human') &&
          existing?.status !== event.status
        ) {
          triggerWaitingQueueNotification(event.id, event.contact?.display_name || 'Nuevo contacto');
        }

        if (existing) {
          return prev.map((c) =>
            c.id === event.id
              ? {
                  ...c,
                  status: event.status || c.status,
                  assigned_user_id: event.assigned_user_id,
                  assigned_user: event.assigned_user ?? c.assigned_user,
                  last_message_at: event.last_message_at || c.last_message_at,
                  channel: event.channel ?? c.channel,
                  contact: event.contact ?? c.contact,
                  closed_at: event.status && event.status !== 'closed' ? null : (event.closed_at ?? c.closed_at),
                  whatsapp_window: event.whatsapp_window ?? event.meta_window ?? c.whatsapp_window,
                  meta_window: event.meta_window ?? event.whatsapp_window ?? c.meta_window,
                  closing_assigned_user_id: event.closing_assigned_user_id ?? (event.status && event.status !== 'closed' ? null : c.closing_assigned_user_id),
                }
              : c
          );
        }

        const newConv: Conversation = {
          id: event.id,
          channel_id: event.channel_id || '',
          channel: event.channel,
          contact_id: event.contact_id,
          contact: event.contact,
          status: event.status || 'assigned',
          assigned_user_id: event.assigned_user_id,
          assigned_user: event.assigned_user,
          closing_assigned_user_id: event.closing_assigned_user_id,
          active_flow_id: null,
          closed_at: event.status && event.status !== 'closed' ? null : (event.closed_at ?? null),
          started_at: event.started_at || new Date().toISOString(),
          last_message_at: event.last_message_at || new Date().toISOString(),
          created_at: event.created_at || new Date().toISOString(),
          updated_at: event.updated_at || new Date().toISOString(),
          whatsapp_window: event.whatsapp_window ?? event.meta_window,
          meta_window: event.meta_window ?? event.whatsapp_window,
        };

        if (matchesCurrentFilters(newConv)) {
          return [newConv, ...prev];
        }
        return prev;
      });

      if (selectedConversationRef.current?.id === event.id) {
        setSelectedConversation((prev) =>
          prev
            ? {
                ...prev,
                status: event.status || prev.status,
                assigned_user_id: event.assigned_user_id,
                assigned_user: event.assigned_user ?? prev.assigned_user,
                closing_assigned_user_id: event.closing_assigned_user_id ?? (event.status && event.status !== 'closed' ? null : prev.closing_assigned_user_id),
                closed_at: event.status && event.status !== 'closed' ? null : (event.closed_at ?? prev.closed_at),
                channel: event.channel ?? prev.channel,
                contact: event.contact ?? prev.contact,
                whatsapp_window: event.whatsapp_window ?? event.meta_window ?? prev.whatsapp_window,
                meta_window: event.meta_window ?? event.whatsapp_window ?? prev.meta_window,
              }
            : null
        );
      }
    });

    inboxChannel.listen('.ConversationAssigned', (event: any) => {
      const isAgentOnly = !hasPermission('conversations.view_all');
      const isAssignedToCurrent = event.assigned_user_id === user?.id;

      if (isAgentOnly && !isAssignedToCurrent) {
        // Conversation was claimed or assigned to another agent: remove from available list
        setConversations((prev) => prev.filter((c) => c.id !== event.id));
        if (selectedConversationRef.current?.id === event.id) {
          showToast('Esta conversación ya fue tomada por otro agente.', 'info');
          setSelectedConversation(null);
        }
        return;
      }

      setConversations((prev) => {
        const existing = prev.find((c) => c.id === event.id);
        const wasAssignedToMe = existing?.assigned_user_id === user?.id;

        if (isAssignedToCurrent && !wasAssignedToMe) {
          triggerNewChatNotification(event.id, event.contact?.display_name || 'Nuevo contacto');
        } else if (
          (event.status === 'waiting_agent' || event.status === 'waiting_human') &&
          existing?.status !== event.status
        ) {
          triggerWaitingQueueNotification(event.id, event.contact?.display_name || 'Nuevo contacto');
        }

        if (existing) {
          return prev.map((c) =>
            c.id === event.id
              ? {
                  ...c,
                  status: event.status || c.status,
                  assigned_user_id: event.assigned_user_id,
                  assigned_user: event.assigned_user,
                }
              : c
          );
        }
        const newConv: Conversation = {
          id: event.id,
          channel_id: event.channel_id || '',
          channel: event.channel,
          contact_id: event.contact_id,
          contact: event.contact,
          status: event.status || 'assigned',
          assigned_user_id: event.assigned_user_id,
          assigned_user: event.assigned_user,
          active_flow_id: null,
          closed_at: null,
          started_at: event.started_at || new Date().toISOString(),
          last_message_at: event.last_message_at || new Date().toISOString(),
          created_at: event.created_at || new Date().toISOString(),
          updated_at: event.updated_at || new Date().toISOString(),
        };
        return [newConv, ...prev];
      });

      if (selectedConversationRef.current?.id === event.id) {
        setSelectedConversation((prev) =>
          prev
            ? {
                ...prev,
                status: event.status || prev.status,
                assigned_user_id: event.assigned_user_id,
                assigned_user: event.assigned_user,
              }
            : null
        );
      }
    });

    inboxChannel.listen('.ConversationClosed', (event: any) => {
      setConversations((prev) =>
        prev.map((c) => (c.id === event.id ? { ...c, status: 'closed' } : c))
      );
      if (selectedConversationRef.current?.id === event.id) {
        setSelectedConversation((prev) => (prev ? { ...prev, status: 'closed' } : null));
      }
    });

    inboxChannel.listen('.MessageCreated', (event: any) => {
      const isSelected = selectedConversationRef.current?.id === event.conversation_id;
      const isContact = event.sender_type === 'contact' || event.direction === 'inbound';
      const targetConv =
        conversationsRef.current.find((c) => c.id === event.conversation_id) ||
        (isSelected ? selectedConversationRef.current : null);

      setConversations((prev) =>
        prev.map((c) => {
          if (c.id !== event.conversation_id) return c;
          const newUnread = isSelected ? 0 : isContact ? (c.unread_count || 0) + 1 : (c.unread_count || 0);
          return {
            ...c,
            last_message_at: event.sent_at || event.created_at,
            unread_count: newUnread,
          };
        })
      );

      if (isContact) {
        const isAssignedToMe = targetConv ? targetConv.assigned_user_id === userRef.current?.id : false;
        const isWaitingHuman = targetConv
          ? targetConv.status === 'waiting_agent' || (targetConv.status as string) === 'waiting_human'
          : false;
        const canNotify = isSupervisorOrAdminRef.current || isAssignedToMe || isWaitingHuman;

        if (canNotify) {
          inboxNotificationsRef.current.playMessageSound();

          const isHidden = typeof document !== 'undefined' && document.hidden;
          if (isHidden || !isSelected) {
            const contactName =
              targetConv?.contact?.display_name || targetConv?.contact?.phone || 'Contacto';
            const bodyText =
              event.content ||
              (event.message_type === 'audio'
                ? '🎵 Nota de voz'
                : event.message_type === 'image'
                ? '📷 Imagen'
                : '📎 Archivo adjunto');

            inboxNotificationsRef.current.notifyDesktop({
              title: `Mensaje de ${contactName}`,
              body: bodyText,
              conversationId: event.conversation_id,
            });
          }
        }
      }

      if (isSelected && isContact) {
        api.markConversationAsRead(event.conversation_id).catch(() => {});
      }
    });

    if (user?.id) {
      const userChannel = echo.private(`user.${user.id}`);

      userChannel.listen('.ConversationAssigned', (event: any) => {
        triggerNewChatNotification(event.id, event.contact?.display_name || 'Nuevo contacto');
        setConversations((prev) => {
          const exists = prev.some((c) => c.id === event.id);
          if (exists) {
            return prev.map((c) =>
              c.id === event.id
                ? {
                    ...c,
                    status: event.status || c.status,
                    assigned_user_id: event.assigned_user_id,
                    assigned_user: event.assigned_user,
                  }
                : c
            );
          }
          const newConv: Conversation = {
            id: event.id,
            channel_id: event.channel_id || '',
            channel: event.channel,
            contact_id: event.contact_id,
            contact: event.contact,
            status: event.status || 'assigned',
            assigned_user_id: event.assigned_user_id,
            assigned_user: event.assigned_user,
            active_flow_id: null,
            closed_at: null,
            started_at: event.started_at || new Date().toISOString(),
            last_message_at: event.last_message_at || new Date().toISOString(),
            created_at: event.created_at || new Date().toISOString(),
            updated_at: event.updated_at || new Date().toISOString(),
          };
          return [newConv, ...prev];
        });
      });

      userChannel.listen('.ConversationUpdated', (event: any) => {
        if (event.assigned_user_id === user?.id) {
          triggerNewChatNotification(event.id, event.contact?.display_name || 'Nuevo contacto');
        }
        setConversations((prev) => {
          const exists = prev.some((c) => c.id === event.id);
          if (exists) {
            return prev.map((c) =>
              c.id === event.id
                ? {
                    ...c,
                    status: event.status || c.status,
                    assigned_user_id: event.assigned_user_id,
                    assigned_user: event.assigned_user ?? c.assigned_user,
                    last_message_at: event.last_message_at || c.last_message_at,
                    channel: event.channel ?? c.channel,
                    contact: event.contact ?? c.contact,
                    closed_at: event.status && event.status !== 'closed' ? null : (event.closed_at ?? c.closed_at),
                    whatsapp_window: event.whatsapp_window ?? event.meta_window ?? c.whatsapp_window,
                    meta_window: event.meta_window ?? event.whatsapp_window ?? c.meta_window,
                    closing_assigned_user_id: event.closing_assigned_user_id ?? (event.status && event.status !== 'closed' ? null : c.closing_assigned_user_id),
                  }
                : c
            );
          }
          const newConv: Conversation = {
            id: event.id,
            channel_id: event.channel_id || '',
            channel: event.channel,
            contact_id: event.contact_id,
            contact: event.contact,
            status: event.status || 'assigned',
            assigned_user_id: event.assigned_user_id,
            assigned_user: event.assigned_user,
            closing_assigned_user_id: event.closing_assigned_user_id,
            active_flow_id: null,
            closed_at: event.status && event.status !== 'closed' ? null : (event.closed_at ?? null),
            started_at: event.started_at || new Date().toISOString(),
            last_message_at: event.last_message_at || new Date().toISOString(),
            created_at: event.created_at || new Date().toISOString(),
            updated_at: event.updated_at || new Date().toISOString(),
            whatsapp_window: event.whatsapp_window ?? event.meta_window,
            meta_window: event.meta_window ?? event.whatsapp_window,
          };
          return [newConv, ...prev];
        });

        if (selectedConversationRef.current?.id === event.id) {
          setSelectedConversation((prev) =>
            prev
              ? {
                  ...prev,
                  status: event.status || prev.status,
                  assigned_user_id: event.assigned_user_id,
                  assigned_user: event.assigned_user ?? prev.assigned_user,
                  closing_assigned_user_id: event.closing_assigned_user_id ?? (event.status && event.status !== 'closed' ? null : prev.closing_assigned_user_id),
                  closed_at: event.status && event.status !== 'closed' ? null : (event.closed_at ?? prev.closed_at),
                  channel: event.channel ?? prev.channel,
                  contact: event.contact ?? prev.contact,
                  whatsapp_window: event.whatsapp_window ?? event.meta_window ?? prev.whatsapp_window,
                  meta_window: event.meta_window ?? event.whatsapp_window ?? prev.meta_window,
                }
              : null
          );
        }
      });
    }

    };

    void setupRealtime().catch((error) => {
      console.error('No se pudo inicializar la conexión en tiempo real.', error);
      setRealtimeStatus('offline');
    });

    return () => {
      cancelled = true;
      if (activeEcho) {
        activeEcho.leave?.('inbox');
        if (user?.id) {
          activeEcho.leave?.(`user.${user.id}`);
        }
        activeEcho.disconnect?.();
      }
      if (echoRef.current === activeEcho) {
        echoRef.current = null;
      }
    };
  }, [user?.id, hasPermission, matchesCurrentFilters, triggerNewChatNotification, triggerWaitingQueueNotification, showToast]);

  // Keep the inbox usable when the WebSocket is unavailable. This also
  // reconciles messages missed during short network interruptions.
  useEffect(() => {
    if (realtimeStatus === 'connected') return;

    let syncing = false;
    const syncInbox = async () => {
      if (syncing || document.visibilityState === 'hidden') return;
      syncing = true;
      const selectedId = selectedConversationRef.current?.id;

      try {
        await fetchConversationsRef.current(true);

        if (selectedId) {
          const fresh = await api.getConversation(selectedId);
          if (selectedConversationRef.current?.id !== selectedId) return;

          const incomingMessages: Message[] = fresh.messages?.data || [];
          const existingIds = new Set((selectedConversationRef.current.messages || []).map((message) => message.id));
          const hasNewInbound = incomingMessages.some(
            (message) => !existingIds.has(message.id) && (message.sender_type === 'contact' || message.direction === 'inbound')
          );

          setSelectedConversation((previous) => {
            if (!previous || previous.id !== selectedId) return previous;
            return {
              ...previous,
              ...fresh.data,
              messages: sortAndDeduplicateInboxMessages([
                ...(previous.messages || []),
                ...incomingMessages,
              ]),
            };
          });
          if (fresh.messages) {
            setHasMoreMessages(Boolean(fresh.messages.has_more));
            setOldestMessageId(fresh.messages.oldest_message_id || null);
          }
          if (hasNewInbound) {
            api.markConversationAsRead(selectedId).catch(() => {});
            setConversations((previous) =>
              previous.map((conversation) =>
                conversation.id === selectedId ? { ...conversation, unread_count: 0 } : conversation
              )
            );
          }
        }
      } catch {
        // A later interval or focus event will retry automatically.
      } finally {
        syncing = false;
      }
    };

    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') void syncInbox();
    };
    const interval = window.setInterval(() => void syncInbox(), 8000);
    window.addEventListener('focus', syncInbox);
    document.addEventListener('visibilitychange', onVisibilityChange);

    return () => {
      window.clearInterval(interval);
      window.removeEventListener('focus', syncInbox);
      document.removeEventListener('visibilitychange', onVisibilityChange);
    };
  }, [realtimeStatus]);

  // Setup Realtime listener for active selected conversation thread
  useEffect(() => {
    if (!selectedConversation?.id || !echoRef.current) return;

    const channelName = `conversation.${selectedConversation.id}`;
    const convChannel = echoRef.current.private(channelName);

    convChannel.listen('.MessageCreated', (event: any) => {
      const newMsg: Message = {
        id: event.id,
        conversation_id: event.conversation_id,
        sender_type: event.sender_type,
        sender_id: event.sender_id,
        direction: event.direction,
        message_type: event.message_type,
        content: event.content,
        metadata: event.metadata || {},
        sent_at: event.sent_at || event.created_at,
        created_at: event.created_at,
      };

      setSelectedConversation((prev) => {
        if (!prev || prev.id !== event.conversation_id) return prev;
        if (prev.messages?.some((m) => m.id === newMsg.id)) return prev;
        return {
          ...prev,
          last_message_at: newMsg.sent_at,
          messages: [...(prev.messages || []), newMsg],
        };
      });

      if (event.sender_type === 'contact' || event.direction === 'inbound') {
        api.markConversationAsRead(event.conversation_id).catch(() => {});
      }

      if (event.sender_type === 'bot') {
        api.getConversationExecution(event.conversation_id).then((res) => setExecution(res.data)).catch(() => {});
      }
    });

    const handleStatusUpdated = (event: any) => {
      setSelectedConversation((prev) => {
        if (!prev || prev.id !== event.conversation_id) return prev;
        const updatedMessages = (prev.messages || []).map((m) => {
          if (m.id === event.id || (event.external_message_id && m.external_message_id === event.external_message_id)) {
            const nextMeta = {
              ...(m.metadata || {}),
              ...(event.metadata || {}),
            };
            if (event.status && !nextMeta.delivery_status) {
              nextMeta.delivery_status = event.status;
            }
            return {
              ...m,
              status: event.status || m.status,
              external_message_id: event.external_message_id || m.external_message_id,
              metadata: nextMeta,
            };
          }
          return m;
        });

        return {
          ...prev,
          messages: updatedMessages,
        };
      });
    };

    convChannel.listen('.MessageStatusUpdated', handleStatusUpdated);
    convChannel.listen('MessageStatusUpdated', handleStatusUpdated);
    convChannel.listen('.MessageUpdated', handleStatusUpdated);

    convChannel.listen('.MessageReactionUpdated', (event: any) => {
      setSelectedConversation((prev) => {
        if (!prev || prev.id !== event.conversation_id) return prev;

        return {
          ...prev,
          messages: (prev.messages || []).map((message) =>
            message.id === event.id
              ? {
                  ...message,
                  metadata: {
                    ...(message.metadata || {}),
                    reactions: event.reactions || {},
                  },
                }
              : message
          ),
        };
      });
    });

    convChannel.listen('.ConversationWaitingAgent', () => {
      setSelectedConversation((prev) => (prev ? { ...prev, status: 'waiting_agent' } : null));
      api.getConversationExecution(selectedConversation.id).then((res) => setExecution(res.data)).catch(() => {});
    });

    convChannel.listen('.ConversationStatusChanged', (event: any) => {
      setSelectedConversation((prev) => (prev ? { ...prev, status: event.status } : null));
    });

    convChannel.listen('.ConversationAssigned', (event: any) => {
      setSelectedConversation((prev) =>
        prev
          ? {
              ...prev,
              status: event.status || prev.status,
              assigned_user_id: event.assigned_user_id,
              assigned_user: event.assigned_user,
            }
          : null
      );
    });

    convChannel.listen('.ConversationClosed', () => {
      setSelectedConversation((prev) => (prev ? { ...prev, status: 'closed' } : null));
    });

    convChannel.listen('.ContactUpdated', (event: any) => {
      const updatedContact = event.contact;
      setSelectedConversation((prev) => {
        if (!prev) return null;
        if (event.conversation_id && prev.id !== event.conversation_id) return prev;
        return {
          ...prev,
          contact: {
            ...prev.contact,
            ...updatedContact,
          },
        };
      });
    });

    return () => {
      echoRef.current?.leave(channelName);
    };
  }, [selectedConversation?.id, echoReadyVersion]);

  const formatFileSize = (bytes: number): string => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  };

  const handleClearFile = () => {
    setSelectedFile(null);
    if (filePreviewUrl) {
      URL.revokeObjectURL(filePreviewUrl);
      setFilePreviewUrl(null);
    }
    setFileError(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    setFileError(null);
    const file = e.target.files?.[0];
    if (!file) return;

    const lowerName = file.name.toLowerCase();
    const isImage = file.type.startsWith('image/');
    const isAudio = file.type.startsWith('audio/') || file.type === 'application/ogg' || file.type === 'video/webm' || lowerName.endsWith('.ogg') || lowerName.endsWith('.opus') || lowerName.endsWith('.mp3') || lowerName.endsWith('.m4a') || lowerName.endsWith('.aac') || lowerName.endsWith('.wav') || lowerName.endsWith('.webm') || lowerName.endsWith('.amr');
    const maxBytes = isAudio ? 16 * 1024 * 1024 : (isImage ? 5 * 1024 * 1024 : 10 * 1024 * 1024);

    // Reject SVG or HTML
    if (file.type.includes('svg') || file.type.includes('html') || lowerName.endsWith('.svg') || lowerName.endsWith('.html') || lowerName.endsWith('.php') || lowerName.endsWith('.exe')) {
      setFileError('Formato de archivo no permitido por razones de seguridad.');
      if (fileInputRef.current) fileInputRef.current.value = '';
      return;
    }

    const allowedMimes = [
      'image/jpeg', 'image/png', 'image/webp', 'image/gif',
      'audio/ogg', 'application/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/wav', 'audio/x-wav', 'audio/webm', 'video/webm', 'audio/amr',
      'application/pdf', 'text/plain', 'text/csv',
      'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];

    if (!allowedMimes.includes(file.type)) {
      setFileError('Tipo no soportado. Formatos válidos: JPG, PNG, WEBP, GIF (máx 5MB), MP3, OGG, WAV, M4A, AAC, WEBM (máx 16MB), PDF, TXT, CSV, DOC, DOCX, XLS, XLSX (máx 10MB).');
      if (fileInputRef.current) fileInputRef.current.value = '';
      return;
    }

    if (file.size > maxBytes) {
      setFileError(`El archivo supera el tamaño máximo permitido (${isAudio ? '16 MB' : isImage ? '5 MB' : '10 MB'}).`);
      if (fileInputRef.current) fileInputRef.current.value = '';
      return;
    }

    setSelectedFile(file);
    if (isImage) {
      setFilePreviewUrl(URL.createObjectURL(file));
    } else {
      setFilePreviewUrl(null);
    }
  };

  const handleSendMessage = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedConversation || (!replyText.trim() && !selectedFile)) return;

    setSending(true);
    const content = replyText.trim();
    const fileToSend = selectedFile;
    try {
      await api.sendMessage(selectedConversation.id, content, {
        attachment: fileToSend,
      });
      setReplyText('');
      handleClearFile();
      userScrolledUpRef.current = false;
      await loadConversationDetails(selectedConversation.id);
      scrollToBottom('smooth', true);
    } catch (err: any) {
      showToast(err.message || 'Error al enviar mensaje.', 'error');
    } finally {
      setSending(false);
    }
  };

  const voiceRecorder = useVoiceRecorder();

  const handleSendVoiceNote = async () => {
    if (!selectedConversation) return;
    const file = await voiceRecorder.stopRecording();
    if (!file) return;

    setSending(true);
    try {
      await api.sendMessage(selectedConversation.id, replyText.trim(), {
        attachment: file,
      });
      setReplyText('');
      handleClearFile();
      userScrolledUpRef.current = false;
      await loadConversationDetails(selectedConversation.id);
      scrollToBottom('smooth', true);
    } catch (err: any) {
      showToast(err.message || 'Error al enviar nota de voz.', 'error');
    } finally {
      setSending(false);
    }
  };

  const handleAssign = async (agentId: number | null) => {
    if (!selectedConversation) return;

    try {
      await api.assignConversation(selectedConversation.id, {
        assigned_user_id: agentId,
        reason: 'Asignación manual desde panel de operador',
      });
      loadConversationDetails(selectedConversation.id);
      fetchConversations(true);
    } catch (err: any) {
      showToast(err.message || 'Error al asignar conversación.', 'error');
    }
  };

  const handleRemoveTagFromHeader = async (tagIdentifier: string) => {
    if (!selectedConversation) return;
    try {
      await api.removeConversationTag(selectedConversation.id, tagIdentifier);
      const updatedTags = (selectedConversation.tags || []).filter((t: any) =>
        (typeof t === 'string' ? t : (t.id || t.name)) !== tagIdentifier
      );
      setSelectedConversation({
        ...selectedConversation,
        tags: updatedTags,
      });
      setConversations((prev) =>
        prev.map((c) => (c.id === selectedConversation.id ? { ...c, tags: updatedTags } : c))
      );
    } catch (err: any) {
      console.error('Error al quitar etiqueta:', err);
    }
  };

  const handleToggleTag = async (tag: Tag) => {
    if (!selectedConversation) return;
    try {
      const res = await api.syncConversationTags(selectedConversation.id, { tag_id: tag.id });
      const newTags = res.tags || res.data?.tags || [];
      setSelectedConversation({
        ...selectedConversation,
        tags: newTags,
      });
      setConversations((prev) =>
        prev.map((c) => (c.id === selectedConversation.id ? { ...c, tags: newTags } : c))
      );
    } catch (err: any) {
      console.error('Error al alternar etiqueta:', err);
    }
  };

  const handleCreateTagInline = async () => {
    if (!isSupervisorOrAdmin) return;
    if (!newTagName.trim()) return;
    setCreatingTag(true);
    try {
      const res = await api.createTag({ name: newTagName.trim(), color: newTagColor });
      const created = res.data;
      setAllTags((prev) => [...prev, created]);
      setNewTagName('');
      if (selectedConversation) {
        await handleToggleTag(created);
      }
    } catch (err: any) {
      showToast(err.message || 'Error al crear etiqueta.', 'error');
    } finally {
      setCreatingTag(false);
    }
  };

  const handleConfirmTransfer = async () => {
    if (!selectedConversation || !transferTargetUserId) return;
    setTransferring(true);
    try {
      const res = await api.transferConversation(selectedConversation.id, {
        target_user_id: transferTargetUserId,
        note: transferNote.trim() || undefined,
      });

      setShowTransferModal(false);
      setTransferNote('');
      setTransferTargetUserId(null);

      await loadConversationDetails(selectedConversation.id);
      fetchConversations(true);

      showToast(res.message || 'Conversación transferida con éxito.', 'success');
    } catch (err: any) {
      showToast(err.message || 'Error al transferir la conversación.', 'error');
    } finally {
      setTransferring(false);
    }
  };

  const sortedMessages = useMemo(
    () => sortAndDeduplicateInboxMessages(selectedConversation?.messages || []),
    [selectedConversation?.messages]
  );

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'bot':
        return <span className="badge badge-info"><Bot size={12} style={{ marginRight: '3px' }} /> En Bot</span>;
      case 'waiting_agent':
        return <span className="badge badge-warning" style={{ background: '#fef3c7', color: '#92400e', borderColor: '#fde68a' }}><Clock size={12} style={{ marginRight: '3px' }} /> Esperando Agente</span>;
      case 'assigned':
        return <span className="badge badge-success"><UserCheck size={12} style={{ marginRight: '3px' }} /> Asignada</span>;
      case 'closed':
        return <span className="badge badge-secondary"><CheckCircle2 size={12} style={{ marginRight: '3px' }} /> Cerrada</span>;
      default:
        return <span className="badge badge-secondary">{status}</span>;
    }
  };

  const getExecutionStatusBadge = (status: string) => {
    switch (status) {
      case 'running':
        return <span className="badge badge-info"><Zap size={11} style={{ marginRight: '3px' }} /> Ejecutando</span>;
      case 'waiting_input':
        return <span className="badge badge-warning"><Clock size={11} style={{ marginRight: '3px' }} /> Esperando Respuesta</span>;
      case 'waiting_agent':
        return <span className="badge badge-warning"><UserCheck size={11} style={{ marginRight: '3px' }} /> Transferido a Agente</span>;
      case 'completed':
        return <span className="badge badge-success"><CheckCircle2 size={11} style={{ marginRight: '3px' }} /> Completado</span>;
      case 'failed':
        return <span className="badge badge-danger"><AlertCircle size={11} style={{ marginRight: '3px' }} /> Falló</span>;
      default:
        return <span className="badge badge-secondary">{status}</span>;
    }
  };

  const canCloseSelected =
    selectedConversation &&
    selectedConversation.status !== 'closed' &&
    (isSupervisorOrAdmin
      ? ['waiting_agent', 'assigned'].includes(selectedConversation.status)
      : selectedConversation.assigned_user_id === user?.id && selectedConversation.status === 'assigned');

  const selectedChannelType = selectedConversation?.channel?.type;
  const isMetaChannel = ['whatsapp', 'facebook_messenger', 'instagram'].includes(selectedChannelType || '');

  const isSelectedWhatsApp = Boolean(selectedConversation && selectedChannelType === 'whatsapp');
  const isSelectedMessenger = Boolean(selectedConversation && selectedChannelType === 'facebook_messenger');
  const isSelectedInstagram = Boolean(selectedConversation && selectedChannelType === 'instagram');

  const currentMetaWindow = selectedConversation?.meta_window ?? selectedConversation?.whatsapp_window;

  const isSelectedMeta = Boolean(
    selectedConversation &&
      (isMetaChannel || currentMetaWindow?.is_applicable)
  );

  const isSelectedMetaExpired = Boolean(
    isSelectedMeta &&
      currentMetaWindow &&
      !currentMetaWindow.is_open
  );

  const isSelectedMetaOpen = Boolean(
    isSelectedMeta && currentMetaWindow?.is_open
  );

  // Backward-compatible alias used by composer and actions
  const isSelectedWAExpired = isSelectedMetaExpired;

  const isAuthorizedToReplySelected = Boolean(
    hasPermission('conversations.reply') &&
      (isSupervisorOrAdmin ||
        (selectedConversation?.status !== 'closed' && selectedConversation?.assigned_user_id === user?.id) ||
        (selectedConversation?.status === 'closed' && selectedConversation?.closing_assigned_user_id === user?.id))
  );

  const canReplyToClosedMeta = Boolean(
    selectedConversation?.status === 'closed' &&
      isSelectedMetaOpen &&
      isAuthorizedToReplySelected
  );

  const availableTabs = isSupervisorOrAdmin
    ? [
        { id: 'all', label: 'Todas' },
        { id: 'bot', label: 'En Bot' },
        { id: 'waiting_agent', label: 'Esperando Agente' },
        { id: 'assigned', label: 'Asignadas' },
        { id: 'closed', label: 'Archivadas' },
      ]
    : [
        { id: 'all', label: 'Todas' },
        { id: 'assigned', label: 'Asignadas' },
        { id: 'closed', label: 'Archivadas' },
      ];

  const availableChannels = useMemo(() => {
    if (channels.length > 0) return channels;
    const map = new Map<string, string>();
    conversations.forEach((c) => {
      if (c.channel?.id && c.channel?.name) {
        map.set(c.channel.id, c.channel.name);
      }
    });
    return Array.from(map.entries()).map(([id, name]) => ({ id, name }));
  }, [channels, conversations]);

  const filteredConversations = useMemo(() => {
    const list = conversations.filter((c) => {
      if (activeTab === 'all' && c.status === 'closed') return false;
      if (!isSupervisorOrAdmin) {
        if (c.status === 'bot' || c.status === 'waiting_agent') return false;
        if (c.status === 'closed') {
          if (c.closing_assigned_user_id !== user?.id) return false;
        } else {
          if (c.assigned_user_id !== user?.id) return false;
        }
      }
      if (activeTab !== 'all' && c.status !== activeTab) {
        return false;
      }
      if (selectedChannelFilter !== 'all' && c.channel_id !== selectedChannelFilter) {
        return false;
      }
      if (selectedAgentFilter === 'unassigned' && c.assigned_user_id) {
        return false;
      }
      if (
        selectedAgentFilter !== 'all' &&
        selectedAgentFilter !== 'unassigned' &&
        String(c.assigned_user_id) !== selectedAgentFilter
      ) {
        return false;
      }
      if (selectedTagFilter !== 'all') {
        const hasTag = c.tags?.some((t: any) =>
          typeof t === 'string' ? t === selectedTagFilter : t.id === selectedTagFilter || t.name === selectedTagFilter
        );
        if (!hasTag) return false;
      }
      if (debouncedSearch.trim()) {
        const query = debouncedSearch.trim().toLowerCase();
        const name = (c.contact?.display_name || '').toLowerCase();
        const email = (c.contact?.email || '').toLowerCase();
        const phone = (c.contact?.phone || '').toLowerCase();
        const ext = (c.contact?.external_identifier || '').toLowerCase();
        const id = (c.id || '').toLowerCase();
        if (
          !name.includes(query) &&
          !email.includes(query) &&
          !phone.includes(query) &&
          !ext.includes(query) &&
          !id.includes(query)
        ) {
          return false;
        }
      }
      return true;
    });

    // Persistent threading: Show only one single row per contact in the inbox list
    const seenContacts = new Set<string>();
    return list.filter((conv) => {
      const contactKey = conv.contact_id || conv.contact?.id || (conv.contact?.external_identifier ? `ext_${conv.contact.external_identifier}` : conv.id);
      if (seenContacts.has(contactKey)) {
        return false;
      }
      seenContacts.add(contactKey);
      return true;
    });
  }, [
    conversations,
    activeTab,
    isSupervisorOrAdmin,
    user?.id,
    selectedChannelFilter,
    selectedAgentFilter,
    selectedTagFilter,
    debouncedSearch,
  ]);

  useEffect(() => {
    const handleInboxShortcut = (event: KeyboardEvent) => {
      if (event.metaKey || event.ctrlKey || event.altKey) return;
      const target = event.target as HTMLElement | null;
      if (target && (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.isContentEditable)) return;

      const key = event.key.toLowerCase();
      if (key === '/') {
        event.preventDefault();
        document.querySelector<HTMLInputElement>('[data-testid="inbox-search-input"]')?.focus();
        return;
      }

      if (key === 'a') {
        if (selectedConversation && (hasPermission('conversations.assign') || isSupervisorOrAdmin)) {
          const assignment = document.querySelector<HTMLSelectElement>('[aria-label="Asignar agente a la conversación"]');
          if (assignment) {
            event.preventDefault();
            assignment.focus();
          }
        }
        return;
      }

      if (key === 'r') {
        const canReply = Boolean(
          selectedConversation &&
          hasPermission('conversations.reply') &&
          (isSupervisorOrAdmin ||
            (selectedConversation.status !== 'closed' && selectedConversation.assigned_user_id === user?.id) ||
            (selectedConversation.status === 'closed' && selectedConversation.closing_assigned_user_id === user?.id))
        );
        if (canReply) {
          event.preventDefault();
          replyInputRef.current?.focus();
        }
        return;
      }

      if (key === 'j' || key === 'k') {
        if (filteredConversations.length === 0) return;
        event.preventDefault();
        const selectedIndex = filteredConversations.findIndex((conversation) => conversation.id === selectedConversation?.id);
        const direction = key === 'j' ? 1 : -1;
        const nextIndex = selectedIndex < 0
          ? (direction > 0 ? 0 : filteredConversations.length - 1)
          : (selectedIndex + direction + filteredConversations.length) % filteredConversations.length;
        void loadConversationDetails(filteredConversations[nextIndex].id);
      }
    };

    window.addEventListener('keydown', handleInboxShortcut);
    return () => window.removeEventListener('keydown', handleInboxShortcut);
  }, [filteredConversations, selectedConversation, hasPermission, isSupervisorOrAdmin, user?.id]);

  return (
    <div style={{ height: isMobile ? 'calc(100dvh - 72px)' : 'calc(100vh - 110px)', display: 'flex', flexDirection: 'column', minHeight: 0 }}>
      {/* Top Bar with Filter Tabs and Realtime Status (Hidden on mobile when conversation is active to give 100% space to chat) */}
      {(!isMobile || !selectedConversation) && (
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: isMobile ? '0.65rem' : '1rem', gap: '0.5rem', minWidth: 0 }}>
          <div
            className="horizontal-scroll"
            style={{
              display: 'flex',
              gap: '0.4rem',
              overflowX: 'auto',
              whiteSpace: 'nowrap',
              WebkitOverflowScrolling: 'touch',
              paddingBottom: '2px',
              flex: 1,
              minWidth: 0,
            }}
          >
            {availableTabs.map((tab) => {
              const count = tabCounts[tab.id];
              return (
                <button
                  key={tab.id}
                  className={`btn btn-sm ${activeTab === tab.id ? 'btn-primary' : 'btn-secondary'}`}
                  onClick={() => {
                    setActiveTab(tab.id);
                    setSelectedConversation(null);
                  }}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', flexShrink: 0 }}
                >
                  <span>{tab.label}</span>
                  {count !== undefined && (
                    <span
                      style={{
                        fontSize: '0.7rem',
                        padding: '1px 6px',
                        borderRadius: '9999px',
                        background: activeTab === tab.id ? 'rgba(255,255,255,0.25)' : '#d9e3de',
                        color: activeTab === tab.id ? '#ffffff' : '#61726d',
                        fontWeight: 600,
                      }}
                    >
                      {count}
                    </span>
                  )}
                </button>
              );
            })}
          </div>

          {/* Top Bar Action Controls: Sound Alerts Toggle & Realtime Live Indicator */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', flexShrink: 0 }}>
            <button
              type="button"
              data-testid="inbox-sound-toggle-btn"
              onClick={inboxNotifications.toggleSound}
              title={
                inboxNotifications.soundEnabled
                  ? 'Alertas sonoras activadas (clic para silenciar)'
                  : 'Alertas silenciadas (clic para activar)'
              }
              aria-label={
                inboxNotifications.soundEnabled
                  ? 'Alertas sonoras activadas (clic para silenciar)'
                  : 'Alertas silenciadas (clic para activar)'
              }
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '0.35rem',
                fontSize: '0.75rem',
                padding: '4px 10px',
                borderRadius: '9999px',
                background: inboxNotifications.soundEnabled ? '#eaf1ee' : '#f4f7f5',
                color: inboxNotifications.soundEnabled ? '#263c38' : '#61726d',
                border: `1px solid ${inboxNotifications.soundEnabled ? '#a9c8bb' : '#d9e3de'}`,
                fontWeight: 500,
                cursor: 'pointer',
                transition: 'all 0.15s ease',
              }}
            >
              {inboxNotifications.soundEnabled ? (
                <Bell size={12} style={{ color: '#5d8f82' }} />
              ) : (
                <BellOff size={12} style={{ color: '#80918c' }} />
              )}
              <span className="topbar-desktop-only">
                {inboxNotifications.soundEnabled ? 'Sonido' : 'Silencio'}
              </span>
            </button>

            {/* Realtime Live Indicator */}
            <div
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '0.4rem',
                fontSize: '0.75rem',
                padding: '4px 10px',
                borderRadius: '9999px',
                background: realtimeStatus === 'connected' ? '#eaf1ee' : '#f4f7f5',
                color: realtimeStatus === 'connected' ? '#263c38' : '#61726d',
                border: `1px solid ${realtimeStatus === 'connected' ? '#a9c8bb' : '#d9e3de'}`,
                fontWeight: 500,
              }}
              title={realtimeStatus === 'connected'
                ? 'Conexión en tiempo real activa'
                : realtimeStatus === 'offline'
                  ? 'Sin conexión en tiempo real; la bandeja se actualiza automáticamente'
                  : 'Conectando en tiempo real'}
            >
              <span
                style={{
                  display: 'inline-block',
                  width: '8px',
                  height: '8px',
                  borderRadius: '50%',
                  background: realtimeStatus === 'connected' ? '#5d8f82' : '#80918c',
                  boxShadow: realtimeStatus === 'connected' ? '0 0 6px #5d8f82' : 'none',
                }}
              />
              <Wifi size={12} />
              <span className="topbar-desktop-only">
                {realtimeStatus === 'connected'
                  ? 'Realtime Activo'
                  : realtimeStatus === 'offline'
                    ? 'Actualizando'
                    : 'Conectando'}
              </span>
            </div>
          </div>
        </div>
      )}

      {/* Two-Column Chat Panel (Adaptive for mobile: 1 column at a time) */}
      <div style={{ flex: 1, display: 'flex', gap: isMobile ? 0 : '1.25rem', overflow: 'hidden', minHeight: 0 }}>
        {/* Left: Conversation List */}
        {(!isMobile || !selectedConversation) && (
          <div
            data-testid="inbox-conversation-list-panel"
            style={{
              width: isMobile ? '100%' : '360px',
              flex: isMobile ? '1 1 100%' : undefined,
              background: 'white',
              borderRadius: 'var(--radius)',
              border: '1px solid var(--border)',
              display: 'flex',
              flexDirection: 'column',
              overflow: 'hidden',
              minHeight: 0,
            }}
          >
            <div style={{ padding: '0.75rem 1rem', borderBottom: '1px solid var(--border)', fontWeight: 600, fontSize: '0.9rem', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span title="Atajos: / buscar · J/K cambiar conversación · A asignar agente · R responder">
                Bandeja de Entrada ({totalConversations > 0 ? totalConversations : filteredConversations.length}) · ⌨ J/K · A · R
              </span>
            </div>

            {newChatAlert && (
              <div
                data-testid="new-chat-assigned-alert"
                style={{
                  margin: '0.4rem 0.6rem 0.2rem',
                  padding: '0.5rem 0.75rem',
                  backgroundColor: '#eaf1ee',
                  border: '1px solid #6ee7b7',
                  borderRadius: '6px',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  gap: '0.5rem',
                }}
              >
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.8rem', color: '#263c38', minWidth: 0, overflow: 'hidden' }}>
                  <Bell size={14} style={{ color: '#5d8f82', flexShrink: 0 }} />
                  <span style={{ textOverflow: 'ellipsis', overflow: 'hidden', whiteSpace: 'nowrap' }}>
                    <strong>¡Nuevo chat asignado!</strong> {newChatAlert.name}
                  </span>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', flexShrink: 0 }}>
                  <button
                    type="button"
                    className="btn btn-xs btn-primary"
                    onClick={() => {
                      loadConversationDetails(newChatAlert.id);
                      setNewChatAlert(null);
                    }}
                    style={{ fontSize: '0.7rem', padding: '1px 6px', height: '22px' }}
                  >
                    Abrir
                  </button>
                  <button
                    type="button"
                    onClick={() => setNewChatAlert(null)}
                    style={{ background: 'none', border: 'none', cursor: 'pointer', padding: '2px', color: '#263c38' }}
                    title="Cerrar alerta"
                  >
                    <X size={12} />
                  </button>
                </div>
              </div>
            )}

            {/* Search & Filters */}
            <div style={{ padding: '0.6rem 0.8rem', borderBottom: '1px solid var(--border)', background: '#f4f7f5', display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
              <div style={{ position: 'relative', display: 'flex', alignItems: 'center' }}>
                <Search size={14} style={{ position: 'absolute', left: '10px', color: '#80918c' }} />
                <input
                  type="text"
                  data-testid="inbox-search-input"
                  className="form-input"
                  style={{ paddingLeft: '2rem', paddingRight: '1.8rem', fontSize: '0.8rem', height: '32px' }}
                  placeholder="Buscar contacto, email, tel..."
                  title="Atajo: / para buscar"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                />
                {searchQuery && (
                  <button
                    type="button"
                    onClick={() => setSearchQuery('')}
                    style={{ position: 'absolute', right: '8px', background: 'none', border: 'none', color: '#80918c', cursor: 'pointer', fontSize: '0.75rem', padding: 0 }}
                    title="Limpiar búsqueda"
                  >
                    ✕
                  </button>
                )}
              </div>

              <div style={{ display: 'flex', gap: '0.35rem', alignItems: 'center', flexWrap: 'wrap' }}>
                <Filter size={13} style={{ color: '#80918c', flexShrink: 0 }} />
                <select
                  data-testid="channel-filter-select"
                  className="form-select"
                  style={{ fontSize: '0.75rem', height: '28px', padding: '1px 6px', flex: 1, minWidth: '100px' }}
                  value={selectedChannelFilter}
                  onChange={(e) => setSelectedChannelFilter(e.target.value)}
                >
                  <option value="all">Todos los canales</option>
                  {availableChannels.map((ch) => (
                    <option key={ch.id} value={ch.id}>{ch.name}</option>
                  ))}
                </select>

                {isSupervisorOrAdmin && (
                  <select
                    data-testid="agent-filter-select"
                    className="form-select"
                    style={{ fontSize: '0.75rem', height: '28px', padding: '1px 6px', flex: 1, minWidth: '100px' }}
                    value={selectedAgentFilter}
                    onChange={(e) => setSelectedAgentFilter(e.target.value)}
                  >
                    <option value="all">Todos los agentes</option>
                    <option value="unassigned">Sin asignar</option>
                    {agents.map((ag) => (
                      <option key={ag.id} value={ag.id}>{ag.name}</option>
                    ))}
                  </select>
                )}

                <select
                  data-testid="inbox-tag-filter"
                  className="form-select"
                  style={{ fontSize: '0.75rem', height: '28px', padding: '1px 6px', flex: 1, minWidth: '110px' }}
                  value={selectedTagFilter}
                  onChange={(e) => setSelectedTagFilter(e.target.value)}
                >
                  <option value="all">Todas las etiquetas</option>
                  {allTags.map((tag) => (
                    <option key={tag.id} value={tag.id}>{tag.name}</option>
                  ))}
                </select>
              </div>
            </div>

            <div style={{ flex: 1, overflowY: 'auto' }}>
              {loading ? (
                <p style={{ padding: '1rem', color: 'var(--text-muted)' }}>Cargando...</p>
              ) : filteredConversations.length === 0 ? (
                <div style={{ padding: '2.5rem 1.25rem', textAlign: 'center', color: 'var(--text-muted)' }}>
                  <MessageSquare size={36} style={{ opacity: 0.25, marginBottom: '0.75rem', color: '#61726d' }} />
                  <p style={{ fontSize: '0.875rem', fontWeight: 600, color: '#35564f', marginBottom: '0.25rem' }}>
                    No hay conversaciones
                  </p>
                  <p style={{ fontSize: '0.775rem', margin: '0 0 1rem 0' }}>
                    No se encontraron conversaciones con los filtros seleccionados.
                  </p>
                  {(activeTab !== 'all' || selectedChannelFilter !== 'all' || selectedAgentFilter !== 'all' || selectedTagFilter !== 'all' || debouncedSearch) && (
                    <button
                      className="btn btn-secondary btn-sm"
                      style={{ fontSize: '0.75rem', padding: '2px 8px' }}
                      onClick={() => {
                        setActiveTab('all');
                        setSelectedChannelFilter('all');
                        setSelectedAgentFilter('all');
                        setSelectedTagFilter('all');
                        setSearchQuery('');
                      }}
                    >
                      Restablecer filtros
                    </button>
                  )}
                </div>
              ) : (
                filteredConversations.map((conv) => {
                  const isSelected = selectedConversation?.id === conv.id;
                  const isWaiting = conv.status === 'waiting_agent';

                  return (
                    <div
                      key={conv.id}
                      data-testid="conversation-item"
                      onClick={() => loadConversationDetails(conv.id)}
                      style={{
                        padding: '0.85rem 1rem',
                        borderBottom: '1px solid var(--border)',
                        cursor: 'pointer',
                        background: isSelected ? '#eaf1ee' : isWaiting ? '#fffbeb' : 'white',
                        borderLeft: isWaiting ? '3px solid #d97706' : isSelected ? '3px solid #5d8f82' : '3px solid transparent',
                        transition: 'background 0.15s ease',
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.35rem' }}>
                        <span style={{ fontWeight: 600, fontSize: '0.9rem', color: '#35564f', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                          {conv.contact?.display_name || conv.contact?.external_identifier || 'Contacto Anónimo'}
                          {Boolean(conv.unread_count && conv.unread_count > 0) && (
                            <span
                              data-testid="unread-badge"
                              style={{
                                background: '#ef4444',
                                color: '#ffffff',
                                fontSize: '0.7rem',
                                fontWeight: 700,
                                borderRadius: '9999px',
                                padding: '1px 6px',
                                lineHeight: '1.2',
                              }}
                            >
                              {conv.unread_count}
                            </span>
                          )}
                        </span>
                        {getStatusBadge(conv.status)}
                      </div>
                      {(conv.contact?.email || conv.contact?.phone) && (
                        <div style={{ display: 'flex', gap: '0.6rem', fontSize: '0.72rem', color: '#61726d', marginBottom: '0.35rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                          {conv.contact.email && (
                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }} title={`Email: ${conv.contact.email}`}>
                              <Mail size={11} /> {conv.contact.email}
                            </span>
                          )}
                          {conv.contact.phone && (
                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }} title={`Tel: ${conv.contact.phone}`}>
                              <Phone size={11} /> {conv.contact.phone}
                            </span>
                          )}
                        </div>
                      )}
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.3rem' }}>
                          {conv.channel?.type === 'whatsapp' ? (
                            <span
                              style={{
                                background: '#eaf1ee',
                                color: '#263c38',
                                fontWeight: 600,
                                padding: '1px 5px',
                                borderRadius: '3px',
                                fontSize: '0.68rem',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '0.2rem',
                              }}
                            >
                              <MessageSquare size={10} /> WA
                            </span>
                          ) : conv.channel?.type === 'facebook_messenger' ? (
                            <span
                              style={{
                                background: '#ddeae4',
                                color: '#456c61',
                                fontWeight: 600,
                                padding: '1px 5px',
                                borderRadius: '3px',
                                fontSize: '0.68rem',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '0.2rem',
                              }}
                            >
                              <MessageSquare size={10} /> Messenger
                            </span>
                          ) : conv.channel?.type === 'instagram' ? (
                            <span
                              style={{
                                background: '#fce7f3',
                                color: '#be185d',
                                fontWeight: 600,
                                padding: '1px 5px',
                                borderRadius: '3px',
                                fontSize: '0.68rem',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '0.2rem',
                              }}
                            >
                              <MessageSquare size={10} /> Instagram
                            </span>
                          ) : conv.channel?.type === 'telegram' ? (
                            <span
                              style={{
                                background: '#e0f2fe',
                                color: '#0369a1',
                                fontWeight: 600,
                                padding: '1px 5px',
                                borderRadius: '3px',
                                fontSize: '0.68rem',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '0.2rem',
                              }}
                            >
                              <Send size={10} /> Telegram
                            </span>
                          ) : (
                            <span
                              style={{
                                background: '#eaf1ee',
                                color: '#456c61',
                                fontWeight: 500,
                                padding: '1px 5px',
                                borderRadius: '3px',
                                fontSize: '0.68rem',
                              }}
                            >
                              Webchat
                            </span>
                          )}
                          <span>{conv.channel?.name || 'Canal'}</span>
                        </span>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                          {!isSupervisorOrAdmin && conv.status === 'waiting_agent' && !conv.assigned_user_id && (
                            <button
                              data-testid="claim-conversation-btn"
                              className="btn btn-sm btn-success"
                              style={{
                                fontSize: '0.7rem',
                                padding: '2px 8px',
                                background: '#5d8f82',
                                borderColor: '#5d8f82',
                                color: 'white',
                                borderRadius: '4px',
                              }}
                              disabled={claiming}
                              onClick={(e) => {
                                e.stopPropagation();
                                handleClaim(conv.id);
                              }}
                            >
                              Tomar conversación
                            </button>
                          )}
                          <span>{conv.last_message_at ? new Date(conv.last_message_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}</span>
                        </div>
                      </div>

                      {Boolean(conv.tags && conv.tags.length > 0) && (
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.25rem', marginTop: '0.35rem' }}>
                          {(conv.tags as any[]).map((tag) => {
                            const tagName = typeof tag === 'string' ? tag : tag.name;
                            const tagColor = typeof tag === 'string' ? '#5d8f82' : (tag.color || '#5d8f82');
                            const tagId = typeof tag === 'string' ? tag : (tag.id || tag.name);

                            return (
                              <span
                                key={tagId}
                                data-testid={`conv-tag-chip-${tagId}`}
                                style={{
                                  fontSize: '0.68rem',
                                  padding: '1px 6px',
                                  borderRadius: '9999px',
                                  backgroundColor: `${tagColor}18`,
                                  color: tagColor,
                                  border: `1px solid ${tagColor}40`,
                                  fontWeight: 600,
                                  maxWidth: '120px',
                                  overflow: 'hidden',
                                  textOverflow: 'ellipsis',
                                  whiteSpace: 'nowrap',
                                }}
                              >
                                {tagName}
                              </span>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  );
                })
              )}
            </div>

            {/* Pagination Controls */}
            {lastPage > 1 && (
              <div
                data-testid="inbox-pagination"
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  padding: '0.4rem 0.75rem',
                  borderTop: '1px solid var(--border)',
                  background: '#f4f7f5',
                  fontSize: '0.75rem',
                  flexShrink: 0,
                }}
              >
                <button
                  type="button"
                  data-testid="inbox-prev-page-btn"
                  className="btn btn-secondary btn-sm"
                  style={{ padding: '2px 8px', fontSize: '0.72rem' }}
                  disabled={currentPage <= 1 || loading}
                  onClick={() => goToPage(currentPage - 1)}
                >
                  Anterior
                </button>
                <span data-testid="inbox-page-indicator" style={{ color: 'var(--text-muted)' }}>
                  Página {currentPage} de {lastPage}
                </span>
                <button
                  type="button"
                  data-testid="inbox-next-page-btn"
                  className="btn btn-secondary btn-sm"
                  style={{ padding: '2px 8px', fontSize: '0.72rem' }}
                  disabled={currentPage >= lastPage || loading}
                  onClick={() => goToPage(currentPage + 1)}
                >
                  Siguiente
                </button>
              </div>
            )}
          </div>
        )}

        {/* Right: Conversation Thread View */}
        {(!isMobile || selectedConversation) && (
          <div
            className="inbox-thread-panel"
            data-testid="inbox-thread-panel"
            style={{
              flex: 1,
              width: isMobile ? '100%' : undefined,
              background: 'white',
              borderRadius: 'var(--radius)',
              border: '1px solid var(--border)',
              display: 'flex',
              flexDirection: 'column',
              overflow: 'hidden',
              minHeight: 0,
            }}
          >
            {selectedConversation ? (
              <>
                {/* Header */}
                <div
                  className={`inbox-thread-header${isMobile ? ' inbox-thread-header--mobile' : ''}`}
                  style={{
                    borderBottom: '1px solid var(--border)',
                    background: '#f4f7f5',
                  }}
                >
                  <div className="inbox-thread-header__identity">
                    {isMobile && (
                      <button
                        type="button"
                        data-testid="back-to-inbox-btn"
                        className="btn btn-secondary btn-sm"
                        onClick={() => setSelectedConversation(null)}
                        title="Volver a la bandeja de conversaciones"
                        style={{
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: '0.3rem',
                          padding: '0.35rem 0.6rem',
                          flexShrink: 0,
                          fontWeight: 600,
                          fontSize: '0.8rem',
                        }}
                      >
                        <ArrowLeft size={16} />
                        <span>Volver</span>
                      </button>
                    )}

                    <div className="inbox-thread-header__identity-content">
                      <div style={{ fontWeight: 700, fontSize: isMobile ? '0.95rem' : '1rem', display: 'flex', alignItems: 'center', gap: '0.4rem', justifyContent: 'space-between' }}>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                          <UserIcon size={17} color="#5d8f82" style={{ flexShrink: 0 }} />
                          <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {selectedConversation.contact?.display_name || 'Contacto'}
                          </span>
                        </span>
                        {isMobile && getStatusBadge(selectedConversation.status)}
                      </div>

                      <div className="inbox-thread-header__metadata" style={{ fontSize: '0.72rem', color: 'var(--text-muted)' }}>
                        <span>Email: {selectedConversation.contact?.email || 'N/A'}</span>
                        <span>Tel: {selectedConversation.contact?.phone || 'N/A'}</span>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}>
                          Canal: {selectedConversation.channel?.name}
                          {selectedConversation.channel?.type === 'whatsapp' ? (
                            <span style={{ background: '#eaf1ee', color: '#263c38', fontWeight: 600, padding: '1px 5px', borderRadius: '4px', fontSize: '0.68rem', display: 'inline-flex', alignItems: 'center', gap: '0.2rem' }}>
                              <MessageSquare size={10} /> WA
                            </span>
                          ) : selectedConversation.channel?.type === 'facebook_messenger' ? (
                            <span style={{ background: '#ddeae4', color: '#456c61', fontWeight: 600, padding: '1px 5px', borderRadius: '4px', fontSize: '0.68rem', display: 'inline-flex', alignItems: 'center', gap: '0.2rem' }}>
                              <MessageSquare size={10} /> Messenger
                            </span>
                          ) : selectedConversation.channel?.type === 'instagram' ? (
                            <span style={{ background: '#fce7f3', color: '#be185d', fontWeight: 600, padding: '1px 5px', borderRadius: '4px', fontSize: '0.68rem', display: 'inline-flex', alignItems: 'center', gap: '0.2rem' }}>
                              <MessageSquare size={10} /> Instagram
                            </span>
                          ) : selectedConversation.channel?.type === 'telegram' ? (
                            <span style={{ background: '#e0f2fe', color: '#0369a1', fontWeight: 600, padding: '1px 5px', borderRadius: '4px', fontSize: '0.68rem', display: 'inline-flex', alignItems: 'center', gap: '0.2rem' }}>
                              <Send size={10} /> Telegram
                            </span>
                          ) : (
                            <span style={{ background: '#eaf1ee', color: '#456c61', fontWeight: 500, padding: '1px 5px', borderRadius: '4px', fontSize: '0.68rem' }}>
                              Webchat
                            </span>
                          )}
                        </span>
                        {selectedConversation.assigned_user && (
                          <span style={{ color: '#5d8f82', fontWeight: 500 }}>
                            Agente: {selectedConversation.assigned_user.name}
                          </span>
                        )}
                        {/* Meta 24h Window Badge (WhatsApp, Messenger, Instagram) */}
                        {isSelectedMeta && currentMetaWindow && (
                          isSelectedMetaOpen ? (
                            <span
                              data-testid={isSelectedWhatsApp ? 'whatsapp-window-active-badge' : isSelectedMessenger ? 'messenger-window-active-badge' : 'instagram-window-active-badge'}
                              title={`Ventana de atención de ${isSelectedWhatsApp ? 'WhatsApp' : isSelectedMessenger ? 'Facebook Messenger' : 'Instagram Direct'} abierta. Vence: ${currentMetaWindow.expires_at ? new Date(currentMetaWindow.expires_at).toLocaleTimeString() : ''}`}
                              style={{
                                background: '#eaf1ee',
                                color: '#263c38',
                                border: '1px solid #a9c8bb',
                                fontWeight: 500,
                                padding: '1px 7px',
                                borderRadius: '9999px',
                                fontSize: '0.68rem',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '0.25rem',
                              }}
                            >
                              <Clock size={10} />
                              <span>
                                Ventana {isSelectedWhatsApp ? 'WhatsApp' : isSelectedMessenger ? 'Messenger' : isSelectedInstagram ? 'Instagram' : 'Meta'} activa (vence en {formatRemainingTime(currentMetaWindow.remaining_seconds, currentMetaWindow.expires_at)})
                              </span>
                            </span>
                          ) : (
                            <span
                              data-testid={isSelectedWhatsApp ? 'whatsapp-window-expired-badge' : isSelectedMessenger ? 'messenger-window-expired-badge' : 'instagram-window-expired-badge'}
                              title={
                                isSelectedWhatsApp
                                  ? 'La ventana de 24 horas de WhatsApp ha expirado. Meta requiere plantilla preaprobada.'
                                  : `La ventana de 24 horas de ${isSelectedMessenger ? 'Facebook Messenger' : 'Instagram Direct'} ha expirado. Meta requiere que el usuario vuelva a escribir.`
                              }
                              style={{
                                background: '#fef2f2',
                                color: '#b91c1c',
                                border: '1px solid #fecaca',
                                fontWeight: 500,
                                padding: '1px 7px',
                                borderRadius: '9999px',
                                fontSize: '0.68rem',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '0.25rem',
                              }}
                            >
                              <AlertTriangle size={10} />
                              <span>
                                Ventana {isSelectedWhatsApp ? 'WhatsApp' : isSelectedMessenger ? 'Messenger' : isSelectedInstagram ? 'Instagram' : 'Meta'} vencida ({isSelectedWhatsApp ? 'requiere plantilla' : '24h expiradas'})
                              </span>
                            </span>
                          )
                        )}
                      </div>

                      {/* Active conversation tags row */}
                      <div className="inbox-thread-header__tags">
                        {((selectedConversation.tags || []) as any[]).map((tag) => {
                          const tagName = typeof tag === 'string' ? tag : tag.name;
                          const tagColor = typeof tag === 'string' ? '#5d8f82' : (tag.color || '#5d8f82');
                          const tagId = typeof tag === 'string' ? tag : (tag.id || tag.name);

                          return (
                            <span
                              key={tagId}
                              data-testid={`header-tag-${tagId}`}
                              style={{
                                fontSize: '0.72rem',
                                padding: '2px 8px',
                                borderRadius: '9999px',
                                backgroundColor: `${tagColor}18`,
                                color: tagColor,
                                border: `1px solid ${tagColor}40`,
                                fontWeight: 600,
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '0.25rem',
                              }}
                            >
                              <span>{tagName}</span>
                              <button
                                type="button"
                                data-testid={`remove-header-tag-${tagId}`}
                                onClick={() => handleRemoveTagFromHeader(tagId)}
                                style={{
                                  background: 'transparent',
                                  border: 'none',
                                  cursor: 'pointer',
                                  padding: 0,
                                  display: 'inline-flex',
                                  alignItems: 'center',
                                  color: tagColor,
                                  fontWeight: 'bold',
                                  lineHeight: 1,
                                }}
                                title={`Quitar etiqueta ${tagName}`}
                              >
                                ×
                              </button>
                            </span>
                          );
                        })}

                        {/* Button + Etiqueta */}
                        <div style={{ position: 'relative' }}>
                          <button
                            type="button"
                            data-testid="conversation-tags-btn"
                            className="btn btn-sm"
                            style={{
                              fontSize: '0.72rem',
                              padding: '2px 8px',
                              borderRadius: '9999px',
                              border: '1px dashed #d9e3de',
                              background: showTagDropdown ? '#eaf1ee' : 'transparent',
                              color: 'var(--text-muted, #61726d)',
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '0.25rem',
                              cursor: 'pointer',
                            }}
                            onClick={() => setShowTagDropdown(!showTagDropdown)}
                          >
                            <TagIcon size={11} />
                            + Etiqueta
                          </button>

                          {/* Tag Dropdown Popover */}
                          {showTagDropdown && (
                            <div
                              data-testid="tags-dropdown-popover"
                              style={{
                                position: 'absolute',
                                top: '100%',
                                left: 0,
                                marginTop: '4px',
                                zIndex: 1000,
                                background: 'white',
                                borderRadius: '8px',
                                boxShadow: '0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1)',
                                border: '1px solid #d9e3de',
                                width: '240px',
                                padding: '0.6rem',
                                display: 'flex',
                                flexDirection: 'column',
                                gap: '0.5rem',
                              }}
                            >
                              <div style={{ fontSize: '0.75rem', fontWeight: 700, color: '#56736b', borderBottom: '1px solid #eaf1ee', paddingBottom: '0.35rem' }}>
                                Asignar Etiquetas
                              </div>

                              <div style={{ maxHeight: '160px', overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
                                {allTags.map((tag) => {
                                  const isAssigned = (selectedConversation.tags || []).some((t: any) =>
                                    typeof t === 'string' ? t === tag.name || t === tag.id : t.id === tag.id || t.name === tag.name
                                  );

                                  return (
                                    <button
                                      key={tag.id}
                                      type="button"
                                      data-testid={`toggle-tag-item-${tag.id}`}
                                      onClick={() => handleToggleTag(tag)}
                                      style={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'space-between',
                                        padding: '0.3rem 0.5rem',
                                        borderRadius: '4px',
                                        border: 'none',
                                        background: isAssigned ? `${tag.color}15` : 'transparent',
                                        cursor: 'pointer',
                                        textAlign: 'left',
                                        width: '100%',
                                        fontSize: '0.78rem',
                                      }}
                                    >
                                      <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                                        <span style={{ width: '8px', height: '8px', borderRadius: '50%', backgroundColor: tag.color }} />
                                        <span style={{ fontWeight: isAssigned ? 600 : 400, color: '#35564f' }}>{tag.name}</span>
                                      </div>
                                      {isAssigned && <Check size={12} style={{ color: tag.color }} />}
                                    </button>
                                  );
                                })}
                                {allTags.length === 0 && (
                                  <span style={{ fontSize: '0.75rem', color: '#80918c', fontStyle: 'italic', padding: '0.25rem 0.5rem' }}>
                                    No hay etiquetas disponibles
                                  </span>
                                )}
                              </div>

                              {/* Inline create new tag (Supervisors and Admins only) */}
                              {isSupervisorOrAdmin && (
                                <div style={{ borderTop: '1px solid #eaf1ee', paddingTop: '0.5rem', display: 'flex', flexDirection: 'column', gap: '0.35rem' }}>
                                  <span style={{ fontSize: '0.7rem', color: '#61726d', fontWeight: 600 }}>Nueva Etiqueta:</span>
                                  <div style={{ display: 'flex', gap: '0.25rem' }}>
                                    <input
                                      type="text"
                                      data-testid="new-tag-name-input"
                                      placeholder="Nombre..."
                                      value={newTagName}
                                      onChange={(e) => setNewTagName(e.target.value)}
                                      style={{
                                        flex: 1,
                                        fontSize: '0.75rem',
                                        padding: '2px 6px',
                                        borderRadius: '4px',
                                        border: '1px solid #d9e3de',
                                      }}
                                      onKeyDown={(e) => {
                                        if (e.key === 'Enter') handleCreateTagInline();
                                      }}
                                    />
                                    <input
                                      type="color"
                                      data-testid="new-tag-color-input"
                                      value={newTagColor}
                                      onChange={(e) => setNewTagColor(e.target.value)}
                                      title="Color"
                                      style={{
                                        width: '26px',
                                        height: '24px',
                                        padding: 0,
                                        border: 'none',
                                        borderRadius: '4px',
                                        cursor: 'pointer',
                                        background: 'transparent',
                                      }}
                                    />
                                    <button
                                      type="button"
                                      data-testid="create-tag-btn"
                                      onClick={handleCreateTagInline}
                                      disabled={!newTagName.trim() || creatingTag}
                                      className="btn btn-sm btn-primary"
                                      style={{ fontSize: '0.7rem', padding: '1px 6px' }}
                                    >
                                      +
                                    </button>
                                  </div>
                                </div>
                              )}
                            </div>
                          )}
                        </div>
                      </div>
                    </div>
                  </div>

                  <div className="inbox-thread-header__toolbar">
                    {/* The primary actions stay visible; less frequent controls move into Más when the pane is narrow. */}
                    {selectedConversation.status === 'waiting_agent' && !selectedConversation.assigned_user_id && (
                      <button
                        data-testid="claim-conversation-btn"
                        className="btn btn-sm btn-success"
                        style={{
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: '0.35rem',
                          background: '#5d8f82',
                          borderColor: '#5d8f82',
                          color: 'white',
                        }}
                        disabled={claiming}
                        onClick={() => handleClaim()}
                      >
                        <UserCheck size={14} />
                        {claiming ? 'Tomando...' : 'Tomar conversación'}
                      </button>
                    )}

                    {/* Supervisor Assign Select */}
                    {hasPermission('conversations.assign') && selectedConversation.status !== 'closed' && (
                      <select
                        data-testid="assign-agent-select"
                        className="form-select"
                        aria-label="Asignar agente a la conversación"
                        style={{ fontSize: '0.8rem', padding: '0.35rem 0.6rem' }}
                        value={selectedConversation.assigned_user_id || ''}
                        onChange={(e) => handleAssign(e.target.value ? Number(e.target.value) : null)}
                      >
                        <option value="">(Sin asignar / Espera)</option>
                        {agents.map((ag) => (
                          <option key={ag.id} value={ag.id}>
                            Asignar a: {ag.name}
                          </option>
                        ))}
                      </select>
                    )}

                    {selectedConversation.status !== 'closed' && (
                      <button
                        type="button"
                        data-testid="transfer-conversation-btn"
                        className="btn btn-sm btn-outline-secondary"
                        style={{
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: '0.35rem',
                          fontSize: '0.8rem',
                          padding: '0.35rem 0.6rem',
                        }}
                        onClick={() => {
                          setTransferNote('');
                          setTransferTargetUserId(agents.find((a) => a.is_active && a.id !== selectedConversation.assigned_user_id)?.id || null);
                          setShowTransferModal(true);
                        }}
                      >
                        <ArrowRightLeft size={14} />
                        Transferir
                      </button>
                    )}

                    {canCloseSelected && (
                      <button
                        data-testid="close-conversation-btn"
                        className="btn btn-sm btn-danger"
                        style={{
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: '0.35rem',
                          background: '#dc2626',
                          borderColor: '#dc2626',
                          color: 'white',
                        }}
                        disabled={closing}
                        onClick={handleClose}
                      >
                        <XCircle size={14} />
                        {closing ? 'Cerrando...' : 'Cerrar conversación'}
                      </button>
                    )}

                    <details
                      className="inbox-thread-header__more"
                      data-testid="conversation-header-more"
                      onBlur={(event) => {
                        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
                          event.currentTarget.open = false;
                        }
                      }}
                      onClick={(event) => {
                        if ((event.target as HTMLElement).closest('button')) {
                          event.currentTarget.open = false;
                        }
                      }}
                      onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                          event.currentTarget.open = false;
                          event.currentTarget.querySelector('summary')?.focus();
                        }
                      }}
                    >
                      <summary className="btn btn-sm btn-secondary inbox-thread-header__more-trigger">
                        <MoreHorizontal size={15} aria-hidden="true" />
                        <span>Más</span>
                      </summary>
                      <div className="inbox-thread-header__more-items" role="group" aria-label="Más acciones de la conversación">
                        {execution && (
                          <button
                            type="button"
                            className={`btn btn-sm ${showExecutionPanel ? 'btn-primary' : 'btn-secondary'}`}
                            style={{ fontSize: '0.75rem', padding: '0.25rem 0.5rem', display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
                            onClick={() => setShowExecutionPanel(!showExecutionPanel)}
                            title="Observabilidad del flujo conversacional"
                          >
                            <Zap size={13} />
                            Flujo: {execution.status === 'completed' ? 'Completado' : execution.status === 'running' ? 'En ejecución' : execution.status === 'waiting_input' ? 'Esperando respuesta' : execution.status === 'waiting_agent' ? 'Esperando agente' : execution.status === 'failed' ? 'Falló' : execution.status}
                            {showExecutionPanel ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
                          </button>
                        )}

                        {selectedConversation.status !== 'closed' && (
                          <button
                            type="button"
                            data-testid="toggle-bot-btn"
                            className="btn btn-sm"
                            disabled={togglingBot}
                            onClick={handleToggleBot}
                            title={
                              selectedConversation.bot_enabled !== false
                                ? 'Bot activo: responderá automáticamente (clic para pausar)'
                                : 'Bot pausado: atención humana exclusiva (clic para reanudar)'
                            }
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '0.35rem',
                              fontSize: '0.8rem',
                              padding: '0.35rem 0.6rem',
                              background: selectedConversation.bot_enabled !== false ? '#eaf1ee' : '#fffbeb',
                              borderColor: selectedConversation.bot_enabled !== false ? '#a9c8bb' : '#fde68a',
                              color: selectedConversation.bot_enabled !== false ? '#5d8f82' : '#d97706',
                              transition: 'all 0.15s ease-in-out',
                            }}
                          >
                            <Power size={14} />
                            <span style={{ fontWeight: 500 }}>
                              {selectedConversation.bot_enabled !== false ? 'Bot Activo' : 'Bot Pausado'}
                            </span>
                          </button>
                        )}

                        {selectedConversation.status !== 'closed' && (
                          <button
                            type="button"
                            data-testid="restart-flow-btn"
                            className="btn btn-sm btn-secondary"
                            disabled={restartingFlow}
                            onClick={handleRestartFlow}
                            title="Reiniciar flujo del bot para este contacto"
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '0.35rem',
                              fontSize: '0.8rem',
                              padding: '0.35rem 0.6rem',
                            }}
                          >
                            <RotateCcw size={14} className={restartingFlow ? 'animate-spin' : ''} />
                            <span>Reiniciar flujo</span>
                          </button>
                        )}

                        <button
                          type="button"
                          data-testid="toggle-contact-panel-btn"
                          className={`btn btn-sm ${isContactPanelOpen ? 'btn-primary' : 'btn-secondary'}`}
                          style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: '0.35rem',
                            fontSize: '0.8rem',
                            padding: '0.35rem 0.6rem',
                          }}
                          onPointerDown={handleOpenContactPanel}
                          onClick={(event) => {
                            // iOS Safari can collapse <details> on the same tap
                            // before the nested action is handled. Open the drawer
                            // first, then close the menu without bubbling.
                            event.preventDefault();
                            event.stopPropagation();
                            event.currentTarget.closest('details')?.removeAttribute('open');
                            handleOpenContactPanel();
                          }}
                          title="Ver información del contacto"
                          aria-expanded={isContactPanelOpen}
                          aria-controls="contact-detail-panel"
                        >
                          {isContactPanelOpen ? <PanelRightClose size={14} /> : <PanelRightOpen size={14} />}
                          <span>Ficha 360°</span>
                        </button>
                      </div>
                    </details>

                    {!isMobile && getStatusBadge(selectedConversation.status)}
                  </div>
                </div>

                {/* Chat Body & Ficha 360 Split Container */}
                <div
                  style={{
                    flex: 1,
                    display: 'flex',
                    flexDirection: 'row',
                    overflow: 'hidden',
                    minHeight: 0,
                    position: 'relative',
                  }}
                >
                  {/* Messages & Composer Column */}
                  <div
                    style={{
                      flex: 1,
                      display: 'flex',
                      flexDirection: 'column',
                      overflow: 'hidden',
                      minWidth: 0,
                      minHeight: 0,
                    }}
                  >
                    {/* Central Sub-Tabs: Chat | Respuestas Rápidas */}
                    <div
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        borderBottom: '1px solid var(--border)',
                        background: '#ffffff',
                        padding: '0 1rem',
                        flexShrink: 0,
                        gap: '0.25rem',
                      }}
                    >
                      <button
                        type="button"
                        data-testid="thread-tab-chat"
                        onClick={() => setCenterTab('chat')}
                        style={{
                          padding: '0.65rem 0.85rem',
                          fontSize: '0.82rem',
                          fontWeight: centerTab === 'chat' ? 700 : 500,
                          color: centerTab === 'chat' ? '#5d8f82' : 'var(--text-muted)',
                          borderBottom: centerTab === 'chat' ? '2px solid #5d8f82' : '2px solid transparent',
                          background: 'transparent',
                          borderTop: 'none',
                          borderLeft: 'none',
                          borderRight: 'none',
                          cursor: 'pointer',
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: '0.35rem',
                        }}
                      >
                        <MessageSquare size={14} />
                        <span>Chat</span>
                      </button>
                      <button
                        type="button"
                        data-testid="thread-tab-canned"
                        onClick={() => setCenterTab('canned')}
                        style={{
                          padding: '0.65rem 0.85rem',
                          fontSize: '0.82rem',
                          fontWeight: centerTab === 'canned' ? 700 : 500,
                          color: centerTab === 'canned' ? '#5d8f82' : 'var(--text-muted)',
                          borderBottom: centerTab === 'canned' ? '2px solid #5d8f82' : '2px solid transparent',
                          background: 'transparent',
                          borderTop: 'none',
                          borderLeft: 'none',
                          borderRight: 'none',
                          cursor: 'pointer',
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: '0.35rem',
                        }}
                      >
                        <Zap size={14} />
                        <span>Respuestas rápidas</span>
                        {cannedResponses.length > 0 && (
                          <span
                            style={{
                              background: centerTab === 'canned' ? '#ddeae4' : '#eaf1ee',
                              color: centerTab === 'canned' ? '#456c61' : '#61726d',
                              fontSize: '0.7rem',
                              padding: '1px 6px',
                              borderRadius: '9999px',
                              fontWeight: 600,
                            }}
                          >
                            {cannedResponses.length}
                          </span>
                        )}
                      </button>
                    </div>

                    {centerTab === 'canned' ? (
                      <CannedResponsesTab
                        responses={cannedResponses}
                        loading={loadingCanned}
                        canManage={canManageCanned}
                        onUseInChat={handleUseInChat}
                        onCreate={handleOpenCreateCanned}
                        onEdit={handleOpenEditCanned}
                        onDelete={handleDeleteCanned}
                      />
                    ) : (
                      <>
              {/* Execution Panel (Collapsible) */}
              {showExecutionPanel && execution && (
                <div
                  style={{
                    background: '#eaf1ee',
                    borderBottom: '1px solid var(--border)',
                    padding: '0.85rem 1.25rem',
                    fontSize: '0.8rem',
                    maxHeight: '260px',
                    overflowY: 'auto',
                  }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '0.5rem' }}>
                    <div>
                      <span style={{ fontWeight: 700, color: '#35564f', display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}>
                        <GitBranch size={13} /> Flujo: {execution.flow?.name || 'Desconocido'} (v{execution.version?.version_number ?? '?'})
                      </span>
                      <span style={{ marginLeft: '0.75rem' }}>{getExecutionStatusBadge(execution.status)}</span>
                    </div>
                    <div style={{ color: 'var(--text-muted)', fontSize: '0.75rem' }}>
                      Iniciado: {new Date(execution.started_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                    </div>
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.5rem', marginBottom: '0.75rem' }}>
                    <div>
                      <strong>Nodo actual:</strong> {execution.current_node ? `${execution.current_node.name} [${execution.current_node.type}]` : 'Ninguno (Inactivo/Completado)'}
                    </div>
                    {execution.waiting_since && (
                      <div>
                        <strong>Esperando respuesta desde:</strong> {new Date(execution.waiting_since).toLocaleTimeString()}
                      </div>
                    )}
                    {execution.completed_at && (
                      <div>
                        <strong>Completado en:</strong> {new Date(execution.completed_at).toLocaleTimeString()}
                      </div>
                    )}
                    {execution.failed_at && (
                      <div style={{ color: '#dc2626' }}>
                        <strong>Fallo [{execution.failure_code}]:</strong> {new Date(execution.failed_at).toLocaleTimeString()}
                      </div>
                    )}
                  </div>

                  {isSupervisorOrAdmin && Object.keys(execution.context || {}).length > 0 && (
                    <div style={{ marginBottom: '0.75rem' }}>
                      <strong>Contexto de Variables:</strong>
                      <pre style={{ margin: '0.25rem 0', padding: '0.35rem 0.5rem', background: 'white', borderRadius: '4px', fontSize: '0.75rem', border: '1px solid #d9e3de' }}>
                        {JSON.stringify(execution.context, null, 2)}
                      </pre>
                    </div>
                  )}

                  <div>
                    <strong style={{ display: 'block', marginBottom: '0.25rem' }}>Últimos pasos ejecutados:</strong>
                    {execution.steps && execution.steps.length > 0 ? (
                      <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
                        {execution.steps.slice(-5).map((step) => (
                          <div
                            key={step.id}
                            style={{
                              background: 'white',
                              padding: '0.3rem 0.6rem',
                              borderRadius: '4px',
                              border: '1px solid #d9e3de',
                              display: 'flex',
                              justifyContent: 'space-between',
                              alignItems: 'center',
                              fontSize: '0.75rem',
                            }}
                          >
                            <span>
                              <strong>{step.node_name || step.node_key || 'Nodo'}</strong> ({step.node_type})
                              {step.error_code && <span style={{ color: '#dc2626', marginLeft: '0.5rem' }}>[{step.error_code}]</span>}
                            </span>
                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.5rem' }}>
                              <span className={`badge ${step.status === 'completed' ? 'badge-success' : step.status === 'failed' ? 'badge-danger' : 'badge-warning'}`} style={{ fontSize: '0.65rem' }}>
                                {step.status === 'completed' ? 'Completado' : step.status === 'failed' ? 'Falló' : step.status === 'waiting' ? 'En espera' : step.status === 'entered' ? 'Iniciado' : step.status === 'skipped' ? 'Omitido' : step.status}
                              </span>
                              <span style={{ color: 'var(--text-muted)' }}>
                                {new Date(step.entered_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                              </span>
                            </span>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <span style={{ color: 'var(--text-muted)' }}>Sin pasos registrados.</span>
                    )}
                  </div>
                </div>
              )}

              {/* Message History */}
              <div
                ref={messagesContainerRef}
                className={`inbox-thread-messages${selectedConversation.status === 'closed' && !canReplyToClosedMeta ? ' inbox-thread-messages--closed' : ''}`}
                onScroll={handleScroll}
                style={{
                  flex: 1,
                  padding: '1.25rem',
                  overflowY: 'auto',
                  display: 'flex',
                  flexDirection: 'column',
                  gap: '0.75rem',
                  background: '#f4f7f5',
                }}
              >
                <div
                  ref={messagesContentRef}
                  style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: '0.75rem',
                    minHeight: '100%',
                  }}
                >
                {/* Pagination: Load older messages */}
                {hasMoreMessages && (
                  <div style={{ textAlign: 'center', margin: '0.25rem 0' }}>
                    <button
                      data-testid="load-older-messages-btn"
                      className="btn btn-sm btn-secondary"
                      disabled={loadingMore}
                      onClick={handleLoadOlderMessages}
                      style={{ fontSize: '0.75rem', padding: '0.3rem 0.8rem' }}
                    >
                      {loadingMore ? 'Cargando mensajes anteriores...' : 'Cargar mensajes anteriores'}
                    </button>
                  </div>
                )}

                {sortedMessages && sortedMessages.length > 0 ? (
                  sortedMessages.map((msg) => {
                    const isOutbound = msg.direction === 'outbound';
                    const isSystem = msg.message_type === 'system' || msg.sender_type === 'system';
                    const isDivider =
                      (msg.metadata as any)?.event === 'session_divider' ||
                      (msg.content && msg.content.includes('Sesión finalizada'));
                    const sessionBoundary = (msg.metadata as any)?.session_divider_before;

                    if (isDivider) {
                      const rawTs = (msg.metadata as any)?.timestamp || msg.sent_at || msg.created_at;
                      return <SessionDivider key={msg.id} timestamp={rawTs} />;
                    }

                    if (isSystem) {
                      return (
                        <div
                          key={msg.id}
                          style={{
                            alignSelf: 'center',
                            maxWidth: '85%',
                            background: '#fee2e2',
                            color: '#991b1b',
                            borderRadius: '8px',
                            padding: '0.5rem 1rem',
                            fontSize: '0.8rem',
                            border: '1px solid #fecaca',
                            textAlign: 'center',
                            margin: '0.5rem 0',
                          }}
                        >
                          <div style={{ fontWeight: 600, marginBottom: '0.15rem' }}>
                            [SISTEMA] {new Date(msg.sent_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                          </div>
                          <LinkifiedText text={msg.content} />
                        </div>
                      );
                    }

                    const attachment = msg.payload?.attachment || msg.metadata?.attachment;
                    const safeAttachmentUrl = resolveMediaUrl(attachment?.url);
                    const isAudio = msg.message_type === 'audio' || attachment?.mime_type?.startsWith('audio/') || Boolean(attachment?.is_voice_note);
                    const isImage = !isAudio && msg.message_type === 'image';
                    const isFile = !isAudio && (msg.message_type === 'file' || (!isImage && Boolean(safeAttachmentUrl)));
                    const isDefaultAudioContent = isAudio && (msg.content === '🎵 Nota de voz' || msg.content === '🎵 Audio');
                    const reactionCounts = Object.values(msg.metadata?.reactions || {}).reduce<Record<string, number>>((counts, reaction) => {
                      const emoji = typeof reaction === 'string' ? reaction : (reaction as any)?.emoji;
                      if (typeof emoji === 'string' && emoji !== '') {
                        counts[emoji] = (counts[emoji] || 0) + 1;
                      }
                      return counts;
                    }, {});

                    return (
                      <React.Fragment key={msg.id}>
                        {sessionBoundary && <SessionDivider timestamp={sessionBoundary.timestamp} />}
                        <div
                          style={{
                            alignSelf: isOutbound ? 'flex-end' : 'flex-start',
                            maxWidth: '70%',
                          }}
                        >
                        <div
                          style={{
                            background: isOutbound ? (msg.sender_type === 'bot' ? '#0284c7' : '#5d8f82') : '#ffffff',
                            color: isOutbound ? '#ffffff' : '#263c38',
                            borderRadius: '12px',
                            padding: '0.75rem 1rem',
                            boxShadow: 'var(--shadow)',
                            fontSize: '0.9rem',
                            border: isOutbound ? 'none' : '1px solid var(--border)',
                          }}
                        >
                          <div style={{ fontSize: '0.7rem', opacity: 0.85, marginBottom: '0.25rem', display: 'flex', justifyContent: 'space-between', gap: '1rem', alignItems: 'center' }}>
                            <span>
                              {msg.sender_type === 'bot' ? '🤖 BOT' : msg.sender_type === 'agent' ? '👤 AGENTE' : 'VISITANTE'}
                            </span>
                            <span style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
                              <span>{new Date(msg.sent_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                              {isOutbound && <MessageDeliveryStatus message={msg} />}
                            </span>
                          </div>

                          {/* Audio Attachment Rendering */}
                          {isAudio && safeAttachmentUrl && (
                            <div style={{ marginBottom: msg.content && !isDefaultAudioContent ? '0.5rem' : '0' }}>
                              <AudioMessagePlayer
                                url={safeAttachmentUrl}
                                fileName={attachment?.file_name}
                                sizeBytes={attachment?.size_bytes}
                                isOutbound={isOutbound}
                                isVoiceNote={Boolean(attachment?.is_voice_note)}
                              />
                            </div>
                          )}

                          {/* Image Attachment Rendering */}
                          {isImage && safeAttachmentUrl && (
                            <div style={{ marginBottom: msg.content ? '0.5rem' : '0' }}>
                              <a
                                href={safeAttachmentUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                style={{ display: 'block', borderRadius: '8px', overflow: 'hidden' }}
                              >
                                <img
                                  src={safeAttachmentUrl}
                                  alt={attachment?.file_name || 'Imagen adjunta'}
                                  onLoad={() => {
                                    if (!userScrolledUpRef.current) {
                                      scrollToBottom('smooth');
                                    }
                                  }}
                                  style={{
                                    maxWidth: '100%',
                                    maxHeight: '260px',
                                    objectFit: 'cover',
                                    borderRadius: '8px',
                                    display: 'block',
                                    cursor: 'pointer',
                                  }}
                                />
                              </a>
                            </div>
                          )}

                          {/* Document / File Attachment Rendering */}
                          {isFile && safeAttachmentUrl && (
                            <div
                              style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: '0.6rem',
                                padding: '0.6rem 0.8rem',
                                background: isOutbound ? 'rgba(255, 255, 255, 0.18)' : '#f4f7f5',
                                border: isOutbound ? '1px solid rgba(255, 255, 255, 0.3)' : '1px solid #d9e3de',
                                borderRadius: '8px',
                                marginBottom: msg.content ? '0.5rem' : '0',
                              }}
                            >
                              <FileText size={22} style={{ flexShrink: 0, color: isOutbound ? '#ffffff' : '#5d8f82' }} />
                              <div style={{ flex: 1, minWidth: 0 }}>
                                <div style={{ fontSize: '0.85rem', fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: isOutbound ? '#ffffff' : '#263c38' }}>
                                  {attachment?.file_name || 'Documento adjunto'}
                                </div>
                                {attachment?.size_bytes ? (
                                  <div style={{ fontSize: '0.75rem', opacity: 0.85, color: isOutbound ? '#d9e3de' : '#61726d' }}>
                                    {formatFileSize(attachment.size_bytes)}
                                  </div>
                                ) : null}
                              </div>
                              <a
                                href={safeAttachmentUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                download={attachment?.file_name}
                                style={{
                                  color: isOutbound ? '#ffffff' : '#5d8f82',
                                  display: 'flex',
                                  alignItems: 'center',
                                  padding: '4px',
                                  borderRadius: '4px',
                                  }}
                                title="Descargar archivo"
                              >
                                <Download size={18} />
                              </a>
                            </div>
                          )}

                          {msg.content && !isDefaultAudioContent && (
                            <LinkifiedText
                              text={msg.content}
                              linkClassName={
                                isOutbound
                                  ? 'text-white underline hover:opacity-85 break-words inline-block'
                                  : 'text-blue-600 dark:text-blue-400 underline hover:text-blue-800 break-words inline-block'
                              }
                            />
                          )}

                          {/* Interactive Options Preview (Bot Quick Replies / Menu Options) */}
                          {(() => {
                            const interactiveOptions = extractInteractiveOptions(msg);
                            if (interactiveOptions.length === 0) return null;
                            return (
                              <div
                                data-testid="interactive-options-preview"
                                style={{
                                  display: 'flex',
                                  flexWrap: 'wrap',
                                  gap: '6px',
                                  marginTop: '8px',
                                }}
                              >
                                {interactiveOptions.map((opt, idx) => (
                                  <span
                                    key={opt.id || idx}
                                    style={{
                                      padding: '4px 10px',
                                      borderRadius: '12px',
                                      fontSize: '0.75rem',
                                      fontWeight: 600,
                                      background: isOutbound ? 'rgba(255, 255, 255, 0.2)' : '#d9e3de',
                                      color: isOutbound ? '#ffffff' : '#56736b',
                                      border: isOutbound ? '1px solid rgba(255, 255, 255, 0.35)' : '1px solid #d9e3de',
                                      display: 'inline-flex',
                                      alignItems: 'center',
                                      gap: '4px',
                                    }}
                                  >
                                    <span style={{ fontSize: '0.7rem' }}>🔘</span> {opt.title}
                                  </span>
                                ))}
                              </div>
                            );
                          })()}
                        </div>
                        </div>
                        {Object.entries(reactionCounts).length > 0 && (
                          <div
                            aria-label="Reacciones del mensaje"
                            style={{
                              alignSelf: isOutbound ? 'flex-end' : 'flex-start',
                              display: 'flex',
                              flexWrap: 'wrap',
                              gap: '0.25rem',
                              marginTop: '-0.35rem',
                              padding: '0 0.35rem',
                            }}
                          >
                            {Object.entries(reactionCounts).map(([emoji, count]) => (
                              <span
                                key={emoji}
                                title={`${count} reacción${count === 1 ? '' : 'es'}`}
                                style={{
                                  display: 'inline-flex',
                                  alignItems: 'center',
                                  gap: '0.25rem',
                                  padding: '0.15rem 0.45rem',
                                  borderRadius: '999px',
                                  border: '1px solid var(--border)',
                                  background: 'var(--surface, #ffffff)',
                                  boxShadow: 'var(--shadow)',
                                  fontSize: '0.8rem',
                                  lineHeight: 1.2,
                                }}
                              >
                                <span>{emoji}</span>
                                {count > 1 && <span>{count}</span>}
                              </span>
                            ))}
                          </div>
                        )}
                      </React.Fragment>
                    );
                  })
                ) : (
                  <div style={{ margin: 'auto', textAlign: 'center', color: 'var(--text-muted)' }}>
                    <Radio size={32} style={{ opacity: 0.3, marginBottom: '0.5rem' }} />
                    <p>No hay mensajes en esta conversación aún.</p>
                  </div>
                )}
                  <div ref={messagesEndRef} className="h-0 w-full shrink-0" aria-hidden="true" />
                </div>
              </div>

              {/* Reply Box or Closed Banner */}
              {selectedConversation.status === 'closed' && !canReplyToClosedMeta ? (
                <div
                  data-testid="conversation-closed-banner"
                  style={{
                    padding: '0.9rem 1rem',
                    borderTop: '1px solid var(--border)',
                    background: '#eaf1ee',
                    textAlign: 'center',
                    color: '#61726d',
                    fontSize: '0.85rem',
                    fontWeight: 600,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: '0.5rem',
                  }}
                >
                  <CheckCircle2 size={16} color="#61726d" />
                  Conversación cerrada. No se admiten nuevos mensajes.
                </div>
              ) : (
                hasPermission('conversations.reply') && (
                  <div>
                    {/* Selected Attachment Preview Bar */}
                    {selectedFile && (
                      <div
                        data-testid="attachment-preview-bar"
                        style={{
                          padding: '0.5rem 1rem',
                          background: '#f4f7f5',
                          borderTop: '1px solid var(--border)',
                          display: 'flex',
                          alignItems: 'center',
                          gap: '0.75rem',
                        }}
                      >
                        {filePreviewUrl ? (
                          <img
                            src={filePreviewUrl}
                            alt="Previsualización"
                            style={{ width: '42px', height: '42px', objectFit: 'cover', borderRadius: '4px', border: '1px solid #d9e3de' }}
                          />
                        ) : (
                          <div style={{ padding: '8px', background: '#d9e3de', borderRadius: '4px', color: '#61726d' }}>
                            <FileText size={20} />
                          </div>
                        )}
                        <div style={{ flex: 1, minWidth: 0, fontSize: '0.82rem' }}>
                          <div style={{ fontWeight: 600, color: '#35564f', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {selectedFile.name}
                          </div>
                          <div style={{ fontSize: '0.75rem', color: '#61726d' }}>
                            {formatFileSize(selectedFile.size)}
                          </div>
                        </div>
                        <button
                          type="button"
                          onClick={handleClearFile}
                          className="btn btn-sm btn-ghost"
                          style={{ padding: '4px 6px', color: '#61726d', cursor: 'pointer' }}
                          title="Quitar archivo adjunto"
                        >
                          <X size={16} />
                        </button>
                      </div>
                    )}

                    {/* File validation error banner */}
                    {(fileError || voiceRecorder.errorMessage) && (
                      <div
                        style={{
                          padding: '0.45rem 1rem',
                          background: '#fef2f2',
                          color: '#dc2626',
                          fontSize: '0.8rem',
                          borderTop: '1px solid #fecaca',
                          display: 'flex',
                          alignItems: 'center',
                          gap: '0.5rem',
                        }}
                      >
                        <AlertCircle size={15} />
                        <span>{fileError || voiceRecorder.errorMessage}</span>
                      </div>
                    )}

                    {/* Paused Bot Banner */}
                    {selectedConversation.bot_enabled === false && (
                      <div
                        data-testid="paused-bot-banner"
                        style={{
                          padding: '0.45rem 1rem',
                          background: '#fffbeb',
                          color: '#b45309',
                          fontSize: '0.8rem',
                          borderTop: '1px solid #fde68a',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'space-between',
                          gap: '0.5rem',
                        }}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                          <PauseCircle size={15} style={{ color: '#d97706', flexShrink: 0 }} />
                          <span>
                            El bot se encuentra en pausa para esta conversación. Los mensajes entrantes no recibirán respuestas automáticas.
                          </span>
                        </div>
                        <button
                          type="button"
                          onClick={handleToggleBot}
                          disabled={togglingBot}
                          style={{
                            background: '#d97706',
                            color: 'white',
                            border: 'none',
                            borderRadius: '4px',
                            padding: '0.2rem 0.6rem',
                            fontSize: '0.75rem',
                            fontWeight: 500,
                            cursor: 'pointer',
                            whiteSpace: 'nowrap',
                          }}
                        >
                          Reanudar Bot
                        </button>
                      </div>
                    )}

                    {/* Meta 24h Window Expired Banner (WhatsApp, Messenger, Instagram) */}
                    {isSelectedMetaExpired && (
                      <div
                        data-testid={isSelectedWhatsApp ? 'whatsapp-24h-expired-banner' : isSelectedMessenger ? 'messenger-24h-expired-banner' : 'instagram-24h-expired-banner'}
                        style={{
                          padding: '0.85rem 1.25rem',
                          background: '#fffbeb',
                          borderTop: '1px solid #fde68a',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'space-between',
                          gap: '1rem',
                          flexWrap: 'wrap',
                        }}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                          <AlertTriangle size={20} style={{ color: '#d97706', flexShrink: 0 }} />
                          <div>
                            <div style={{ fontWeight: 600, fontSize: '0.875rem', color: '#92400e' }}>
                              Ventana de atención de 24 horas de {isSelectedWhatsApp ? 'WhatsApp' : isSelectedMessenger ? 'Facebook Messenger' : isSelectedInstagram ? 'Instagram Direct' : 'Meta'} expirada.
                            </div>
                            <div style={{ fontSize: '0.78rem', color: '#b45309' }}>
                              {isSelectedWhatsApp
                                ? 'Haz clic aquí para reabrirla con una plantilla preaprobada de WhatsApp.'
                                : `Las políticas de Meta impiden enviar respuestas por ${isSelectedMessenger ? 'Facebook Messenger' : 'Instagram Direct'} fuera de la ventana de 24 horas. La ventana se reactivará en cuanto el usuario vuelva a escribir.`}
                            </div>
                          </div>
                        </div>
                        {isSelectedWhatsApp && (
                          <button
                            type="button"
                            data-testid="reopen-with-template-btn"
                            className="btn btn-sm"
                            onClick={() => setIsTemplateModalOpen(true)}
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '0.4rem',
                              background: '#d97706',
                              borderColor: '#d97706',
                              color: 'white',
                              fontWeight: 600,
                              fontSize: '0.8rem',
                              padding: '0.4rem 0.85rem',
                              borderRadius: '6px',
                              whiteSpace: 'nowrap',
                            }}
                          >
                            <FileText size={14} />
                            <span>Reabrir con plantilla</span>
                          </button>
                        )}
                      </div>
                    )}

                    <form
                      onSubmit={handleSendMessage}
                      style={{
                        padding: '0.75rem 1rem',
                        borderTop: '1px solid var(--border)',
                        display: 'flex',
                        alignItems: 'center',
                        gap: '0.5rem',
                        background: 'white',
                        position: 'relative',
                      }}
                    >
                      {voiceRecorder.isRecording ? (
                        <div
                          data-testid="voice-recording-bar"
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '0.75rem',
                            flex: 1,
                            padding: '0.25rem 0.5rem',
                          }}
                        >
                          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flex: 1 }}>
                            <span
                              style={{
                                width: '10px',
                                height: '10px',
                                borderRadius: '50%',
                                backgroundColor: '#ef4444',
                                display: 'inline-block',
                              }}
                            />
                            <span style={{ fontSize: '0.85rem', fontWeight: 600, color: '#ef4444' }}>
                              Grabando audio... {voiceRecorder.formattedDuration}
                            </span>
                          </div>

                          <button
                            type="button"
                            data-testid="cancel-voice-record-btn"
                            className="btn btn-secondary"
                            onClick={voiceRecorder.cancelRecording}
                            title="Cancelar grabación"
                            style={{
                              padding: '0.5rem 0.75rem',
                              display: 'flex',
                              alignItems: 'center',
                              gap: '0.35rem',
                              color: '#ef4444',
                            }}
                          >
                            <Trash2 size={16} /> Cancelar
                          </button>

                          <button
                            type="button"
                            data-testid="send-voice-record-btn"
                            className="btn btn-primary"
                            onClick={handleSendVoiceNote}
                            disabled={sending}
                            title="Enviar nota de voz"
                            style={{
                              padding: '0.5rem 0.75rem',
                              display: 'flex',
                              alignItems: 'center',
                              gap: '0.35rem',
                              backgroundColor: '#5d8f82',
                              borderColor: '#5d8f82',
                            }}
                          >
                            <Send size={16} /> Enviar audio
                          </button>
                        </div>
                      ) : (
                        <>
                          {/* Autocomplete Popover */}
                          <CannedResponseAutocomplete
                            isOpen={isSlashMenuOpen}
                            filterText={slashFilter}
                            responses={cannedResponses}
                            selectedIndex={selectedSlashIndex}
                            onSelect={handleSelectCannedResponse}
                            onClose={() => setIsSlashMenuOpen(false)}
                          />

                          {/* Hidden File Input */}
                          <input
                            ref={fileInputRef}
                            type="file"
                            data-testid="agent-attachment-input"
                            style={{ display: 'none' }}
                            onChange={handleFileSelect}
                            accept="image/jpeg,image/png,image/webp,image/gif,audio/ogg,audio/opus,audio/mpeg,audio/mp3,audio/wav,audio/x-m4a,audio/aac,audio/webm,application/pdf,text/plain,text/csv,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                          />

                          {/* Attach Button */}
                          <button
                            type="button"
                            data-testid="agent-attach-btn"
                            className="btn btn-secondary"
                            onClick={() => fileInputRef.current?.click()}
                            disabled={sending || isSelectedWAExpired}
                            title="Adjuntar imagen (máx 5MB), audio (máx 16MB) o documento (máx 10MB)"
                            style={{ padding: '0.5rem 0.75rem', display: 'flex', alignItems: 'center', justifyContent: 'center' }}
                          >
                            <Paperclip size={16} />
                          </button>

                          {/* Quick Access Canned Responses Button */}
                          <button
                            type="button"
                            data-testid="canned-responses-trigger-btn"
                            className={`btn ${isSlashMenuOpen ? 'btn-primary' : 'btn-secondary'}`}
                            onClick={handleToggleCannedMenu}
                            disabled={sending || isSelectedWAExpired}
                            title="Respuestas rápidas (/)"
                            style={{
                              padding: '0.5rem 0.75rem',
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'center',
                              color: isSlashMenuOpen ? '#ffffff' : '#5d8f82',
                            }}
                          >
                            <Zap size={16} />
                          </button>

                          <input
                            ref={replyInputRef}
                            data-testid="agent-reply-input"
                            type="text"
                            className="form-input"
                            placeholder={
                              isSelectedMetaExpired
                                ? isSelectedWhatsApp
                                  ? 'Ventana de WhatsApp de 24h vencida. Reabre con una plantilla preaprobada...'
                                  : isSelectedMessenger
                                    ? 'Ventana de Messenger de 24h vencida. Esperando que el usuario vuelva a escribir...'
                                    : 'Ventana de Instagram de 24h vencida. Esperando que el usuario vuelva a escribir...'
                                : selectedConversation.status === 'closed'
                                  ? 'Escribe una respuesta como agente para reabrir esta conversación (o usa /)...'
                                  : 'Escribe una respuesta como agente (o usa / para respuestas rápidas)...'
                            }
                            value={replyText}
                            onChange={handleReplyChange}
                            onKeyDown={handleReplyKeyDown}
                            disabled={sending || isSelectedWAExpired}
                            style={{ flex: 1 }}
                          />

                          {/* Voice Note Record Mic Button */}
                          <button
                            type="button"
                            data-testid="agent-mic-btn"
                            className="btn btn-secondary"
                            onClick={voiceRecorder.startRecording}
                            disabled={sending || isSelectedWAExpired}
                            title="Grabar nota de voz"
                            style={{
                              padding: '0.5rem 0.75rem',
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'center',
                              color: '#61726d',
                            }}
                          >
                            <Mic size={16} />
                          </button>

                          <button
                            data-testid="agent-reply-submit"
                            type="submit"
                            className="btn btn-primary"
                            disabled={
                              sending ||
                              isSelectedWAExpired ||
                              (!replyText.trim() && !selectedFile)
                            }
                          >
                            <Send size={16} /> Enviar
                          </button>
                        </>
                      )}
                    </form>
                  </div>
                )
              )}
                      </>
                    )}
                  </div>

                  {/* Ficha 360° Contact Detail Panel */}
                  <ContactDetailPanel
                    conversation={selectedConversation}
                    isOpen={isContactPanelOpen}
                    onClose={handleCloseContactPanel}
                    onContactUpdated={handleContactUpdated}
                    onTagAdded={handleTagAdded}
                    onTagRemoved={handleTagRemoved}
                    onNoteAdded={handleNoteAdded}
                  />
                </div>
              </>
          ) : (
            <div style={{ margin: 'auto', textAlign: 'center', color: 'var(--text-muted)', padding: '2rem' }}>
              <MessageSquare size={48} style={{ opacity: 0.25, marginBottom: '0.75rem', color: '#5d8f82' }} />
              <h3 style={{ fontSize: '1.05rem', fontWeight: 600, color: '#35564f', marginBottom: '0.35rem' }}>
                Bandeja de Entrada Conversacional
              </h3>
              <p style={{ fontSize: '0.85rem', maxWidth: '360px', margin: '0 auto', lineHeight: 1.5 }}>
                Selecciona una conversación de la lista para ver el hilo de mensajes, contexto del contacto y responder en tiempo real.
              </p>
            </div>
          )}
        </div>
      )}
      </div>

      {/* Canned Response Create/Edit Modal */}
      <CannedResponseModal
        isOpen={cannedModalOpen}
        cannedResponse={editingCanned}
        onClose={() => setCannedModalOpen(false)}
        onSaved={handleSavedCanned}
      />

      {/* WhatsApp Template Reopen Modal */}
      <WhatsAppTemplateModal
        isOpen={isTemplateModalOpen}
        onClose={() => setIsTemplateModalOpen(false)}
        contactName={selectedConversation?.contact?.display_name || selectedConversation?.contact?.name || 'Cliente'}
        channelId={selectedConversation?.channel_id}
        conversationId={selectedConversation?.id}
        onTemplateSent={() => {
          if (selectedConversation?.id) {
            loadConversationDetails(selectedConversation.id);
          }
          fetchConversations(true);
        }}
      />

      {/* Restart Flow Confirmation Modal */}
      <ConfirmModal
        isOpen={isRestartFlowModalOpen}
        onClose={() => setIsRestartFlowModalOpen(false)}
        onConfirm={handleConfirmRestartFlow}
        title="¿Reiniciar flujo del bot?"
        description="Esta acción cancelará cualquier paso activo en la conversación y enviará el mensaje y menú inicial del canal al contacto."
        confirmText="Sí, reiniciar flujo"
        cancelText="Cancelar"
        confirmVariant="primary"
        icon={<RotateCcw size={24} />}
        isLoading={restartingFlow}
        testId="restart-flow-modal"
        confirmTestId="confirm-restart-flow-btn"
        cancelTestId="cancel-restart-flow-btn"
      />

      {/* Close Conversation Confirmation Modal */}
      <ConfirmModal
        isOpen={isCloseConversationModalOpen}
        onClose={() => setIsCloseConversationModalOpen(false)}
        onConfirm={handleConfirmClose}
        title="¿Cerrar conversación?"
        description={`¿Deseas cerrar definitivamente la conversación con ${
          selectedConversation?.contact?.display_name || selectedConversation?.contact?.name || 'este contacto'
        }? El cliente no podrá enviar nuevos mensajes en esta sesión.`}
        confirmText="Sí, cerrar conversación"
        cancelText="Cancelar"
        confirmVariant="danger"
        icon={<XCircle size={24} />}
        isLoading={closing}
        testId="close-conversation-modal"
        confirmTestId="confirm-close-conversation-btn"
        cancelTestId="cancel-close-conversation-btn"
      />

      {/* Transfer Conversation Modal */}
      {showTransferModal && selectedConversation && (
        <div
          className="modal-overlay"
          role="dialog"
          aria-modal="true"
          aria-label="Transferir conversación"
          data-testid="transfer-conversation-modal"
          style={{
            position: 'fixed',
            inset: 0,
            backgroundColor: 'rgba(15, 23, 42, 0.5)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 9999,
            padding: '1rem',
          }}
        >
          <div
            className="modal-content side-panel-compact"
            style={{
              background: 'white',
              borderRadius: '12px',
              maxWidth: '720px',
              width: '100%',
              boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)',
              overflow: 'hidden',
              display: 'flex',
              flexDirection: 'column',
            }}
          >
            {/* Modal Header */}
            <div className="flow-side-panel-header">
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                <ArrowRightLeft size={18} style={{ color: 'var(--primary, #5d8f82)' }} />
                <h3 style={{ margin: 0, fontSize: '1rem', fontWeight: 600, color: '#35564f' }}>
                  Transferir Conversación
                </h3>
              </div>
              <button
                type="button"
                data-testid="close-transfer-modal-btn"
                onClick={() => setShowTransferModal(false)}
                style={{
                  background: 'transparent',
                  border: 'none',
                  cursor: 'pointer',
                  color: '#61726d',
                }}
              >
                <X size={18} />
              </button>
            </div>

            {/* Modal Body */}
            <div className="flow-side-panel-body" style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
              <div>
                <label style={{ display: 'block', fontSize: '0.825rem', fontWeight: 600, color: '#56736b', marginBottom: '0.35rem' }}>
                  Agente de Destino *
                </label>
                <select
                  data-testid="transfer-target-agent-select"
                  className="form-select"
                  value={transferTargetUserId || ''}
                  onChange={(e) => setTransferTargetUserId(Number(e.target.value) || null)}
                  style={{ width: '100%', fontSize: '0.875rem' }}
                >
                  <option value="">Selecciona un agente...</option>
                  {agents
                    .filter((ag) => ag.is_active && ag.id !== selectedConversation.assigned_user_id)
                    .map((ag) => (
                      <option key={ag.id} value={ag.id}>
                        {ag.name} ({ag.email})
                      </option>
                    ))}
                </select>
                {agents.filter((ag) => ag.is_active && ag.id !== selectedConversation.assigned_user_id).length === 0 && (
                  <p style={{ fontSize: '0.75rem', color: '#dc2626', marginTop: '0.25rem', margin: 0 }}>
                    No hay otros agentes activos disponibles para transferir.
                  </p>
                )}
              </div>

              <div>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.35rem' }}>
                  <label style={{ fontSize: '0.825rem', fontWeight: 600, color: '#56736b', margin: 0 }}>
                    Nota de Traspaso (Opcional)
                  </label>
                  <span style={{ fontSize: '0.72rem', color: transferNote.length > 900 ? '#dc2626' : '#80918c' }}>
                    {transferNote.length} / 1000
                  </span>
                </div>
                <textarea
                  data-testid="transfer-note-input"
                  rows={3}
                  maxLength={1000}
                  className="form-control"
                  placeholder="Motivo o contexto de la transferencia..."
                  value={transferNote}
                  onChange={(e) => setTransferNote(e.target.value)}
                  style={{ width: '100%', fontSize: '0.85rem', resize: 'vertical' }}
                />
                <span style={{ fontSize: '0.72rem', color: '#61726d' }}>
                  Esta nota se registrará como un mensaje interno en el chat y será visible para el equipo.
                </span>
              </div>
            </div>

            {/* Modal Footer */}
            <div className="flow-side-panel-footer">
              <button
                type="button"
                data-testid="cancel-transfer-btn"
                className="btn btn-secondary btn-sm"
                onClick={() => setShowTransferModal(false)}
                disabled={transferring}
              >
                Cancelar
              </button>
              <button
                type="button"
                data-testid="confirm-transfer-btn"
                className="btn btn-primary btn-sm"
                disabled={!transferTargetUserId || transferring}
                onClick={handleConfirmTransfer}
                style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
              >
                {transferring ? (
                  <>
                    <RotateCcw size={13} className="animate-spin" />
                    Transfiriendo...
                  </>
                ) : (
                  <>
                    <ArrowRightLeft size={13} />
                    Confirmar Transferencia
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Canned Response Delete Confirmation Modal */}
      <ConfirmModal
        isOpen={Boolean(cannedToDelete)}
        onClose={() => setCannedToDelete(null)}
        onConfirm={executeDeleteCanned}
        title={`¿Eliminar respuesta rápida «${cannedToDelete?.title}»?`}
        description="Esta acción es permanente y no se puede deshacer. Escribe el nombre exacto para confirmar."
        confirmText="Eliminar respuesta"
        cancelText="Cancelar"
        confirmVariant="danger"
        testId="confirm-delete-canned-modal"
        requiredText={cannedToDelete?.title}
        requiredTextLabel="respuesta rápida"
      />

    </div>
  );
};
