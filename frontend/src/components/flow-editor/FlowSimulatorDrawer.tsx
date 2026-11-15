import React, { useState, useEffect, useRef, useCallback } from 'react';
import {
  Play,
  RotateCcw,
  Square,
  X,
  Send,
  AlertCircle,
  Activity,
  Layers,
  Variable,
  UserCheck,
  CornerDownRight,
  Maximize2,
  Plus,
  Trash2,
  ImageOff,
  FileText,
  Loader2,
} from 'lucide-react';
import { api } from '../../services/api';
import { resolveMediaUrl } from '../../utils/media';
import { LinkifiedText } from '../common/LinkifiedText';
import type { Flow, FlowVersion, SimulationState, SimulationEvent } from '../../types';

interface FlowSimulatorDrawerProps {
  isOpen: boolean;
  flow: Flow | null;
  version: FlowVersion | null;
  onClose: () => void;
  onFocusNode?: (nodeKey: string) => void;
  onActiveNodeChange?: (nodeKey: string | null, isError?: boolean) => void;
  onActiveEdgeChange?: (edge: { id?: string; source_node_key?: string; target_node_key?: string; condition_handle?: string | null } | null) => void;
}

export const FlowSimulatorDrawer: React.FC<FlowSimulatorDrawerProps> = (props) => {
  const { isOpen, onActiveNodeChange, onActiveEdgeChange } = props;

  useEffect(() => {
    if (!isOpen) {
      onActiveNodeChange?.(null);
      onActiveEdgeChange?.(null);
    }
  }, [isOpen, onActiveNodeChange, onActiveEdgeChange]);

  if (!isOpen) return null;
  return <FlowSimulatorDrawerContent {...props} />;
};

const SimulatorImageAttachment: React.FC<{
  url?: string | null;
  fileName?: string | null;
}> = ({ url, fileName }) => {
  const [isLoading, setIsLoading] = useState(true);
  const [hasError, setHasError] = useState(false);
  const resolvedUrl = resolveMediaUrl(url);

  if (!resolvedUrl || hasError) {
    return (
      <div
        style={{
          minHeight: '70px',
          minWidth: '150px',
          maxWidth: '100%',
          backgroundColor: '#f4f7f5',
          border: '1px dashed #d9e3de',
          borderRadius: '8px',
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          padding: '8px',
          gap: '4px',
          color: '#61726d',
          textAlign: 'center',
        }}
        data-testid="simulator-image-error"
      >
        <ImageOff size={18} color="#80918c" />
        <span style={{ fontSize: '11px', fontWeight: 500 }}>No se pudo cargar la imagen</span>
      </div>
    );
  }

  return (
    <div
      style={{
        position: 'relative',
        minHeight: isLoading ? '90px' : 'auto',
        minWidth: isLoading ? '150px' : 'auto',
        backgroundColor: isLoading ? '#eaf1ee' : 'transparent',
        borderRadius: '8px',
        overflow: 'hidden',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
      }}
    >
      {isLoading && (
        <div
          style={{
            position: 'absolute',
            inset: 0,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '6px',
            color: '#61726d',
            fontSize: '11px',
          }}
        >
          <Loader2 size={14} className="animate-spin" />
          <span>Cargando...</span>
        </div>
      )}
      <a
        href={resolvedUrl}
        target="_blank"
        rel="noopener noreferrer"
        style={{ display: 'block', width: '100%', borderRadius: '8px', overflow: 'hidden' }}
      >
        <img
          src={resolvedUrl}
          alt={fileName || 'Imagen adjunta'}
          onLoad={() => setIsLoading(false)}
          onError={() => {
            setIsLoading(false);
            setHasError(true);
          }}
          style={{
            maxWidth: '100%',
            maxHeight: '200px',
            objectFit: 'cover',
            borderRadius: '8px',
            display: isLoading ? 'none' : 'block',
            cursor: 'pointer',
            width: '100%',
          }}
        />
      </a>
    </div>
  );
};

