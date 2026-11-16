import React, { useState, useEffect, useCallback, useRef, useMemo, useContext } from 'react';
import {
  type Node,
  type Edge,
  type Connection,
  type NodeChange,
  type EdgeChange,
  applyNodeChanges,
  applyEdgeChanges,
  addEdge,
  type ReactFlowInstance,
  ReactFlowProvider,
} from '@xyflow/react';
import { api } from '../../services/api';
import type { Flow, FlowVersion, FlowNodeType, FlowValidationError, FlowVariable, FlowPublication } from '../../types';
import { FlowVersionHistoryDrawer } from './FlowVersionHistoryDrawer';
import { FlowVersionCompareModal } from './FlowVersionCompareModal';
import { FlowRollbackModal } from './FlowRollbackModal';
import {
  toReactFlowNodes,
  toReactFlowEdges,
  toBackendGraph,
  type EditorNodeData,
} from './adapter';
import { FlowToolbar } from './FlowToolbar';
import { NodePalette } from './NodePalette';
import { FlowCanvas } from './FlowCanvas';
import { PropertiesPanel } from './PropertiesPanel';
import { ValidationPanel } from './ValidationPanel';
import { FlowSearchModal } from './FlowSearchModal';
import { ContextualNodeMenu, type ContextualInsertionTarget } from './ContextualNodeMenu';
import { VariableManagerModal } from './VariableManagerModal';
import { FlowSimulatorDrawer } from './FlowSimulatorDrawer';
import { FlowPublishModal } from './FlowPublishModal';
import { FlowCreateDraftModal } from './FlowCreateDraftModal';
import { calculateAutoLayout, hasValidPositions } from './layoutEngine';
import { computeVisibleGraph } from './branchVisibility';
import { ConfirmModal } from '../common/ConfirmModal';
import { AuthContext } from '../../context/authContextDef';
import { useToast } from '../../context/ToastContext';
import './flow-editor.css';
import { PanelRightOpen, Lock, ShieldAlert } from 'lucide-react';

interface FlowEditorProps {
  flowId: string;
  initialVersionId?: string;
  onBack: () => void;
}

interface HistoryState {
  nodes: Node<EditorNodeData>[];
  edges: Edge[];
}