const FlowSimulatorDrawerContent: React.FC<FlowSimulatorDrawerProps> = ({
  flow,
  version,
  onClose,
  onFocusNode,
  onActiveNodeChange,
  onActiveEdgeChange,
}) => {
  const [activeTab, setActiveTab] = useState<'chat' | 'inspector' | 'events'>('chat');
  const [simulation, setSimulation] = useState<SimulationState | null>(null);
  const [prevContext, setPrevContext] = useState<Record<string, any>>({});
  const [loading, setLoading] = useState(Boolean(flow?.id && version?.id));
  const [inputText, setInputText] = useState('');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Inyección de variables iniciales
  const [showInjectModal, setShowInjectModal] = useState(false);
  const [injectVarKey, setInjectVarKey] = useState('');
  const [injectVarValue, setInjectVarValue] = useState('');
  const [injectedVariables, setInjectedVariables] = useState<Record<string, any>>({});

  const messagesEndRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
      onActiveNodeChange?.(null);
      onActiveEdgeChange?.(null);
    };
  }, [onClose, onActiveNodeChange, onActiveEdgeChange]);

  // Notificar al canvas sobre cambios en el nodo/edge activo
  useEffect(() => {
    if (simulation) {
      const isFailed = simulation.status === 'failed';
      const nodeKey = simulation.current_node?.node_key || null;
      onActiveNodeChange?.(nodeKey, isFailed);
      onActiveEdgeChange?.(simulation.taken_edge || null);
    }
  }, [simulation, onActiveNodeChange, onActiveEdgeChange]);

  const startSimulation = useCallback(async (customInitialContext?: Record<string, any>) => {
    if (!flow || !version) return;
    setLoading(true);
    setErrorMessage(null);
    setPrevContext({});
    try {
      const ctx = customInitialContext || injectedVariables;
      const res = await api.startSimulation(flow.id, version.id, ctx);
      setSimulation(res.data);
    } catch (err: any) {
      setErrorMessage(err.message || 'Error al iniciar la simulación.');
    } finally {
      setLoading(false);
    }
  }, [flow, version, injectedVariables]);

  useEffect(() => {
    let ignore = false;
    if (flow?.id && version?.id) {
      api.startSimulation(flow.id, version.id, {})
        .then((res) => {
          if (!ignore) {
            setSimulation(res.data);
            setLoading(false);
          }
        })
        .catch((err: any) => {
          if (!ignore) {
            setErrorMessage(err.message || 'Error al iniciar la simulación.');
            setLoading(false);
          }
        });
    }
    return () => {
      ignore = true;
    };
  }, [flow?.id, version?.id]);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView?.({ behavior: 'smooth' });
  }, [simulation?.messages]);

  const handleSendMessage = async (textToSend?: string) => {
    const text = (textToSend !== undefined ? textToSend : inputText).trim();
    if (!text || !simulation || !flow || !version || loading) return;

    setLoading(true);
    setErrorMessage(null);
    setInputText('');

    // Guardar contexto previo para diff de variables
    if (simulation.context) {
      setPrevContext({ ...simulation.context });
    }

    try {
      const res = await api.stepSimulation(flow.id, version.id, simulation.execution_id, text);
      setSimulation(res.data);
    } catch (err: any) {
      setErrorMessage(err.message || 'Error al procesar el paso de simulación.');
    } finally {
      setLoading(false);
    }
  };

  const handleReset = async () => {
    if (!flow || !version) return;
    setLoading(true);
    setErrorMessage(null);
    setPrevContext({});
    try {
      const res = await api.resetSimulation(
        flow.id,
        version.id,
        simulation?.execution_id,
        injectedVariables
      );
      setSimulation(res.data);
    } catch (err: any) {
      setErrorMessage(err.message || 'Error al reiniciar la simulación.');
    } finally {
      setLoading(false);
    }
  };

  const handleStop = async () => {
    if (!flow || !version || !simulation?.execution_id) return;
    setLoading(true);
    setErrorMessage(null);
    try {
      const res = await api.stopSimulation(flow.id, version.id, simulation.execution_id);
      setSimulation(res.data);
    } catch (err: any) {
      setErrorMessage(err.message || 'Error al detener la simulación.');
    } finally {
      setLoading(false);
    }
  };

  const handleAddInjectedVariable = () => {
    if (!injectVarKey.trim()) return;
    let parsedVal: any = injectVarValue.trim();
    if (parsedVal.toLowerCase() === 'true') parsedVal = true;
    else if (parsedVal.toLowerCase() === 'false') parsedVal = false;
    else if (!isNaN(Number(parsedVal)) && parsedVal !== '') parsedVal = Number(parsedVal);

    setInjectedVariables((prev) => ({
      ...prev,
      [injectVarKey.trim()]: parsedVal,
    }));
    setInjectVarKey('');
    setInjectVarValue('');
  };

  const handleRemoveInjectedVariable = (key: string) => {
    setInjectedVariables((prev) => {
      const copy = { ...prev };
      delete copy[key];
      return copy;
    });
  };

  const isFinished = simulation?.status === 'completed' || simulation?.status === 'failed' || simulation?.status === 'cancelled';
  const isWaitingInput = simulation?.status === 'waiting_input';

  // Helper para inferir tipo de dato visual
  const inferVariableType = (val: any): { type: string; color: string } => {
    if (val === null || val === undefined) return { type: 'null', color: '#80918c' };
    if (typeof val === 'boolean') return { type: 'boolean', color: '#5d8f82' };
    if (typeof val === 'number') return { type: 'number', color: '#ea580c' };
    if (Array.isArray(val)) return { type: 'list', color: '#0284c7' };
    if (typeof val === 'object') return { type: 'json', color: '#5d8f82' };
    if (typeof val === 'string' && /^\d{4}-\d{2}-\d{2}/.test(val)) return { type: 'date', color: '#9333ea' };
    return { type: 'string', color: '#5d8f82' };
  };

  return (
    <div
      className="flow-simulator-drawer"
      style={{
        position: 'fixed',
        top: 0,
        right: 0,
        bottom: 0,
        width: '480px',
        maxWidth: '100vw',
        background: '#ffffff',
        boxShadow: '-4px 0 24px rgba(0, 0, 0, 0.16)',
        zIndex: 900,
        display: 'flex',
        flexDirection: 'column',
        borderLeft: '1px solid #d9e3de',
      }}
    >
      {/* Header */}
      <div
        style={{
          padding: '0.85rem 1rem',
          borderBottom: '1px solid #d9e3de',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          background: '#263c38',
          color: '#ffffff',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.65rem' }}>
          <div
            style={{
              width: '30px',
              height: '30px',
              borderRadius: '8px',
              background: '#5d8f82',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              boxShadow: '0 2px 8px rgba(37,99,235,0.4)',
            }}
          >
            <Play size={15} fill="#ffffff" color="#ffffff" />
          </div>
          <div>
            <div style={{ fontSize: '0.9rem', fontWeight: 700, display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
              Simulador de Flujo
              <span
                style={{
                  fontSize: '0.65rem',
                  padding: '0.1rem 0.4rem',
                  borderRadius: '999px',
                  background: version?.status === 'published' ? '#263c38' : '#854d0e',
                  color: '#ffffff',
                  fontWeight: 600,
                }}
              >
                {version?.status === 'published' ? 'Publicada' : 'Borrador'}
              </span>
            </div>
            <div style={{ fontSize: '0.72rem', color: '#80918c' }}>
              v{version?.version_number} • {flow?.name}
            </div>
          </div>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
          <button
            type="button"
            className="btn btn-sm"
            style={{
              padding: '0.25rem 0.5rem',
              color: '#d9e3de',
              background: '#35564f',
              border: '1px solid #56736b',
              display: 'flex',
              alignItems: 'center',
              gap: '0.25rem',
              fontSize: '0.72rem',
              borderRadius: '5px',
            }}
            onClick={handleReset}
            disabled={loading}
            title="Reiniciar simulación con contexto limpio"
          >
            <RotateCcw size={12} /> Reiniciar
          </button>
          {!isFinished && (
            <button
              type="button"
              className="btn btn-sm"
              style={{
                padding: '0.25rem 0.5rem',
                color: '#f87171',
                background: '#35564f',
                border: '1px solid #56736b',
                display: 'flex',
                alignItems: 'center',
                gap: '0.25rem',
                fontSize: '0.72rem',
                borderRadius: '5px',
              }}
              onClick={handleStop}
              disabled={loading}
              title="Detener ejecución"
            >
              <Square size={11} fill="#f87171" /> Detener
            </button>
          )}
          <button
            type="button"
            className="btn btn-sm"
            style={{ padding: '0.25rem', color: '#80918c', background: 'transparent', border: 'none', cursor: 'pointer' }}
            onClick={onClose}
            title="Cerrar simulador"
          >
            <X size={18} />
          </button>
        </div>
      </div>

      {/* Navegación por Pestañas */}
      <div style={{ display: 'flex', borderBottom: '1px solid #d9e3de', background: '#f4f7f5' }}>
        <button
          type="button"
          onClick={() => setActiveTab('chat')}
          style={{
            flex: 1,
            padding: '0.55rem',
            border: 'none',
            background: activeTab === 'chat' ? '#ffffff' : 'transparent',
            fontWeight: activeTab === 'chat' ? 700 : 500,
            fontSize: '0.78rem',
            color: activeTab === 'chat' ? '#5d8f82' : '#61726d',
            cursor: 'pointer',
            borderBottom: activeTab === 'chat' ? '2px solid #5d8f82' : 'none',
          }}
        >
          Conversación
        </button>
        <button
          type="button"
          onClick={() => setActiveTab('inspector')}
          style={{
            flex: 1,
            padding: '0.55rem',
            border: 'none',
            background: activeTab === 'inspector' ? '#ffffff' : 'transparent',
            fontWeight: activeTab === 'inspector' ? 700 : 500,
            fontSize: '0.78rem',
            color: activeTab === 'inspector' ? '#5d8f82' : '#61726d',
            cursor: 'pointer',
            borderBottom: activeTab === 'inspector' ? '2px solid #5d8f82' : 'none',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '0.35rem',
          }}
        >
          <Activity size={13} color="#456c61" />
          Inspector ({Object.keys(simulation?.context || {}).length} vars)
        </button>
        <button
          type="button"
          onClick={() => setActiveTab('events')}
          style={{
            flex: 1,
            padding: '0.55rem',
            border: 'none',
            background: activeTab === 'events' ? '#ffffff' : 'transparent',
            fontWeight: activeTab === 'events' ? 700 : 500,
            fontSize: '0.78rem',
            color: activeTab === 'events' ? '#5d8f82' : '#61726d',
            cursor: 'pointer',
            borderBottom: activeTab === 'events' ? '2px solid #5d8f82' : 'none',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '0.35rem',
          }}
        >
          <Layers size={13} color="#5d8f82" />
          Eventos ({simulation?.steps?.length || 0})
        </button>
      </div>

      {/* Alerta de Error */}
      {errorMessage && (
        <div
          style={{
            padding: '0.65rem 0.85rem',
            background: '#fee2e2',
            color: '#991b1b',
            fontSize: '0.76rem',
            display: 'flex',
            alignItems: 'center',
            gap: '0.45rem',
            borderBottom: '1px solid #fecaca',
          }}
        >
          <AlertCircle size={15} color="#dc2626" />
          <span>{errorMessage}</span>
        </div>
      )}

      {/* ========================================================================= */}
      {/* PESTAÑA 1: CHAT CONVERSACIONAL                                           */}
      {/* ========================================================================= */}
      {activeTab === 'chat' && (
        <div style={{ flex: 1, display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
          {/* Historial de Mensajes */}
          <div
            style={{
              flex: 1,
              overflowY: 'auto',
              padding: '1rem',
              background: '#eaf1ee',
              display: 'flex',
              flexDirection: 'column',
              gap: '0.85rem',
            }}
          >
            {(!simulation?.messages || simulation.messages.length === 0) && (
              <div style={{ textAlign: 'center', padding: '2.5rem 1rem', color: '#61726d', fontSize: '0.8rem' }}>
                {loading ? 'Iniciando simulación del flujo...' : 'No hay mensajes generados en esta sesión.'}
              </div>
            )}

            {simulation?.messages?.map((msg, idx) => {
              const isBot = msg.sender_type === 'bot' || msg.direction === 'outbound';
              const choices = (msg.metadata?.choices as string[]) || [];
              const isSimulatedTransfer = Boolean(msg.metadata?.is_simulated_transfer);

              return (
                <div
                  key={msg.id || idx}
                  style={{
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: isBot ? 'flex-start' : 'flex-end',
                    width: '100%',
                  }}
                >
                  {/* Banner especial para Transferencia Simulada */}
                  {isSimulatedTransfer && (
                    <div
                      style={{
                        maxWidth: '90%',
                        padding: '0.65rem 0.85rem',
                        borderRadius: '8px',
                        background: '#fef3c7',
                        border: '1px solid #fde68a',
                        color: '#92400e',
                        marginBottom: '0.35rem',
                        fontSize: '0.75rem',
                        display: 'flex',
                        alignItems: 'flex-start',
                        gap: '0.5rem',
                      }}
                    >
                      <UserCheck size={16} color="#b45309" style={{ flexShrink: 0, marginTop: '2px' }} />
                      <div>
                        <div style={{ fontWeight: 700 }}>Transferencia Simulada a Agente</div>
                        <div style={{ fontSize: '0.72rem', color: '#78350f', marginTop: '2px' }}>
                          En un entorno real, esta conversación pasaría a la bandeja de atención humana del Inbox. En el simulador, se ejecuta de forma segura sin generar tickets productivos.
                        </div>
                      </div>
                    </div>
                  )}

                  {/* Burbuja de mensaje */}
                  <div
                    style={{
                      maxWidth: '85%',
                      padding: '0.65rem 0.9rem',
                      borderRadius: isBot ? '14px 14px 14px 3px' : '14px 14px 3px 14px',
                      background: isBot ? '#ffffff' : '#5d8f82',
                      color: isBot ? '#263c38' : '#ffffff',
                      fontSize: '0.82rem',
                      lineHeight: '1.45',
                      boxShadow: '0 1px 3px rgba(0,0,0,0.06)',
                      wordBreak: 'break-word',
                      border: isBot ? '1px solid #d9e3de' : 'none',
                    }}
                  >
                    {(msg.message_type === 'image' || (msg.payload as any)?.attachment?.mime_type?.startsWith('image/')) && (
                      <div style={{ marginBottom: msg.content ? '0.5rem' : '0' }}>
                        <SimulatorImageAttachment
                          url={(msg.payload as any)?.attachment?.url || (msg as any).metadata?.attachment?.url}
                          fileName={(msg.payload as any)?.attachment?.file_name || (msg as any).metadata?.attachment?.file_name}
                        />
                      </div>
                    )}
                    {(msg.message_type === 'file' ||
                      (!((msg.payload as any)?.attachment?.mime_type?.startsWith('image/')) &&
                        Boolean((msg.payload as any)?.attachment?.url || (msg as any).metadata?.attachment?.url))) && (
                      <div
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          gap: '0.5rem',
                          padding: '0.45rem 0.6rem',
                          background: isBot ? '#eaf1ee' : 'rgba(255,255,255,0.2)',
                          borderRadius: '6px',
                          marginBottom: msg.content ? '0.5rem' : '0',
                        }}
                      >
                        <FileText size={18} color={isBot ? '#5d8f82' : '#ffffff'} />
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div
                            style={{
                              fontSize: '0.78rem',
                              fontWeight: 600,
                              overflow: 'hidden',
                              textOverflow: 'ellipsis',
                              whiteSpace: 'nowrap',
                              color: isBot ? '#263c38' : '#ffffff',
                            }}
                          >
                            {(msg.payload as any)?.attachment?.file_name || (msg as any).metadata?.attachment?.file_name || 'Documento'}
                          </div>
                        </div>
                        {((msg.payload as any)?.attachment?.url || (msg as any).metadata?.attachment?.url) && (
                          <a
                            href={resolveMediaUrl((msg.payload as any)?.attachment?.url || (msg as any).metadata?.attachment?.url)}
                            target="_blank"
                            rel="noopener noreferrer"
                            style={{
                              fontSize: '0.7rem',
                              color: isBot ? '#5d8f82' : '#ffffff',
                              textDecoration: 'underline',
                              flexShrink: 0,
                            }}
                          >
                            Ver
                          </a>
                        )}
                      </div>
                    )}
                    {msg.content && (
                      <LinkifiedText
                        text={msg.content}
                        linkClassName={
                          isBot
                            ? 'text-blue-600 dark:text-blue-400 underline hover:text-blue-800 break-words inline-block'
                            : 'text-white underline hover:opacity-80 break-words inline-block'
                        }
                      />
                    )}
                  </div>

                  {/* Opciones interactivas de menú o botones */}
                  {isBot && choices.length > 0 && (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.4rem', marginTop: '0.45rem', maxWidth: '85%' }}>
                      {choices.map((choiceText, cIdx) => (
                        <button
                          key={cIdx}
                          type="button"
                          disabled={loading || !isWaitingInput}
                          onClick={() => handleSendMessage(choiceText)}
                          style={{
                            background: '#eaf1ee',
                            color: '#456c61',
                            border: '1px solid #a9c8bb',
                            borderRadius: '6px',
                            padding: '0.35rem 0.65rem',
                            fontSize: '0.76rem',
                            fontWeight: 600,
                            cursor: isWaitingInput ? 'pointer' : 'not-allowed',
                            opacity: isWaitingInput ? 1 : 0.6,
                            transition: 'all 0.15s ease',
                            display: 'flex',
                            alignItems: 'center',
                            gap: '0.3rem',
                          }}
                        >
                          <CornerDownRight size={11} /> {choiceText}
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              );
            })}

            <div ref={messagesEndRef} />
          </div>

          {/* Barra de Estado inferior */}
          <div
            style={{
              padding: '0.45rem 0.85rem',
              background: '#f4f7f5',
              borderTop: '1px solid #d9e3de',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              fontSize: '0.72rem',
              color: '#61726d',
            }}
          >
            <div>
              Estado:{' '}
              <strong
                style={{
                  color:
                    simulation?.status === 'completed'
                      ? '#5d8f82'
                      : simulation?.status === 'waiting_input'
                      ? '#d97706'
                      : simulation?.status === 'failed'
                      ? '#dc2626'
                      : simulation?.status === 'cancelled'
                      ? '#61726d'
                      : '#5d8f82',
                }}
              >
                {simulation?.status?.toUpperCase() || 'EN PAUSA'}
              </strong>
            </div>

            {simulation?.current_node && (
              <div
                style={{ cursor: onFocusNode ? 'pointer' : 'default', color: '#5d8f82', display: 'flex', alignItems: 'center', gap: '0.25rem' }}
                onClick={() => onFocusNode && simulation.current_node && onFocusNode(simulation.current_node.node_key)}
                title="Centrar nodo en el lienzo"
              >
                <Maximize2 size={11} />
                Nodo actual: <strong>{simulation.current_node.name}</strong>
              </div>
            )}
          </div>

          {/* Input de respuesta del chat */}
          <form
            onSubmit={(e) => {
              e.preventDefault();
              handleSendMessage();
            }}
            style={{
              padding: '0.75rem',
              borderTop: '1px solid #d9e3de',
              background: '#ffffff',
              display: 'flex',
              gap: '0.5rem',
            }}
          >
            <input
              type="text"
              className="form-control"
              placeholder={
                isFinished
                  ? 'El flujo ha finalizado. Reinicia para probar nuevamente.'
                  : isWaitingInput
                  ? 'Escribe tu respuesta o selección...'
                  : 'Procesando paso...'
              }
              disabled={loading || !isWaitingInput}
              value={inputText}
              onChange={(e) => setInputText(e.target.value)}
              style={{ fontSize: '0.82rem' }}
            />
            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading || !isWaitingInput || !inputText.trim()}
              style={{ padding: '0.4rem 0.75rem' }}
            >
              <Send size={15} />
            </button>
          </form>
        </div>
      )}

      {/* ========================================================================= */}
      {/* PESTAÑA 2: INSPECTOR (NODO ACTUAL & VARIABLES)                            */}
      {/* ========================================================================= */}
      {activeTab === 'inspector' && (
        <div style={{ flex: 1, overflowY: 'auto', padding: '1rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          {/* SECCIÓN A: NODO ACTUAL */}
          <div style={{ background: '#f4f7f5', border: '1px solid #d9e3de', borderRadius: '8px', padding: '0.85rem' }}>
            <div style={{ fontSize: '0.72rem', fontWeight: 700, color: '#61726d', textTransform: 'uppercase', marginBottom: '0.5rem' }}>
              A. Nodo Actual en Ejecución
            </div>
            {simulation?.current_node ? (
              <div>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.4rem' }}>
                  <div style={{ fontWeight: 700, fontSize: '0.9rem', color: '#263c38' }}>
                    {simulation.current_node.name}
                  </div>
                  {onFocusNode && (
                    <button
                      type="button"
                      className="btn btn-secondary btn-sm"
                      style={{ fontSize: '0.7rem', padding: '0.2rem 0.5rem' }}
                      onClick={() => onFocusNode(simulation.current_node!.node_key)}
                    >
                      Centrar en lienzo
                    </button>
                  )}
                </div>
                <div style={{ display: 'flex', gap: '0.4rem', flexWrap: 'wrap', marginBottom: '0.5rem' }}>
                  <span style={{ padding: '0.15rem 0.4rem', borderRadius: '4px', background: '#eaf1ee', color: '#263c38', fontSize: '0.7rem', fontWeight: 600 }}>
                    key: {simulation.current_node.node_key}
                  </span>
                  <span style={{ padding: '0.15rem 0.4rem', borderRadius: '4px', background: '#ddeae4', color: '#456c61', fontSize: '0.7rem', fontWeight: 600 }}>
                    tipo: {simulation.current_node.type}
                  </span>
                </div>
                {simulation.current_node.configuration && Object.keys(simulation.current_node.configuration).length > 0 && (
                  <div style={{ background: '#ffffff', border: '1px solid #d9e3de', borderRadius: '6px', padding: '0.5rem', fontSize: '0.72rem', color: '#56736b' }}>
                    <div style={{ fontWeight: 600, color: '#61726d', marginBottom: '0.25rem' }}>Configuración del bloque:</div>
                    <pre style={{ margin: 0, fontFamily: 'monospace', fontSize: '0.68rem', whiteSpace: 'pre-wrap', maxHeight: '120px', overflowY: 'auto' }}>
                      {JSON.stringify(simulation.current_node.configuration, null, 2)}
                    </pre>
                  </div>
                )}
              </div>
            ) : (
              <div style={{ fontSize: '0.78rem', color: '#61726d' }}>
                {simulation?.status === 'completed' ? 'Flujo finalizado correctamente (nodo End).' : 'No hay ningún nodo activo.'}
              </div>
            )}
          </div>

          {/* SECCIÓN B: VARIABLES EN MEMORIA */}
          <div style={{ background: '#ffffff', border: '1px solid #d9e3de', borderRadius: '8px', overflow: 'hidden' }}>
            <div
              style={{
                padding: '0.6rem 0.85rem',
                background: '#f4f7f5',
                borderBottom: '1px solid #d9e3de',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.78rem', fontWeight: 700, color: '#56736b' }}>
                <Variable size={15} color="#456c61" />
                B. Variables en Memoria ({Object.keys(simulation?.context || {}).length})
              </div>
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                style={{ fontSize: '0.68rem', padding: '0.2rem 0.45rem', display: 'flex', alignItems: 'center', gap: '0.25rem' }}
                onClick={() => setShowInjectModal(!showInjectModal)}
              >
                <Plus size={11} /> {showInjectModal ? 'Ocultar' : 'Inyectar variable'}
              </button>
            </div>

            {/* Modal/Formulario de inyección de variables */}
            {showInjectModal && (
              <div style={{ padding: '0.75rem', background: '#eaf1ee', borderBottom: '1px solid #d9e3de' }}>
                <div style={{ fontSize: '0.72rem', fontWeight: 600, color: '#61726d', marginBottom: '0.35rem' }}>
                  Inyectar valor previo para probar condiciones o mensajes:
                </div>
                <div style={{ display: 'flex', gap: '0.35rem', marginBottom: '0.4rem' }}>
                  <input
                    type="text"
                    placeholder="variable (ej. edad)"
                    value={injectVarKey}
                    onChange={(e) => setInjectVarKey(e.target.value)}
                    style={{ flex: 1, padding: '0.3rem 0.5rem', fontSize: '0.72rem', borderRadius: '4px', border: '1px solid #d9e3de' }}
                  />
                  <input
                    type="text"
                    placeholder="valor (ej. 25)"
                    value={injectVarValue}
                    onChange={(e) => setInjectVarValue(e.target.value)}
                    style={{ flex: 1, padding: '0.3rem 0.5rem', fontSize: '0.72rem', borderRadius: '4px', border: '1px solid #d9e3de' }}
                  />
                  <button
                    type="button"
                    className="btn btn-primary btn-sm"
                    onClick={handleAddInjectedVariable}
                    style={{ fontSize: '0.72rem', padding: '0.3rem 0.6rem' }}
                  >
                    Agregar
                  </button>
                </div>
                {Object.keys(injectedVariables).length > 0 && (
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.3rem', marginTop: '0.4rem' }}>
                    {Object.entries(injectedVariables).map(([k, v]) => (
                      <span
                        key={k}
                        style={{
                          background: '#ffffff',
                          border: '1px solid #d9e3de',
                          borderRadius: '4px',
                          padding: '0.15rem 0.35rem',
                          fontSize: '0.68rem',
                          display: 'flex',
                          alignItems: 'center',
                          gap: '0.3rem',
                        }}
                      >
                        <strong>{k}:</strong> {String(v)}
                        <Trash2 size={10} color="#dc2626" style={{ cursor: 'pointer' }} onClick={() => handleRemoveInjectedVariable(k)} />
                      </span>
                    ))}
                    <button
                      type="button"
                      className="btn btn-sm btn-secondary"
                      style={{ fontSize: '0.65rem', padding: '0.1rem 0.4rem' }}
                      onClick={() => startSimulation(injectedVariables)}
                    >
                      Reiniciar con estas variables
                    </button>
                  </div>
                )}
              </div>
            )}

            {Object.keys(simulation?.context || {}).length === 0 ? (
              <div style={{ padding: '1rem', fontSize: '0.78rem', color: '#80918c', textAlign: 'center' }}>
                No hay variables en el contexto actualmente. Se crearán conforme los nodos (preguntas, menús, set_variable) las almacenen.
              </div>
            ) : (
              <div style={{ maxHeight: '240px', overflowY: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.75rem' }}>
                  <thead>
                    <tr style={{ background: '#f4f7f5', borderBottom: '1px solid #d9e3de', color: '#61726d', fontSize: '0.7rem', textAlign: 'left' }}>
                      <th style={{ padding: '0.4rem 0.75rem' }}>VARIABLE</th>
                      <th style={{ padding: '0.4rem 0.5rem' }}>TIPO</th>
                      <th style={{ padding: '0.4rem 0.75rem' }}>VALOR</th>
                    </tr>
                  </thead>
                  <tbody>
                    {Object.entries(simulation?.context || {}).map(([key, val]) => {
                      const { type, color } = inferVariableType(val);
                      const isChanged = prevContext[key] !== undefined && prevContext[key] !== val;
                      const isNew = prevContext[key] === undefined && Object.keys(prevContext).length > 0;

                      return (
                        <tr key={key} style={{ borderBottom: '1px solid #eaf1ee', background: isChanged || isNew ? '#eaf1ee' : 'transparent' }}>
                          <td style={{ padding: '0.45rem 0.75rem', fontFamily: 'monospace', fontWeight: 600, color: '#312e81' }}>
                            {`{{${key}}}`}
                            {(isChanged || isNew) && (
                              <span style={{ marginLeft: '0.35rem', fontSize: '0.65rem', color: '#5d8f82', fontWeight: 700 }}>
                                ● actualizado
                              </span>
                            )}
                          </td>
                          <td style={{ padding: '0.45rem 0.5rem' }}>
                            <span style={{ fontSize: '0.68rem', fontWeight: 600, color, background: `${color}15`, padding: '0.1rem 0.35rem', borderRadius: '4px' }}>
                              {type}
                            </span>
                          </td>
                          <td style={{ padding: '0.45rem 0.75rem', color: '#263c38', wordBreak: 'break-all' }}>
                            {typeof val === 'object' ? JSON.stringify(val) : String(val)}
                            {isChanged && (
                              <div style={{ fontSize: '0.65rem', color: '#61726d' }}>
                                (anterior: {JSON.stringify(prevContext[key])})
                              </div>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* PESTAÑA 3: HISTORIAL DE EVENTOS Y TRAZABILIDAD (SECCIÓN C)                */}
      {/* ========================================================================= */}
      {activeTab === 'events' && (
        <div style={{ flex: 1, overflowY: 'auto', padding: '1rem', display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
          <div style={{ fontSize: '0.72rem', fontWeight: 700, color: '#61726d', textTransform: 'uppercase' }}>
            C. Historial Cronológico de Eventos ({simulation?.events?.length || simulation?.steps?.length || 0})
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
            {simulation?.events?.map((ev: SimulationEvent, idx: number) => {
              const isSuccess = ev.status === 'success';
              const isWaiting = ev.status === 'waiting';
              const isError = ev.status === 'error';

              return (
                <div
                  key={idx}
                  style={{
                    padding: '0.6rem 0.75rem',
                    borderRadius: '7px',
                    background: '#ffffff',
                    border: `1px solid ${isError ? '#fecaca' : isWaiting ? '#fef3c7' : '#d9e3de'}`,
                    borderLeft: `3px solid ${isError ? '#dc2626' : isWaiting ? '#d97706' : '#5d8f82'}`,
                    fontSize: '0.75rem',
                    boxShadow: '0 1px 2px rgba(0,0,0,0.03)',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.25rem' }}>
                    <div style={{ fontWeight: 700, color: '#263c38', display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                      <span style={{ fontSize: '0.68rem', color: '#61726d' }}>#{ev.order}</span>
                      {ev.label}
                    </div>
                    <span
                      style={{
                        padding: '0.1rem 0.35rem',
                        borderRadius: '4px',
                        fontSize: '0.65rem',
                        fontWeight: 600,
                        background: isSuccess ? '#eaf1ee' : isWaiting ? '#fef3c7' : '#fee2e2',
                        color: isSuccess ? '#263c38' : isWaiting ? '#b45309' : '#b91c1c',
                      }}
                    >
                      {ev.status.toUpperCase()}
                    </span>
                  </div>

                  {ev.details && (
                    <div style={{ color: '#61726d', fontSize: '0.7rem', marginBottom: '0.25rem', fontFamily: 'monospace' }}>
                      {ev.details}
                    </div>
                  )}

                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: '0.68rem', color: '#80918c' }}>
                    {ev.node_key && (
                      <span
                        style={{ cursor: onFocusNode ? 'pointer' : 'default', color: '#5d8f82' }}
                        onClick={() => onFocusNode && ev.node_key && onFocusNode(ev.node_key)}
                      >
                        Nodo: {ev.node_key}
                      </span>
                    )}
                    {ev.timestamp && <span>{new Date(ev.timestamp).toLocaleTimeString()}</span>}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
};