const InnerFlowEditor: React.FC<FlowEditorProps> = ({
  flowId,
  initialVersionId,
  onBack,
}) => {
  const auth = useContext(AuthContext);
  const { showToast } = useToast();
  const hasEditPermission = auth ? (auth.hasPermission ? auth.hasPermission('flows.edit') : true) : true;

  const [loading, setLoading] = useState(true);
  const [flow, setFlow] = useState<Flow | null>(null);
  const [currentVersion, setCurrentVersion] = useState<FlowVersion | null>(null);
  const [versions, setVersions] = useState<FlowVersion[]>([]);

  // Underlying complete graph state
  const [nodes, setNodes] = useState<Node<EditorNodeData>[]>([]);
  const [edges, setEdges] = useState<Edge[]>([]);
  const [selectedNodeId, setSelectedNodeId] = useState<string | null>(null);

  // Editor states
  const [isDirty, setIsDirty] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isValidating, setIsValidating] = useState(false);
  const [isPublishing, setIsPublishing] = useState(false);
  const [isAutoOrganizing, setIsAutoOrganizing] = useState(false);

  // Visual navigation & views state
  const [collapsedNodeKeys, setCollapsedNodeKeys] = useState<Set<string>>(new Set());
  const [focusedNodeKey, setFocusedNodeKey] = useState<string | null>(null);
  const [highlightConnections, setHighlightConnections] = useState(true);
  const [showMiniMap, setShowMiniMap] = useState<boolean>(() => {
    try {
      const saved = localStorage.getItem('conexa_minimap_visible');
      return saved !== null ? saved === 'true' : true;
    } catch {
      return true;
    }
  });

  // Search state
  const [isSearchOpen, setIsSearchOpen] = useState(false);
  const [highlightedNodeId, setHighlightedNodeId] = useState<string | null>(null);

  const [validationErrors, setValidationErrors] = useState<FlowValidationError[]>([]);
  const [isValid, setIsValid] = useState<boolean | null>(null);
  const [expectedUpdatedAt, setExpectedUpdatedAt] = useState<string | null>(null);

  const [showPublishModal, setShowPublishModal] = useState(false);
  const [publishNotes, setPublishNotes] = useState('');
  const [showCreateDraftModal, setShowCreateDraftModal] = useState(false);
  const [cloneSourceVersion, setCloneSourceVersion] = useState<FlowVersion | null>(null);
  const [isCreatingDraft, setIsCreatingDraft] = useState(false);
  const [canUndo, setCanUndo] = useState(false);
  const [canRedo, setCanRedo] = useState(false);

  // Versioning, History, Diff & Rollback states (Fase 3)
  const [isHistoryDrawerOpen, setIsHistoryDrawerOpen] = useState(false);
  const [isCompareModalOpen, setIsCompareModalOpen] = useState(false);
  const [compareSourceId, setCompareSourceId] = useState<string | undefined>();
  const [compareTargetId, setCompareTargetId] = useState<string | undefined>();
  const [isRollbackModalOpen, setIsRollbackModalOpen] = useState(false);
  const [rollbackTargetVersion, setRollbackTargetVersion] = useState<FlowVersion | null>(null);
  const [publications, setPublications] = useState<FlowPublication[]>([]);

  // V3 Contextual menu, Variable manager and Simulator states
  const [isVariableManagerOpen, setIsVariableManagerOpen] = useState(false);
  const [isSimulatorOpen, setIsSimulatorOpen] = useState(false);

  // Collapsible properties panel & Zen Mode states
  const [isPropertiesCollapsed, setIsPropertiesCollapsed] = useState<boolean>(() => {
    try {
      return localStorage.getItem('conexa_flow_properties_collapsed') === 'true';
    } catch {
      return false;
    }
  });
  const [isZenMode, setIsZenMode] = useState<boolean>(false);

  // Styled Confirm & Toast states
  const [confirmDialog, setConfirmDialog] = useState<{
    isOpen: boolean;
    title: string;
    description: string;
    confirmText?: string;
    cancelText?: string;
    confirmVariant?: 'primary' | 'danger';
    requiredText?: string;
    onConfirm: () => void;
  } | null>(null);

  const handleToggleProperties = useCallback(() => {
    setIsPropertiesCollapsed((prev) => {
      const next = !prev;
      try {
        localStorage.setItem('conexa_flow_properties_collapsed', String(next));
      } catch {
        // ignore
      }
      return next;
    });
  }, []);

  const handleToggleZenMode = useCallback(() => {
    setIsZenMode((prev) => !prev);
  }, []);

  const handleDeselectNode = useCallback(() => {
    setSelectedNodeId(null);
    setNodes((nds) => nds.map((n) => (n.selected ? { ...n, selected: false } : n)));
  }, []);

  useEffect(() => {
    if (isZenMode) {
      document.body.classList.add('zen-mode');
    } else {
      document.body.classList.remove('zen-mode');
    }
    return () => {
      document.body.classList.remove('zen-mode');
    };
  }, [isZenMode]);

  const [simulatingNodeKey, setSimulatingNodeKey] = useState<string | null>(null);
  const [simulatingNodeError, setSimulatingNodeError] = useState(false);
  const [simulatingEdge, setSimulatingEdge] = useState<{ id?: string; source_node_key?: string; target_node_key?: string; condition_handle?: string | null } | null>(null);
  const [contextualTarget, setContextualTarget] = useState<ContextualInsertionTarget | null>(null);
  const [isContextualMenuOpen, setIsContextualMenuOpen] = useState(false);
  const [isPaletteOpen, setIsPaletteOpen] = useState(false);
  const [flowVariables, setFlowVariables] = useState<FlowVariable[]>([]);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && isPaletteOpen) {
        setIsPaletteOpen(false);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isPaletteOpen]);

  // Undo / Redo history
  const undoStack = useRef<HistoryState[]>([]);
  const redoStack = useRef<HistoryState[]>([]);
  const reactFlowInstance = useRef<ReactFlowInstance<Node<EditorNodeData>, Edge> | null>(null);
  const layoutToken = useRef(0);

  const isPublished = currentVersion?.status === 'published';
  const isReadOnly = isPublished || !hasEditPermission;

  // Toggle minimap and save preference to localStorage
  const handleToggleMiniMap = useCallback(() => {
    setShowMiniMap((prev) => {
      const next = !prev;
      try {
        localStorage.setItem('conexa_minimap_visible', String(next));
      } catch {
        // ignore storage errors
      }
      return next;
    });
  }, []);

  // Push snapshot to undo stack
  const pushHistory = useCallback((newNodes: Node<EditorNodeData>[], newEdges: Edge[]) => {
    undoStack.current.push({
      nodes: JSON.parse(JSON.stringify(newNodes)),
      edges: JSON.parse(JSON.stringify(newEdges)),
    });
    if (undoStack.current.length > 30) {
      undoStack.current.shift();
    }
    redoStack.current = [];
    setCanUndo(true);
    setCanRedo(false);
  }, []);

  // Fetch Version data on user action (version change, clone, etc.)
  const loadSpecificVersion = useCallback(async (versionIdToLoad: string) => {
    setLoading(true);
    try {
      const flowRes = await api.getFlow(flowId);
      setFlow(flowRes.data);
      setVersions(flowRes.data.versions || []);
      setPublications(flowRes.data.publications || []);

      const verRes = await api.getFlowVersion(flowId, versionIdToLoad);
      const verData = verRes.data;
      setCurrentVersion(verData);
      setExpectedUpdatedAt(verData.updated_at || null);

      const readOnly = verData.status === 'published' || !hasEditPermission;
      const rfNodes = toReactFlowNodes(verData.nodes || [], new Set(), readOnly);
      const rfEdges = toReactFlowEdges(verData.edges || [], readOnly, verData.nodes);

      let finalNodes = rfNodes;
      if (!hasValidPositions(rfNodes) && rfNodes.length > 1) {
        try {
          finalNodes = await calculateAutoLayout(rfNodes, rfEdges);
        } catch (e) {
          console.error('Auto-layout failed on version load:', e);
        }
      }

      setNodes(finalNodes);
      setEdges(rfEdges);
      const initialVars: FlowVariable[] = (verData.metadata?.variables && Array.isArray(verData.metadata.variables))
        ? verData.metadata.variables
        : [
            { name: 'user_name', type: 'text', scope: 'contact', description: 'Nombre del contacto' },
            { name: 'user_phone', type: 'text', scope: 'contact', description: 'Número de teléfono' },
            { name: 'user_email', type: 'text', scope: 'contact', description: 'Correo electrónico' },
            { name: 'last_input', type: 'text', scope: 'temporary', description: 'Último texto ingresado' },
          ];
      setFlowVariables(initialVars);
      setSelectedNodeId(null);
      setCollapsedNodeKeys(new Set());
      setFocusedNodeKey(null);
      setIsDirty(false);
      setIsValid(null);
      setValidationErrors([]);
      undoStack.current = [];
      redoStack.current = [];
      setCanUndo(false);
      setCanRedo(false);

      setTimeout(() => {
        reactFlowInstance.current?.fitView({ padding: 0.2, duration: 400 });
      }, 100);
    } catch (err: any) {
      showToast(err.message || 'Error al cargar versión del flujo.', 'error');
    } finally {
      setLoading(false);
    }
  }, [flowId, hasEditPermission, showToast]);

  // Initial load
  useEffect(() => {
    let isCancelled = false;
    const initFlow = async () => {
      try {
        const flowRes = await api.getFlow(flowId);
        if (isCancelled) return;
        const flowData = flowRes.data;
        setFlow(flowData);
        setVersions(flowData.versions || []);
        setPublications(flowData.publications || []);

        let targetVersionId = initialVersionId;
        if (!targetVersionId) {
          const draftVer = flowData.versions?.find((v: any) => v.status === 'draft');
          targetVersionId = draftVer ? draftVer.id : (flowData.active_version_id || flowData.versions?.[0]?.id);
        }

        if (targetVersionId) {
          const verRes = await api.getFlowVersion(flowId, targetVersionId);
          if (isCancelled) return;
          const verData = verRes.data;
          setCurrentVersion(verData);
          setExpectedUpdatedAt(verData.updated_at || null);

          const readOnly = verData.status === 'published' || !hasEditPermission;
          const rfNodes = toReactFlowNodes(verData.nodes || [], new Set(), readOnly);
          const rfEdges = toReactFlowEdges(verData.edges || [], readOnly, verData.nodes);

          let finalNodes = rfNodes;
          // Calculate initial organization if flow opens without valid positions
          if (!hasValidPositions(rfNodes) && rfNodes.length > 1) {
            try {
              finalNodes = await calculateAutoLayout(rfNodes, rfEdges);
            } catch (e) {
              console.error('Initial auto-layout error:', e);
            }
          }

          setNodes(finalNodes);
          setEdges(rfEdges);
          const initialVars: FlowVariable[] = (verData.metadata?.variables && Array.isArray(verData.metadata.variables))
            ? verData.metadata.variables
            : [
                { name: 'user_name', type: 'text', scope: 'contact', description: 'Nombre del contacto' },
                { name: 'user_phone', type: 'text', scope: 'contact', description: 'Número de teléfono' },
                { name: 'user_email', type: 'text', scope: 'contact', description: 'Correo electrónico' },
                { name: 'last_input', type: 'text', scope: 'temporary', description: 'Último texto ingresado' },
              ];
          setFlowVariables(initialVars);
          setSelectedNodeId(null);
          setCollapsedNodeKeys(new Set());
          setFocusedNodeKey(null);
          setIsDirty(false);
          setIsValid(null);
          setValidationErrors([]);
          undoStack.current = [];
          redoStack.current = [];
          setCanUndo(false);
          setCanRedo(false);

          setTimeout(() => {
            reactFlowInstance.current?.fitView({ padding: 0.2, duration: 400 });
          }, 100);
        }
      } catch (err: any) {
        if (!isCancelled) showToast(err.message || 'Error al cargar el flujo.', 'error');
      } finally {
        if (!isCancelled) setLoading(false);
      }
    };

    initFlow();
    return () => {
      isCancelled = true;
    };
  }, [flowId, initialVersionId, hasEditPermission, showToast]);

  // Warn on page unload if changes are unsaved
  useEffect(() => {
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      if (isDirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, [isDirty]);

  // Save variables from manager modal
  const handleSaveVariables = useCallback((updatedVars: FlowVariable[]) => {
    setFlowVariables(updatedVars);
    setCurrentVersion((prev) =>
      prev
        ? {
            ...prev,
            metadata: {
              ...(prev.metadata || {}),
              variables: updatedVars,
            },
          }
        : null
    );
    setIsDirty(true);
  }, []);

  // Derived available variable names from FlowVariables, Question and SetVariable nodes
  const availableVariables = useMemo(() => {
    const vars = new Set<string>();
    flowVariables.forEach((v) => vars.add(v.name));
    nodes.forEach((n) => {
      if (n.type === 'question' && n.data?.configuration?.variable) {
        const v = String(n.data.configuration.variable).trim();
        if (v) vars.add(v);
      }
      if (n.type === 'set_variable' && n.data?.configuration?.variable) {
        const v = String(n.data.configuration.variable).trim();
        if (v) vars.add(v);
      }
    });
    return Array.from(vars);
  }, [flowVariables, nodes]);

  // Simplified node list for selectors (e.g. GotoNode, condition variable type detection)
  const allNodesSummary = useMemo(() => {
    return nodes.map((n) => ({
      node_key: n.id,
      name: n.data.name || n.id,
      type: (n.type || n.data.type || 'message') as string,
      configuration: n.data?.configuration,
    }));
  }, [nodes]);

  // Backend node graph for Variable Manager scanner
  const backendNodesForVariableModal = useMemo(() => {
    return toBackendGraph(nodes, edges).nodes;
  }, [nodes, edges]);

  // Jump and focus on node on canvas
  const handleJumpToNode = useCallback((nodeKey: string) => {
    setSelectedNodeId(nodeKey);
    const target = nodes.find((n) => n.id === nodeKey);
    if (target && reactFlowInstance.current) {
      reactFlowInstance.current.setCenter(
        target.position.x + 130,
        target.position.y + 70,
        { duration: 600, zoom: 1.15 }
      );
    }
  }, [nodes]);

  // Contextual menu trigger on edge insertion (+)
  const handleOpenContextualMenuOnEdge = useCallback(
    (
      edgeId: string,
      source: string,
      target: string,
      sourceHandle?: string | null,
      clientPos?: { clientX: number; clientY: number }
    ) => {
      if (isReadOnly) return;
      const sourceNode = nodes.find((n) => n.id === source);
      const targetNode = nodes.find((n) => n.id === target);
      const pos = sourceNode && targetNode
        ? {
            x: Math.round((sourceNode.position.x + targetNode.position.x) / 2),
            y: Math.round((sourceNode.position.y + targetNode.position.y) / 2),
          }
        : { x: 300, y: 300 };

      setContextualTarget({
        type: 'edge',
        edgeId,
        sourceNodeKey: source,
        targetNodeKey: target,
        sourceHandle,
        position: pos,
        clientPosition: clientPos,
      });
      setIsContextualMenuOpen(true);
    },
    [isReadOnly, nodes]
  );

  // Releasing a dragged source handle on empty canvas opens the same block picker.
  const handleConnectEnd = useCallback(
    (event: MouseEvent | TouchEvent, connectionState: any) => {
      if (isReadOnly || connectionState.toNode || !connectionState.fromNode || !connectionState.fromHandle) return;

      const pointer = 'changedTouches' in event ? event.changedTouches[0] : event;
      if (!pointer) return;

      const clientPosition = { clientX: pointer.clientX, clientY: pointer.clientY };
      const position = reactFlowInstance.current
        ? reactFlowInstance.current.screenToFlowPosition({ x: pointer.clientX, y: pointer.clientY })
        : { x: 300, y: 300 };

      setContextualTarget({
        type: 'handle',
        sourceNodeKey: connectionState.fromNode.id,
        sourceHandle: connectionState.fromHandle.id || null,
        position,
        clientPosition,
      });
      setIsContextualMenuOpen(true);
    },
    [isReadOnly]
  );

  // Set of node keys that have validation errors
  const errorNodeKeys = useMemo(() => {
    const set = new Set<string>();
    validationErrors.forEach((e) => {
      if (e.node_key) set.add(e.node_key);
    });
    return set;
  }, [validationErrors]);

  // Toggle collapse for a branch
  const handleToggleCollapse = useCallback((nodeKey: string) => {
    setCollapsedNodeKeys((prev) => {
      const next = new Set(prev);
      if (next.has(nodeKey)) {
        next.delete(nodeKey);
      } else {
        next.add(nodeKey);
      }
      return next;
    });
  }, []);

  // Focus a specific branch
  const handleFocusBranch = useCallback((nodeKey: string) => {
    setFocusedNodeKey(nodeKey);
    setSelectedNodeId(nodeKey);
    setTimeout(() => {
      reactFlowInstance.current?.fitView({ padding: 0.2, duration: 500 });
    }, 50);
  }, []);

  // Exit branch focus view
  const handleExitBranchView = useCallback(() => {
    setFocusedNodeKey(null);
    setTimeout(() => {
      reactFlowInstance.current?.fitView({ padding: 0.2, duration: 500 });
    }, 50);
  }, []);

  // Autoorganize flow action
  const handleAutoOrganize = useCallback(async () => {
    if (isReadOnly || isAutoOrganizing || nodes.length === 0) return;

    const currentToken = ++layoutToken.current;
    setIsAutoOrganizing(true);
    try {
      pushHistory(nodes, edges);
      const layoutedNodes = await calculateAutoLayout(nodes, edges);
      if (currentToken !== layoutToken.current) return;

      setNodes(layoutedNodes);
      setIsDirty(true);
      setTimeout(() => {
        reactFlowInstance.current?.fitView({ padding: 0.2, duration: 600 });
      }, 50);
    } catch (err: any) {
      showToast('Error al autoorganizar el flujo: ' + (err.message || ''), 'error');
    } finally {
      setIsAutoOrganizing(false);
    }
  }, [isReadOnly, isAutoOrganizing, nodes, edges, pushHistory, showToast]);

  // Compute visible graph in unified continuous canvas (always 'full' continuous flow, or focused branch)
  const visibleGraph = useMemo(() => {
    return computeVisibleGraph(nodes, edges, {
      viewMode: focusedNodeKey ? 'branch' : 'full',
      collapsedNodeKeys,
      focusedNodeKey,
    });
  }, [nodes, edges, collapsedNodeKeys, focusedNodeKey]);

  const handleUpdateNodeName = useCallback(
    (nodeId: string, name: string) => {
      if (isReadOnly) return;
      setNodes((nds) =>
        nds.map((n) => (n.id === nodeId ? { ...n, data: { ...n.data, name } } : n))
      );
    },
    [isReadOnly]
  );

  // Display nodes memoized with callbacks, errors, and highlight states
  const displayNodes = useMemo(() => {
    return visibleGraph.visibleNodes.map((node) => {
      const hasErr = errorNodeKeys.has(node.id);
      const isHighlighted = highlightedNodeId === node.id;
      const isSimulating = simulatingNodeKey === node.id;
      const isSimulatingError = isSimulating && simulatingNodeError;
      const simClass = isSimulatingError
        ? 'flow-node-simulation-error'
        : (isSimulating ? 'flow-node-simulating' : '');

      return {
        ...node,
        className: simClass || undefined,
        data: {
          ...node.data,
          hasError: hasErr,
          isHighlighted,
          isSimulating,
          isSimulatingError,
          onToggleCollapse: handleToggleCollapse,
          onFocusBranch: handleFocusBranch,
          onUpdateName: (newName: string) => handleUpdateNodeName(node.id, newName),
        },
      };
    });
  }, [
    visibleGraph.visibleNodes,
    errorNodeKeys,
    highlightedNodeId,
    simulatingNodeKey,
    simulatingNodeError,
    handleToggleCollapse,
    handleFocusBranch,
    handleUpdateNodeName,
  ]);

  const displayEdges = visibleGraph.visibleEdges;

  // Node changes
  const onNodesChange = useCallback(
    (changes: NodeChange<Node<EditorNodeData>>[]) => {
      if (isReadOnly) return;
      setNodes((nds) => {
        const updated = applyNodeChanges<Node<EditorNodeData>>(changes, nds);
        const hasSignificantChange = changes.some(
          (c) => c.type === 'position' || c.type === 'remove'
        );
        if (hasSignificantChange) {
          setIsDirty(true);
        }
        return updated;
      });
    },
    [isReadOnly]
  );

  // Edge changes
  const onEdgesChange = useCallback(
    (changes: EdgeChange[]) => {
      if (isReadOnly) return;
      setEdges((eds) => {
        const updated = applyEdgeChanges(changes, eds);
        const hasSignificantChange = changes.some((c) => c.type === 'remove');
        if (hasSignificantChange) {
          setIsDirty(true);
        }
        return updated;
      });
    },
    [isReadOnly]
  );

  // New connection
  const onConnect = useCallback(
    (params: Connection) => {
      if (isReadOnly) return;

      pushHistory(nodes, edges);

      const sourceHandle = params.sourceHandle || null;
      const normSourceHandle = sourceHandle ? sourceHandle.toLowerCase() : null;
      let label: string | undefined;
      let strokeColor = '#61726d';

      if (normSourceHandle === 'true') {
        label = 'TRUE';
        strokeColor = '#5d8f82';
      } else if (normSourceHandle === 'false') {
        label = 'FALSE';
        strokeColor = '#dc2626';
      } else if (sourceHandle?.startsWith('opt_')) {
        strokeColor = '#0284c7';
        const sourceNode = nodes.find((n) => n.id === params.source);
        const optId = sourceHandle.replace('opt_', '');
        const opt = sourceNode?.data?.configuration?.options?.find((o: any) => o.id === optId);
        if (opt?.text) {
          label = opt.text.length > 20 ? opt.text.substring(0, 18) + '...' : opt.text;
        } else {
          label = 'Opción';
        }
      } else if (sourceHandle === 'success') {
        label = 'ÉXITO';
        strokeColor = '#5d8f82';
      } else if (sourceHandle === 'fail') {
        label = 'ERROR';
        strokeColor = '#dc2626';
      }

      const newEdge: Edge = {
        ...params,
        id: `edge-${params.source}-${normSourceHandle ?? sourceHandle ?? 'default'}-${params.target}-${Date.now()}`,
        type: 'conexaEdge',
        label,
        deletable: true,
        style: { stroke: strokeColor, strokeWidth: 2 },
        data: { condition_handle: normSourceHandle ?? sourceHandle, isReadOnly, metadata: { label } },
      };

      setEdges((eds) => addEdge(newEdge, eds));
      setIsDirty(true);
    },
    [isReadOnly, nodes, edges, pushHistory]
  );

  // Add node from Palette or drop
  const handleAddNode = useCallback(
    (type: FlowNodeType, position?: { x: number; y: number }) => {
      if (isReadOnly) return;

      pushHistory(nodes, edges);

      const timestamp = Date.now().toString(36);
      const nodeKey = `node_${type}_${timestamp}`;

      let defaultName = 'Nuevo Nodo';
      const defaultConfig: Record<string, any> = {};

      switch (type) {
        case 'message':
          defaultName = 'Mensaje';
          defaultConfig.text = 'Hola, ¿en qué podemos ayudarte?';
          break;
        case 'image':
          defaultName = 'Imagen';
          defaultConfig.url = '';
          defaultConfig.caption = '';
          defaultConfig.file_name = '';
          break;
        case 'file':
          defaultName = 'Documento';
          defaultConfig.url = '';
          defaultConfig.caption = '';
          defaultConfig.file_name = '';
          defaultConfig.mime_type = '';
          break;
        case 'menu':
          defaultName = 'Menú Principal';
          defaultConfig.title = 'Selecciona una opción';
          defaultConfig.options = [
            { id: '1', text: 'Opción 1', value: 'opt_1' },
            { id: '2', text: 'Opción 2', value: 'opt_2' },
          ];
          break;
        case 'question':
          defaultName = 'Pregunta';
          defaultConfig.text = '¿Cuál es tu consulta?';
          defaultConfig.variable = `var_${timestamp}`;
          defaultConfig.input_type = 'text';
          break;
        case 'http_request':
          defaultName = 'Petición HTTP';
          defaultConfig.method = 'GET';
          defaultConfig.url = 'https://api.ejemplo.com/datos';
          defaultConfig.headers = { Accept: 'application/json' };
          defaultConfig.mappings = [];
          break;
        case 'goto':
          defaultName = 'Ir a Bloque';
          defaultConfig.target_node_key = '';
          break;
        case 'set_variable':
          defaultName = 'Establecer Variable';
          defaultConfig.variable = availableVariables[0] || 'estado';
          defaultConfig.operation = 'set';
          defaultConfig.value = 'activo';
          break;
        case 'condition':
          defaultName = 'Condición';
          defaultConfig.logic = 'and';
          defaultConfig.rules = [
            {
              id: 'rule_1',
              variable: availableVariables[0] || 'opcion',
              operator: 'equals',
              compare_type: 'literal',
              value: 'si',
            },
          ];
          break;
        case 'transfer_to_agent':
          defaultName = 'Transferir a Agente';
          defaultConfig.message = 'Te comunicaremos con uno de nuestros ejecutivos.';
          break;
        case 'end':
          defaultName = 'Fin de Flujo';
          defaultConfig.message = 'Gracias por comunicarte con nosotros.';
          break;
      }

      // Compute coordinate: if not provided, center in canvas or cascade
      let targetPos = position;
      if (!targetPos) {
        if (reactFlowInstance.current) {
          const center = reactFlowInstance.current.screenToFlowPosition({
            x: window.innerWidth / 2,
            y: window.innerHeight / 2,
          });
          targetPos = {
            x: Math.round(center.x + (nodes.length * 15) % 100),
            y: Math.round(center.y + (nodes.length * 15) % 100),
          };
        } else {
          targetPos = { x: 250 + nodes.length * 20, y: 150 + nodes.length * 20 };
        }
      }

      const newNode: Node<EditorNodeData> = {
        id: nodeKey,
        type,
        position: targetPos,
        data: {
          node_key: nodeKey,
          type,
          name: defaultName,
          configuration: defaultConfig,
          hasError: false,
          isReadOnly: false,
        },
        deletable: true,
        draggable: true,
        selectable: true,
      };

      setNodes((nds) => [...nds, newNode]);
      setSelectedNodeId(nodeKey);
      setIsDirty(true);
    },
    [isReadOnly, nodes, edges, availableVariables, pushHistory]
  );

  // Smart contextual node insertion
  const handleInsertContextualNode = useCallback(
    (type: FlowNodeType, target: ContextualInsertionTarget) => {
      if (isReadOnly) return;

      pushHistory(nodes, edges);

      const timestamp = Date.now().toString(36);
      const nodeKey = `node_${type}_${timestamp}`;

      let defaultName = 'Nuevo Bloque';
      const defaultConfig: Record<string, any> = {};

      switch (type) {
        case 'message':
          defaultName = 'Mensaje';
          defaultConfig.text = 'Hola, ¿en qué podemos ayudarte?';
          break;
        case 'image':
          defaultName = 'Imagen';
          defaultConfig.url = '';
          defaultConfig.caption = '';
          defaultConfig.file_name = '';
          break;
        case 'file':
          defaultName = 'Documento';
          defaultConfig.url = '';
          defaultConfig.caption = '';
          defaultConfig.file_name = '';
          defaultConfig.mime_type = '';
          break;
        case 'menu':
          defaultName = 'Menú de opciones';
          defaultConfig.title = 'Selecciona una opción';
          defaultConfig.options = [
            { id: '1', text: 'Opción 1', value: 'opt_1' },
            { id: '2', text: 'Opción 2', value: 'opt_2' },
          ];
          break;
        case 'question':
          defaultName = 'Pregunta';
          defaultConfig.text = '¿Cuál es tu consulta?';
          defaultConfig.variable = `var_${timestamp}`;
          defaultConfig.input_type = 'text';
          break;
        case 'http_request':
          defaultName = 'Petición HTTP';
          defaultConfig.method = 'GET';
          defaultConfig.url = 'https://api.ejemplo.com/datos';
          defaultConfig.mappings = [];
          break;
        case 'set_variable':
          defaultName = 'Establecer variable';
          defaultConfig.variable = availableVariables[0] || 'mi_variable';
          defaultConfig.operation = 'set';
          defaultConfig.value = '';
          break;
        case 'goto':
          defaultName = 'Ir a bloque';
          defaultConfig.target_node_key = target.type === 'edge' ? (target.targetNodeKey || '') : '';
          break;
        case 'condition':
          defaultName = 'Condición';
          defaultConfig.logic = 'and';
          defaultConfig.rules = [
            {
              id: 'rule_1',
              variable: availableVariables[0] || 'estado',
              operator: 'equals',
              compare_type: 'literal',
              value: 'activo',
            },
          ];
          break;
        case 'transfer_to_agent':
          defaultName = 'Transferir a agente';
          defaultConfig.message = 'Te comunicaremos con un ejecutivo.';
          break;
        case 'end':
          defaultName = 'Fin de flujo';
          defaultConfig.message = 'Gracias por comunicarte.';
          break;
      }

      let nodePosition = target.position;

      // Smart insertion on edge
      if (target.type === 'edge') {
        const sourceNode = nodes.find((n) => n.id === target.sourceNodeKey);
        const targetNode = nodes.find((n) => n.id === target.targetNodeKey);

        if (sourceNode && targetNode) {
          nodePosition = {
            x: Math.round((sourceNode.position.x + targetNode.position.x) / 2),
            y: Math.round((sourceNode.position.y + targetNode.position.y) / 2),
          };
        }

        const newNode: Node<EditorNodeData> = {
          id: nodeKey,
          type,
          position: nodePosition,
          data: {
            node_key: nodeKey,
            type,
            name: defaultName,
            configuration: defaultConfig,
            hasError: false,
            isReadOnly: false,
          },
          deletable: true,
          draggable: true,
          selectable: true,
        };

        // 1. Remove the original edge
        const filteredEdges = edges.filter(
          (e) =>
            e.id !== target.edgeId &&
            !(e.source === target.sourceNodeKey && e.target === target.targetNodeKey && (target.sourceHandle ? e.sourceHandle === target.sourceHandle : true))
        );

        // 2. Create edge from sourceNode to newNode
        const edge1Handle = target.sourceHandle || null;
        let edge1Label: string | undefined;
        let edge1Color = '#61726d';
        if (edge1Handle === 'true') { edge1Label = 'TRUE'; edge1Color = '#5d8f82'; }
        else if (edge1Handle === 'false') { edge1Label = 'FALSE'; edge1Color = '#dc2626'; }
        else if (edge1Handle?.startsWith('opt_')) { edge1Color = '#0284c7'; }

        const edgeToNew: Edge = {
          id: `edge-${target.sourceNodeKey}-${edge1Handle ?? 'default'}-${nodeKey}-${Date.now()}`,
          type: 'conexaEdge',
          source: target.sourceNodeKey!,
          target: nodeKey,
          sourceHandle: edge1Handle || undefined,
          label: edge1Label,
          style: { stroke: edge1Color, strokeWidth: 2 },
          data: { condition_handle: edge1Handle, isReadOnly },
        };

        const newEdges = [...filteredEdges, edgeToNew];

        // 3. If newNode can connect to targetNode (not end, transfer_to_agent or goto)
        if (type !== 'end' && type !== 'transfer_to_agent' && type !== 'goto') {
          let edge2Handle: string | undefined = undefined;
          let edge2Label: string | undefined = undefined;
          let edge2Color = '#61726d';

          if (type === 'condition') {
            edge2Handle = 'true';
            edge2Label = 'TRUE';
            edge2Color = '#5d8f82';
          } else if (type === 'menu') {
            edge2Handle = 'opt_1';
            edge2Color = '#0284c7';
          } else if (type === 'http_request') {
            edge2Handle = 'success';
            edge2Label = 'ÉXITO';
            edge2Color = '#5d8f82';
          }

          const edgeFromNew: Edge = {
            id: `edge-${nodeKey}-${edge2Handle ?? 'default'}-${target.targetNodeKey}-${Date.now() + 1}`,
            type: 'conexaEdge',
            source: nodeKey,
            target: target.targetNodeKey!,
            sourceHandle: edge2Handle,
            label: edge2Label,
            style: { stroke: edge2Color, strokeWidth: 2 },
            data: { condition_handle: edge2Handle || null, isReadOnly },
          };
          newEdges.push(edgeFromNew);
        }

        setNodes((nds) => [...nds, newNode]);
        setEdges(newEdges);
        setSelectedNodeId(nodeKey);
        setIsPropertiesCollapsed(false);
        setIsDirty(true);
        setIsContextualMenuOpen(false);
        setContextualTarget(null);
        return;
      }

      // Handle or Canvas insertion
      const newNode: Node<EditorNodeData> = {
        id: nodeKey,
        type,
        position: nodePosition,
        data: {
          node_key: nodeKey,
          type,
          name: defaultName,
          configuration: defaultConfig,
          hasError: false,
          isReadOnly: false,
        },
        deletable: true,
        draggable: true,
        selectable: true,
      };

      let newEdges = [...edges];
      if (target.type === 'handle' && target.sourceNodeKey) {
        const handle = target.sourceHandle || undefined;
        let strokeColor = '#61726d';
        let label: string | undefined;
        if (handle === 'true') { label = 'TRUE'; strokeColor = '#5d8f82'; }
        else if (handle === 'false') { label = 'FALSE'; strokeColor = '#dc2626'; }
        else if (handle?.startsWith('opt_')) { strokeColor = '#0284c7'; }

        newEdges.push({
          id: `edge-${target.sourceNodeKey}-${handle ?? 'default'}-${nodeKey}-${Date.now()}`,
          type: 'conexaEdge',
          source: target.sourceNodeKey,
          target: nodeKey,
          sourceHandle: handle,
          label,
          style: { stroke: strokeColor, strokeWidth: 2 },
          data: { condition_handle: handle || null, isReadOnly },
        });
      }

      setNodes((nds) => [...nds, newNode]);
      setEdges(newEdges);
      setSelectedNodeId(nodeKey);
      setIsDirty(true);
      setIsContextualMenuOpen(false);
      setContextualTarget(null);
    },
    [isReadOnly, nodes, edges, availableVariables, pushHistory]
  );

  // Duplicate node
  const handleDuplicateNode = useCallback(
    (nodeId: string) => {
      if (isReadOnly) return;
      const sourceNode = nodes.find((n) => n.id === nodeId);
      if (!sourceNode || sourceNode.type === 'start') return;

      pushHistory(nodes, edges);

      const timestamp = Date.now().toString(36);
      const newKey = `node_${sourceNode.type}_${timestamp}`;

      const clonedNode: Node<EditorNodeData> = {
        id: newKey,
        type: sourceNode.type,
        position: {
          x: sourceNode.position.x + 40,
          y: sourceNode.position.y + 40,
        },
        data: {
          node_key: newKey,
          type: sourceNode.type as FlowNodeType,
          name: `${sourceNode.data.name} (Copia)`,
          configuration: JSON.parse(JSON.stringify(sourceNode.data.configuration || {})),
          hasError: false,
          isReadOnly: false,
        },
        deletable: true,
        draggable: true,
        selectable: true,
      };

      setNodes((nds) => [...nds, clonedNode]);
      setSelectedNodeId(newKey);
      setIsDirty(true);
    },
    [isReadOnly, nodes, edges, pushHistory]
  );

  // Delete node
  // Delete node and safely clean up references in other nodes
  const handleDeleteNode = useCallback(
    (nodeId: string) => {
      if (isReadOnly) return;
      const target = nodes.find((n) => n.id === nodeId);
      if (!target || target.type === 'start') {
        showToast('El nodo de inicio (Start) no se puede eliminar.', 'error');
        return;
      }

      pushHistory(nodes, edges);

      setNodes((nds) =>
        nds
          .filter((n) => n.id !== nodeId)
          .map((n) => {
            let config = n.data?.configuration;
            let changed = false;

            // Clean up Goto target pointing to deleted node
            if (n.type === 'goto' && config?.target_node_key === nodeId) {
              config = { ...config, target_node_key: '' };
              changed = true;
            }

            // Clean up Menu options target pointing to deleted node
            if (n.type === 'menu' && Array.isArray(config?.options)) {
              const cleanedOptions = config.options.map((opt: any) => {
                if (opt.target_node_key === nodeId) {
                  const { target_node_key: _targetNodeKey, ...rest } = opt;
                  changed = true;
                  return rest;
                }
                return opt;
              });
              if (changed) {
                config = { ...config, options: cleanedOptions };
              }
            }

            if (changed) {
              return {
                ...n,
                data: {
                  ...n.data,
                  configuration: config,
                },
              };
            }
            return n;
          })
      );

      setEdges((eds) => eds.filter((e) => e.source !== nodeId && e.target !== nodeId));
      if (selectedNodeId === nodeId) {
        setSelectedNodeId(null);
      }
      setIsDirty(true);
    },
    [isReadOnly, nodes, edges, selectedNodeId, pushHistory, showToast]
  );

  // Delete edge directly
  const handleDeleteEdge = useCallback(
    (edgeId: string) => {
      if (isReadOnly) return;
      pushHistory(nodes, edges);
      setEdges((eds) => eds.filter((e) => e.id !== edgeId));
      setIsDirty(true);
    },
    [isReadOnly, nodes, edges, pushHistory]
  );

  // Handle edge selection (clears selected node)
  const handleEdgeClick = useCallback(
    (edge: Edge) => {
      setSelectedNodeId(null);
      setEdges((eds) =>
        eds.map((e) => ({
          ...e,
          selected: e.id === edge.id,
        }))
      );
    },
    []
  );

  // Delete selected element (prioritizes selected edge to prevent accidental node deletion)
  const handleDeleteSelected = useCallback(() => {
    if (isReadOnly) return;

    const selectedEdge = edges.find((e) => e.selected);
    if (selectedEdge) {
      handleDeleteEdge(selectedEdge.id);
      return;
    }

    if (selectedNodeId) {
      handleDeleteNode(selectedNodeId);
      return;
    }
  }, [isReadOnly, selectedNodeId, edges, handleDeleteEdge, handleDeleteNode]);

  // Update properties of a node
  const handleUpdateNodeData = useCallback(
    (nodeId: string, updates: Partial<EditorNodeData>) => {
      if (isReadOnly) return;

      setNodes((nds) =>
        nds.map((n) => {
          if (n.id === nodeId) {
            return {
              ...n,
              data: {
                ...n.data,
                ...updates,
              },
            };
          }
          return n;
        })
      );

      // If menu options changed, prune edges connected to deleted option handles
      if (updates.configuration?.options && Array.isArray(updates.configuration.options)) {
        const validHandles = new Set(updates.configuration.options.map((opt: any) => 'opt_' + opt.id));
        setEdges((eds) =>
          eds.filter((e) => {
            if (e.source === nodeId && e.sourceHandle?.startsWith('opt_')) {
              return validHandles.has(e.sourceHandle);
            }
            return true;
          })
        );
      }

      setIsDirty(true);
    },
    [isReadOnly]
  );

  // Save Graph
  const handleSave = useCallback(async (): Promise<boolean> => {
    if (!currentVersion || !flow) return false;
    setIsSaving(true);

    try {
      const graph = toBackendGraph(nodes, edges);
      const res = await api.saveFlowVersionGraph(flow.id, currentVersion.id, {
        expected_updated_at: expectedUpdatedAt,
        nodes: graph.nodes,
        edges: graph.edges,
        metadata: {
          ...(currentVersion.metadata || {}),
          variables: flowVariables,
        },
      });

      if (res.data?.updated_at) {
        setExpectedUpdatedAt(res.data.updated_at);
      }
      const newMetadata = res.data?.metadata;
      if (newMetadata) {
        setCurrentVersion((prev) => (prev ? { ...prev, metadata: newMetadata } : null));
      }

      setIsDirty(false);
      return true;
    } catch (err: any) {
      if (err.status === 409) {
        showToast('Este flujo fue modificado en otra sesión o por otro usuario. Recarga para obtener la versión más reciente.', 'error');
      } else {
        showToast(err.message || 'Error al guardar el flujo.', 'error');
      }
      return false;
    } finally {
      setIsSaving(false);
    }
  }, [currentVersion, flow, nodes, edges, expectedUpdatedAt, flowVariables, showToast]);

  // Validate Flow
  const handleValidate = useCallback(async () => {
    if (!currentVersion || !flow) return;

    if (isDirty) {
      const saved = await handleSave();
      if (!saved) return;
    }

    setIsValidating(true);
    try {
      const res = await api.validateFlowVersion(flow.id, currentVersion.id);
      setIsValid(res.valid);

      let parsedErrors: FlowValidationError[] = [];
      if (res.structured_errors && res.structured_errors.length > 0) {
        parsedErrors = res.structured_errors;
      } else if (res.errors && res.errors.length > 0) {
        parsedErrors = res.errors.map((msg: string) => {
          const match = msg.match(/'([^']+)'/);
          return {
            code: 'VALIDATION_ERROR',
            node_key: match ? match[1] : null,
            message: msg,
          };
        });
      }

      setValidationErrors(parsedErrors);
    } catch (err: any) {
      showToast(err.message || 'Error al validar el flujo.', 'error');
    } finally {
      setIsValidating(false);
    }
  }, [currentVersion, flow, isDirty, handleSave, showToast]);

  // Publish flow version
  const handlePublishClick = useCallback(async () => {
    if (!currentVersion || !flow) return;

    if (isDirty) {
      const saved = await handleSave();
      if (!saved) return;
    }

    setIsValidating(true);
    try {
      const valRes = await api.validateFlowVersion(flow.id, currentVersion.id);
      if (!valRes.valid) {
        setIsValid(false);
        const errs: FlowValidationError[] = valRes.structured_errors || (valRes.errors || []).map((m: string) => ({
          code: 'VALIDATION_ERROR',
          node_key: null,
          message: m,
        }));
        setValidationErrors(errs);
        const issueLabel = errs.length === 1 ? 'problema pendiente' : 'problemas pendientes';
        showToast(`No se puede publicar: ${errs.length} ${issueLabel}. Revisa el panel de validación; puedes seleccionar cada problema para ir al nodo correspondiente.`, 'error');
        return;
      }

      setShowPublishModal(true);
    } catch (err: any) {
      showToast(err.message || 'Error al comprobar validación antes de publicar.', 'error');
    } finally {
      setIsValidating(false);
    }
  }, [currentVersion, flow, isDirty, handleSave, showToast]);

  // Open simulator (auto-saves draft if dirty so simulator tests latest canvas graph)
  const handleOpenSimulator = useCallback(async () => {
    if (isDirty && !isReadOnly) {
      const saved = await handleSave();
      if (!saved) return;
    }
    setIsSimulatorOpen(true);
  }, [isDirty, isReadOnly, handleSave]);

  const handlePublishConfirm = async (notes: string): Promise<boolean> => {
    if (!currentVersion || !flow) return false;
    setIsPublishing(true);

    try {
      const trimmedNotes = notes.trim();
      if (trimmedNotes) {
        await api.publishFlowVersion(flow.id, currentVersion.id, trimmedNotes);
      } else {
        await api.publishFlowVersion(flow.id, currentVersion.id);
      }
      return true;
    } finally {
      setIsPublishing(false);
    }
  };

  const handlePublishSuccessDismiss = async () => {
    setShowPublishModal(false);
    setPublishNotes('');
    if (currentVersion) {
      await loadSpecificVersion(currentVersion.id);
    }
  };

  // Versioning Drawer, Comparison, and Rollback Handlers (Fase 3)
  const handleOpenHistory = useCallback(() => {
    setIsHistoryDrawerOpen(true);
  }, []);

  const handleOpenCompare = useCallback((sourceId?: string, targetId?: string) => {
    setCompareSourceId(sourceId);
    setCompareTargetId(targetId);
    setIsCompareModalOpen(true);
  }, []);

  const handleInitiateRollback = useCallback((ver: FlowVersion) => {
    setRollbackTargetVersion(ver);
    setIsRollbackModalOpen(true);
  }, []);

  const handleConfirmRollback = useCallback(async (notes?: string) => {
    if (!flow || !rollbackTargetVersion) return;
    try {
      const res = await api.rollbackFlowVersion(flow.id, rollbackTargetVersion.id, notes);
      showToast(res.message || `Rollback completado con éxito a la versión ${rollbackTargetVersion.version_number}.`, 'success');
      setIsRollbackModalOpen(false);
      setRollbackTargetVersion(null);
      await loadSpecificVersion(rollbackTargetVersion.id);
    } catch (err: any) {
      showToast(err.message || 'Error al ejecutar rollback de la versión.', 'error');
      throw err;
    }
  }, [flow, rollbackTargetVersion, loadSpecificVersion, showToast]);

  const handleDeleteVersion = useCallback((versionId: string) => {
    if (!flow) return;
    setConfirmDialog({
      isOpen: true,
      title: 'Eliminar versión borrador',
      description: `Esta acción eliminará permanentemente la versión borrador v${versions.find((version) => version.id === versionId)?.version_number ?? ''} del flujo «${flow.name}». Escribe el nombre exacto del flujo para confirmar.`,
      confirmText: 'Eliminar versión',
      confirmVariant: 'danger',
      requiredText: flow.name,
      onConfirm: async () => {
        setConfirmDialog(null);
        try {
          await api.deleteFlowVersion(flow.id, versionId);
          showToast('Versión borrador eliminada con éxito.', 'success');
          const flowRes = await api.getFlow(flowId);
          setFlow(flowRes.data);
          setVersions(flowRes.data.versions || []);
          setPublications(flowRes.data.publications || []);
          if (currentVersion?.id === versionId) {
            const nextId = flowRes.data.active_version_id || flowRes.data.versions?.[0]?.id;
            if (nextId) {
              await loadSpecificVersion(nextId);
            }
          }
        } catch (err: any) {
          showToast(err.message || 'Error al eliminar la versión.', 'error');
        }
      },
    });
  }, [flow, flowId, currentVersion, loadSpecificVersion, showToast]);

  // Open Clone/New Draft modal
  const handleOpenCreateDraftModal = useCallback((targetVersion?: FlowVersion) => {
    setCloneSourceVersion(targetVersion || currentVersion || null);
    setShowCreateDraftModal(true);
  }, [currentVersion]);

  // Execute Clone into new Draft version from modal
  const handleConfirmCreateDraft = async () => {
    const sourceVer = cloneSourceVersion || currentVersion;
    if (!sourceVer || !flow) return;

    setIsCreatingDraft(true);
    try {
      const res = await api.createFlowVersion(flow.id, {
        from_version_id: sourceVer.id,
        description: `Borrador basado en versión ${sourceVer.version_number}`,
      });
      const newVersionId = res.data?.id;
      setShowCreateDraftModal(false);
      setCloneSourceVersion(null);
      if (newVersionId) {
        await loadSpecificVersion(newVersionId);
      }
    } catch (err: any) {
      showToast(err.message || 'Error al crear nueva versión.', 'error');
    } finally {
      setIsCreatingDraft(false);
    }
  };

  // Version change handler
  const handleSelectVersion = (versionId: string) => {
    if (versionId === currentVersion?.id) return;
    if (isDirty) {
      setConfirmDialog({
        isOpen: true,
        title: 'Cambios sin guardar',
        description: 'Tienes cambios sin guardar en esta versión. ¿Deseas descartarlos y cambiar de versión?',
        confirmText: 'Descartar y cambiar',
        confirmVariant: 'danger',
        onConfirm: () => {
          setConfirmDialog(null);
          loadSpecificVersion(versionId);
        },
      });
      return;
    }
    loadSpecificVersion(versionId);
  };

  // Back navigation handler
  const handleBack = () => {
    if (isDirty) {
      setConfirmDialog({
        isOpen: true,
        title: 'Salir del constructor',
        description: 'Tienes cambios sin guardar. ¿Estás seguro de que deseas salir del constructor?',
        confirmText: 'Salir sin guardar',
        confirmVariant: 'danger',
        onConfirm: () => {
          setConfirmDialog(null);
          onBack();
        },
      });
      return;
    }
    onBack();
  };

  // Undo / Redo
  const handleUndo = useCallback(() => {
    if (isReadOnly || undoStack.current.length === 0) return;
    const previous = undoStack.current.pop();
    if (!previous) return;

    redoStack.current.push({
      nodes: JSON.parse(JSON.stringify(nodes)),
      edges: JSON.parse(JSON.stringify(edges)),
    });

    setNodes(previous.nodes);
    setEdges(previous.edges);
    setIsDirty(true);
    setCanUndo(undoStack.current.length > 0);
    setCanRedo(true);
  }, [isReadOnly, nodes, edges]);

  const handleRedo = useCallback(() => {
    if (isReadOnly || redoStack.current.length === 0) return;
    const next = redoStack.current.pop();
    if (!next) return;

    undoStack.current.push({
      nodes: JSON.parse(JSON.stringify(nodes)),
      edges: JSON.parse(JSON.stringify(edges)),
    });

    setNodes(next.nodes);
    setEdges(next.edges);
    setIsDirty(true);
    setCanRedo(redoStack.current.length > 0);
    setCanUndo(true);
  }, [isReadOnly, nodes, edges]);

  // Navigation handlers
  const handleFitView = useCallback(() => {
    if (reactFlowInstance.current) {
      reactFlowInstance.current.fitView({ padding: 0.2, duration: 600 });
    }
  }, []);

  const handleCenterStart = useCallback(() => {
    const startNode = nodes.find((n) => n.type === 'start') || nodes[0];
    if (startNode && reactFlowInstance.current) {
      reactFlowInstance.current.setCenter(
        startNode.position.x + 130,
        startNode.position.y + 60,
        { duration: 600, zoom: 1 }
      );
    }
  }, [nodes]);

  const handleCenterSelected = useCallback(() => {
    if (!selectedNodeId) return;
    const target = nodes.find((n) => n.id === selectedNodeId);
    if (target && reactFlowInstance.current) {
      reactFlowInstance.current.setCenter(
        target.position.x + 130,
        target.position.y + 70,
        { duration: 600, zoom: 1.15 }
      );
    }
  }, [nodes, selectedNodeId]);

  const handleResetView = useCallback(() => {
    const startNode = nodes.find((n) => n.type === 'start') || nodes[0];
    if (startNode && reactFlowInstance.current) {
      reactFlowInstance.current.setCenter(
        startNode.position.x + 130,
        startNode.position.y + 60,
        { duration: 500, zoom: 1 }
      );
    } else if (reactFlowInstance.current) {
      reactFlowInstance.current.setViewport({ x: 0, y: 0, zoom: 1 }, { duration: 500 });
    }
  }, [nodes]);

  // Navigate to error node
  const handleSelectErrorNode = useCallback(
    (nodeKey: string) => {
      setSelectedNodeId(nodeKey);
      const target = nodes.find((n) => n.id === nodeKey);
      if (target && reactFlowInstance.current) {
        reactFlowInstance.current.setCenter(
          target.position.x + 130,
          target.position.y + 60,
          { duration: 600, zoom: 1.1 }
        );
      }
    },
    [nodes]
  );

  // Search node selection
  const handleSelectSearchNode = useCallback((nodeKey: string) => {
    setCollapsedNodeKeys((prev) => {
      const next = new Set(prev);
      next.delete(nodeKey);
      return next;
    });

    setFocusedNodeKey(null);

    setSelectedNodeId(nodeKey);
    setHighlightedNodeId(nodeKey);

    setTimeout(() => {
      setHighlightedNodeId((current) => (current === nodeKey ? null : current));
    }, 3000);

    const target = nodes.find((n) => n.id === nodeKey);
    if (target && reactFlowInstance.current) {
      reactFlowInstance.current.setCenter(
        target.position.x + 130,
        target.position.y + 70,
        { duration: 600, zoom: 1.15 }
      );
    }
  }, [nodes]);

  // Keyboard shortcut listener (Cmd+S, Cmd+Z, Cmd+Shift+Z, Cmd+F)
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
      const modKey = isMac ? e.metaKey : e.ctrlKey;

      const target = e.target as HTMLElement;
      const inInput = ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.isContentEditable;

      // Search: Cmd+K / Ctrl+K or Cmd+F / Ctrl+F
      if (modKey && (e.key === 'f' || e.key === 'F' || e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        setIsSearchOpen((prev) => !prev);
        return;
      }

      // Save: Cmd+S / Ctrl+S
      if (modKey && (e.key === 's' || e.key === 'S')) {
        e.preventDefault();
        if (!isReadOnly && isDirty) {
          handleSave();
        }
        return;
      }

      // Zen Mode: F11
      if (e.key === 'F11') {
        e.preventDefault();
        handleToggleZenMode();
        return;
      }

      // Escape key: deselect active node, exit zen mode, or collapse properties drawer
      if (e.key === 'Escape') {
        if (selectedNodeId) {
          handleDeselectNode();
          return;
        }
        if (isZenMode) {
          setIsZenMode(false);
          return;
        }
        if (!isPropertiesCollapsed) {
          handleToggleProperties();
          return;
        }
      }

      // Undo / Redo if not inside typing input
      if (!inInput && modKey && (e.key === 'z' || e.key === 'Z')) {
        e.preventDefault();
        if (e.shiftKey) {
          handleRedo();
        } else {
          handleUndo();
        }
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [
    isReadOnly,
    isDirty,
    handleSave,
    handleUndo,
    handleRedo,
    selectedNodeId,
    isPropertiesCollapsed,
    isZenMode,
    handleDeselectNode,
    handleToggleProperties,
    handleToggleZenMode,
  ]);

  const selectedNode = useMemo(
    () => nodes.find((n) => n.id === selectedNodeId) || null,
    [nodes, selectedNodeId]
  );

  if (loading) {
    return (
      <div style={{ display: 'flex', height: '100vh', alignItems: 'center', justifyContent: 'center', background: '#f4f7f5' }}>
        <p style={{ color: 'var(--text-muted)', fontWeight: 500 }}>Cargando constructor de flujos...</p>
      </div>
    );
  }

  return (
    <div className="flow-editor-container">
      {/* Responsive warning on small screens */}
      <div className="small-screen-warning">
        Para una experiencia óptima al diseñar flujos, utiliza una pantalla más grande (escritorio o tablet horizontal).
      </div>

      {/* Permission warning banner if read-only due to lack of flows.edit */}
      {!hasEditPermission && (
        <div style={{ background: '#fef3c7', borderBottom: '1px solid #fde68a', padding: '0.4rem 1.25rem', display: 'flex', alignItems: 'center', gap: '0.5rem', fontSize: '0.8rem', color: '#92400e' }}>
          <ShieldAlert size={14} />
          <strong>Permisos insuficientes para editar:</strong> Tu rol actual solo permite visualizar este flujo.
        </div>
      )}

      {/* Published read-only banner */}
      {isPublished && hasEditPermission && (
        <div className="flow-published-notice">
          <div className="flow-published-notice-content">
            <Lock size={13} className="flow-published-notice-icon" />
            <span>
              <strong>Versión {currentVersion?.version_number} publicada — solo lectura.</strong> Las versiones publicadas son inmutables para garantizar el version pinning.
            </span>
          </div>
        </div>
      )}

      {/* Top Toolbar */}
      <FlowToolbar
        flow={flow}
        currentVersion={currentVersion}
        versions={versions}
        isDirty={isDirty}
        isSaving={isSaving}
        isValidating={isValidating}
        isPublishing={isPublishing}
        canUndo={canUndo}
        canRedo={canRedo}
        isReadOnly={isReadOnly}
        isAutoOrganizing={isAutoOrganizing}
        isZenMode={isZenMode}
        hasSelectedNode={Boolean(selectedNodeId)}
        highlightConnections={highlightConnections}
        onBack={handleBack}
        onSelectVersion={handleSelectVersion}
        onSave={handleSave}
        onValidate={handleValidate}
        onPublish={handlePublishClick}
        onFitView={handleFitView}
        onCenterStart={handleCenterStart}
        onCenterSelected={handleCenterSelected}
        onResetView={handleResetView}
        onToggleZenMode={handleToggleZenMode}
        onUndo={handleUndo}
        onRedo={handleRedo}
        onCloneNewVersion={() => handleOpenCreateDraftModal()}
        onAutoOrganize={handleAutoOrganize}
        onSearchClick={() => setIsSearchOpen((prev) => !prev)}
        onToggleHighlightConnections={() => setHighlightConnections((prev) => !prev)}
        variableCount={flowVariables.length}
        isPaletteOpen={isPaletteOpen}
        onTogglePalette={() => setIsPaletteOpen((prev) => !prev)}
        onOpenVariableManager={() => setIsVariableManagerOpen(true)}
        onOpenSimulator={handleOpenSimulator}
        onOpenHistory={handleOpenHistory}
        onOpenCompare={() => handleOpenCompare()}
        onRollback={() => {
          if (currentVersion && currentVersion.status === 'published' && !currentVersion.is_active) {
            handleInitiateRollback(currentVersion);
          } else {
            handleOpenHistory();
          }
        }}
      />

      {/* Main Builder Body */}
      <div className="flow-editor-main">
        {/* Search Modal */}
        <FlowSearchModal
          nodes={nodes}
          isOpen={isSearchOpen}
          onClose={() => setIsSearchOpen(false)}
          onSelectNode={handleSelectSearchNode}
        />

        {/* Collapsible / Floating Node Palette Drawer */}
        <div
          className={`node-palette-drawer ${isPaletteOpen ? 'is-open' : 'is-closed'}`}
          data-testid="node-palette-drawer"
        >
          <NodePalette
            onAddNode={(type) => {
              handleAddNode(type);
              setIsPaletteOpen(false);
            }}
            onClose={() => setIsPaletteOpen(false)}
            isReadOnly={isReadOnly}
          />
        </div>

        {/* Center: Interactive React Flow Canvas */}
        <FlowCanvas
          nodes={displayNodes}
          edges={displayEdges}
          selectedNodeId={selectedNodeId}
          simulatingEdge={simulatingEdge}
          highlightConnections={highlightConnections}
          isReadOnly={isReadOnly}
          showMiniMap={showMiniMap}
          focusedBranchTitle={visibleGraph.focusedBranchTitle}
          onToggleMiniMap={handleToggleMiniMap}
          onTogglePalette={() => setIsPaletteOpen(true)}
          onExitBranchView={handleExitBranchView}
          onNodesChange={onNodesChange}
          onEdgesChange={onEdgesChange}
          onConnect={onConnect}
          onConnectEnd={handleConnectEnd}
          onNodeClick={(node) => {
            setSelectedNodeId(node.id);
            setEdges((eds) => eds.map((e) => (e.selected ? { ...e, selected: false } : e)));
            setIsPropertiesCollapsed(false);
          }}
          onEdgeClick={handleEdgeClick}
          onPaneClick={() => {
            if (isPaletteOpen) setIsPaletteOpen(false);
            setSelectedNodeId(null);
            setEdges((eds) => eds.map((e) => (e.selected ? { ...e, selected: false } : e)));
          }}
          onDropNode={(type, pos) => handleAddNode(type, pos)}
          onDeleteSelected={handleDeleteSelected}
          onDeleteEdge={handleDeleteEdge}
          onInit={(instance) => {
            reactFlowInstance.current = instance;
          }}
          onInsertNodeOnEdge={handleOpenContextualMenuOnEdge}
          onCenterStart={handleCenterStart}
          onCenterSelected={handleCenterSelected}
          onResetView={handleResetView}
          hasSelectedNode={Boolean(selectedNodeId)}
        />

        {/* Floating expand tab when properties panel is collapsed */}
        {isPropertiesCollapsed && (
          <button
            type="button"
            className="properties-expand-tab"
            data-testid="properties-expand-tab"
            onClick={handleToggleProperties}
            title="Mostrar panel de propiedades"
            aria-label="Mostrar panel de propiedades"
          >
            <PanelRightOpen size={16} />
            <span>Propiedades</span>
          </button>
        )}

        {/* Backdrop on tablet/mobile when drawer is open */}
        {!isPropertiesCollapsed && (
          <div
            className="properties-backdrop"
            data-testid="properties-backdrop"
            onClick={handleToggleProperties}
          />
        )}

        {/* Right: Properties Inspector */}
        <PropertiesPanel
          selectedNode={selectedNode}
          flow={flow}
          version={currentVersion}
          availableVariables={availableVariables}
          flowVariables={flowVariables}
          allNodes={allNodesSummary}
          isReadOnly={isReadOnly}
          isCollapsed={isPropertiesCollapsed}
          onToggleCollapse={handleToggleProperties}
          onDeselectNode={handleDeselectNode}
          onUpdateNodeData={handleUpdateNodeData}
          onDuplicateNode={handleDuplicateNode}
          onDeleteNode={handleDeleteNode}
          onJumpToNode={handleJumpToNode}
          onUploadAttachment={async (file) => {
            const res = await api.uploadFlowAttachment(flowId, file);
            return (res as any).data?.data || (res as any).data || res;
          }}
        />

        {/* Floating Validation Drawer */}
        <ValidationPanel
          errors={validationErrors}
          isValid={isValid}
          onSelectNode={handleSelectErrorNode}
          onClose={() => setIsValid(null)}
        />
      </div>

      {/* Contextual Insertion Menu */}
      <ContextualNodeMenu
        isOpen={isContextualMenuOpen}
        target={contextualTarget}
        onClose={() => {
          setIsContextualMenuOpen(false);
          setContextualTarget(null);
        }}
        onSelectNodeType={handleInsertContextualNode}
      />

      {/* Variable Manager Modal */}
      <VariableManagerModal
        isOpen={isVariableManagerOpen}
        variables={flowVariables}
        nodes={backendNodesForVariableModal}
        onClose={() => setIsVariableManagerOpen(false)}
        onSaveVariables={handleSaveVariables}
      />

      {/* Flow Simulator Drawer */}
      <FlowSimulatorDrawer
        isOpen={isSimulatorOpen}
        flow={flow}
        version={currentVersion}
        onClose={() => {
          setIsSimulatorOpen(false);
          setSimulatingNodeKey(null);
          setSimulatingNodeError(false);
          setSimulatingEdge(null);
        }}
        onFocusNode={handleJumpToNode}
        onActiveNodeChange={(nodeKey, isError) => {
          setSimulatingNodeKey(nodeKey);
          setSimulatingNodeError(Boolean(isError));
          if (nodeKey) {
            handleJumpToNode(nodeKey);
          }
        }}
        onActiveEdgeChange={(edge) => {
          setSimulatingEdge(edge);
        }}
      />

      {/* Publish Confirmation Modal */}
      <FlowPublishModal
        isOpen={showPublishModal && Boolean(currentVersion)}
        versionNumber={currentVersion?.version_number ?? ''}
        notes={publishNotes}
        onChangeNotes={setPublishNotes}
        onPublish={handlePublishConfirm}
        onClose={() => setShowPublishModal(false)}
        onSuccessDismiss={handlePublishSuccessDismiss}
      />

      {/* Create Draft Confirmation Modal */}
      <FlowCreateDraftModal
        isOpen={showCreateDraftModal && Boolean(cloneSourceVersion || currentVersion)}
        currentVersionNumber={(cloneSourceVersion || currentVersion)?.version_number ?? ''}
        isCreating={isCreatingDraft}
        onConfirm={handleConfirmCreateDraft}
        onClose={() => {
          setShowCreateDraftModal(false);
          setCloneSourceVersion(null);
        }}
      />

      {/* Version History Drawer */}
      <FlowVersionHistoryDrawer
        flowName={flow?.name}
        activeVersionId={flow?.active_version_id || null}
        currentVersionId={currentVersion?.id || null}
        versions={versions}
        publications={publications}
        isOpen={isHistoryDrawerOpen}
        canPublish={Boolean(auth ? (auth.hasPermission ? auth.hasPermission('flows.publish') : true) : true)}
        canEdit={hasEditPermission}
        onClose={() => setIsHistoryDrawerOpen(false)}
        onSelectVersion={(vId) => {
          setIsHistoryDrawerOpen(false);
          handleSelectVersion(vId);
        }}
        onCloneVersion={(vId) => {
          setIsHistoryDrawerOpen(false);
          const targetVer = versions.find((v) => v.id === vId);
          handleOpenCreateDraftModal(targetVer);
        }}
        onPublishVersion={(vId) => {
          setIsHistoryDrawerOpen(false);
          if (currentVersion?.id === vId) {
            handlePublishClick();
          } else {
            handleSelectVersion(vId);
          }
        }}
        onRollbackVersion={(ver) => {
          handleInitiateRollback(ver);
        }}
        onCompareVersions={(srcId, tgtId) => {
          handleOpenCompare(srcId, tgtId);
        }}
        onDeleteVersion={handleDeleteVersion}
      />

      {/* Version Comparison Modal */}
      {flow && (
        <FlowVersionCompareModal
          flowId={flow.id}
          versions={versions}
          initialSourceVersionId={compareSourceId}
          initialTargetVersionId={compareTargetId}
          isOpen={isCompareModalOpen}
          onClose={() => setIsCompareModalOpen(false)}
        />
      )}

      {/* Rollback Confirmation Modal */}
      {rollbackTargetVersion && (
        <FlowRollbackModal
          version={rollbackTargetVersion}
          activeVersionNumber={versions.find((v) => v.id === flow?.active_version_id)?.version_number}
          isOpen={isRollbackModalOpen}
          onClose={() => {
            setIsRollbackModalOpen(false);
            setRollbackTargetVersion(null);
          }}
          onConfirm={handleConfirmRollback}
        />
      )}

      {/* Dynamic Action Confirm Modal */}
      {confirmDialog && (
        <ConfirmModal
          isOpen={confirmDialog.isOpen}
          title={confirmDialog.title}
          description={confirmDialog.description}
          confirmText={confirmDialog.confirmText}
          cancelText={confirmDialog.cancelText}
          confirmVariant={confirmDialog.confirmVariant || 'primary'}
          requiredText={confirmDialog.requiredText}
          requiredTextLabel="flujo"
          onConfirm={confirmDialog.onConfirm}
          onClose={() => setConfirmDialog(null)}
          testId="flow-editor-confirm-modal"
        />
      )}

    </div>
  );
};

export const FlowEditor: React.FC<FlowEditorProps> = (props) => {
  return (
    <ReactFlowProvider>
      <InnerFlowEditor {...props} />
    </ReactFlowProvider>
  );
};
