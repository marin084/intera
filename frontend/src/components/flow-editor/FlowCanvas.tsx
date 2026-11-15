import React, { useCallback, useRef, useMemo } from 'react';
import {
  ReactFlow,
  Background,
  Controls,
  ControlButton,
  MiniMap,
  type Connection,
  type Edge,
  type Node,
  type OnNodesChange,
  type OnEdgesChange,
  type OnConnect,
  type ReactFlowInstance,
  BackgroundVariant,
} from '@xyflow/react';
import { Map, ArrowLeft, Play, Crosshair, RotateCcw, Plus } from 'lucide-react';
import { flowNodeTypes } from './nodes';
import { ConexaEdge } from './edges/ConexaEdge';
import type { EditorNodeData } from './adapter';
import type { FlowNodeType } from '../../types';

interface FlowCanvasProps {
  nodes: Node<EditorNodeData>[];
  edges: Edge[];
  selectedNodeId?: string | null;
  simulatingEdge?: { id?: string; source_node_key?: string; target_node_key?: string; condition_handle?: string | null } | null;
  highlightConnections?: boolean;
  isReadOnly?: boolean;
  showMiniMap?: boolean;
  focusedBranchTitle?: string;
  onCenterStart?: () => void;
  onCenterSelected?: () => void;
  onResetView?: () => void;
  hasSelectedNode?: boolean;
  onToggleMiniMap?: () => void;
  onExitBranchView?: () => void;
  onNodesChange: OnNodesChange<Node<EditorNodeData>>;
  onEdgesChange: OnEdgesChange;
  onConnect: OnConnect;
  onConnectEnd?: (event: MouseEvent | TouchEvent, connectionState: any) => void;
  onNodeClick: (node: Node<EditorNodeData>) => void;
  onPaneClick: () => void;
  onDropNode: (type: FlowNodeType, position: { x: number; y: number }) => void;
  onDeleteSelected: () => void;
  onInit: (instance: ReactFlowInstance<Node<EditorNodeData>, Edge>) => void;
  onInsertNodeOnEdge?: (
    edgeId: string,
    source: string,
    target: string,
    sourceHandle?: string | null,
    clientPos?: { clientX: number; clientY: number }
  ) => void;
  onDeleteEdge?: (edgeId: string) => void;
  onEdgeClick?: (edge: Edge) => void;
  onTogglePalette?: () => void;
}

export const FlowCanvas: React.FC<FlowCanvasProps> = ({
  nodes,
  edges,
  selectedNodeId,
  simulatingEdge,
  highlightConnections = true,
  isReadOnly = false,
  showMiniMap = true,
  focusedBranchTitle,
  onCenterStart,
  onCenterSelected,
  onResetView,
  hasSelectedNode = false,
  onToggleMiniMap,
  onExitBranchView,
  onNodesChange,
  onEdgesChange,
  onConnect,
  onConnectEnd,
  onNodeClick,
  onPaneClick,
  onDropNode,
  onDeleteSelected,
  onInit,
  onInsertNodeOnEdge,
  onDeleteEdge,
  onEdgeClick,
  onTogglePalette,
}) => {
  const reactFlowWrapper = useRef<HTMLDivElement>(null);
  const reactFlowInstance = useRef<ReactFlowInstance<Node<EditorNodeData>, Edge> | null>(null);
  const memoizedNodeTypes = useMemo(() => flowNodeTypes as any, []);
  const memoizedEdgeTypes = useMemo(() => ({ conexaEdge: ConexaEdge } as any), []);

  const handleInit = useCallback(
    (instance: ReactFlowInstance<Node<EditorNodeData>, Edge>) => {
      reactFlowInstance.current = instance;
      onInit(instance);
    },
    [onInit]
  );

  const handleDragOver = useCallback((event: React.DragEvent) => {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
  }, []);

  const handleDrop = useCallback(
    (event: React.DragEvent) => {
      event.preventDefault();
      if (isReadOnly || !reactFlowInstance.current || !reactFlowWrapper.current) {
        return;
      }

      const type = event.dataTransfer.getData('application/conexa-node-type') as FlowNodeType;
      if (!type) return;

      const position = reactFlowInstance.current.screenToFlowPosition({
        x: event.clientX,
        y: event.clientY,
      });

      onDropNode(type, position);
    },
    [isReadOnly, onDropNode]
  );

  // Structural connection validator (Section 12 & 18)
  const isValidConnection = useCallback(
    (connection: Connection | Edge) => {
      if (isReadOnly) return false;

      const { source, target, sourceHandle } = connection;
      if (!source || !target || source === target) {
        return false;
      }

      const sourceNode = nodes.find((n) => n.id === source);
      const targetNode = nodes.find((n) => n.id === target);

      if (!sourceNode || !targetNode) {
        return false;
      }

      // Rule: Start node cannot have incoming edges
      if (targetNode.type === 'start') {
        return false;
      }

      // Rule: Transfer and End cannot have outgoing edges
      if (sourceNode.type === 'transfer_to_agent' || sourceNode.type === 'end') {
        return false;
      }

      // Outgoing edges from source node
      const currentOutgoing = edges.filter((e) => e.source === source);

      // Rule: Start, Message, Question, Set Variable: exactly max 1 outgoing edge
      if (['start', 'message', 'question', 'set_variable', 'goto'].includes(sourceNode.type || '')) {
        if (currentOutgoing.length >= 1) {
          return false;
        }
      }

      // Rule: Condition node: exactly 1 true and 1 false handle
      if (sourceNode.type === 'condition') {
        const normSourceHandle = sourceHandle ? sourceHandle.toLowerCase() : '';
        if (normSourceHandle !== 'true' && normSourceHandle !== 'false') {
          return false;
        }

        const existingWithSameHandle = currentOutgoing.find((e) => {
          const h = e.sourceHandle || (e.data as any)?.condition_handle;
          return h && String(h).toLowerCase() === normSourceHandle;
        });

        if (existingWithSameHandle) {
          return false;
        }
      }

      // Rule: Menu node: 1 outgoing edge per option handle
      if (sourceNode.type === 'menu') {
        if (!sourceHandle || !sourceHandle.startsWith('opt_')) {
          return false;
        }

        const existingWithSameHandle = currentOutgoing.find(
          (e) => (e.sourceHandle || (e.data as any)?.condition_handle) === sourceHandle
        );

        if (existingWithSameHandle) {
          return false;
        }
      }

      return true;
    },
    [nodes, edges, isReadOnly]
  );

  // Handle keyboard shortcuts (Delete/Backspace)
  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (isReadOnly) return;

      const target = e.target as HTMLElement;
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.isContentEditable) {
        return;
      }

      if (e.key === 'Delete' || e.key === 'Backspace') {
        onDeleteSelected();
      }
    },
    [isReadOnly, onDeleteSelected]
  );

  // Contextual edge styling (highlight connected edges, dim others when node selected)
  const renderedEdges = useMemo(() => {
    return edges.map((edge) => {
      const isConnected = !selectedNodeId || !highlightConnections
        ? true
        : (edge.source === selectedNodeId || edge.target === selectedNodeId);
      const isOutgoing = edge.source === selectedNodeId;

      const edgeData = {
        ...(edge.data || {}),
        isReadOnly,
        onInsertNode: onInsertNodeOnEdge,
        onDeleteEdge,
      };

      const isSimulatingEdge = Boolean(
        simulatingEdge && (
          (simulatingEdge.id && edge.id === simulatingEdge.id) ||
          (edge.source === simulatingEdge.source_node_key &&
           edge.target === simulatingEdge.target_node_key &&
           (!simulatingEdge.condition_handle || (edge.sourceHandle || (edge.data as any)?.condition_handle) === simulatingEdge.condition_handle))
        )
      );

      if (isSimulatingEdge) {
        return {
          ...edge,
          animated: true,
          className: 'flow-edge-simulating',
          style: {
            ...edge.style,
            stroke: '#5d8f82',
            strokeWidth: 4,
            opacity: 1,
          },
          zIndex: 30,
          data: edgeData,
        };
      }

      if (!selectedNodeId || !highlightConnections) {
        return {
          ...edge,
          data: edgeData,
        };
      }

      if (isConnected) {
        const strokeColor = isOutgoing
          ? (edge.sourceHandle === 'false' ? '#dc2626' : edge.sourceHandle === 'true' ? '#5d8f82' : '#5d8f82')
          : '#5d8f82';

        return {
          ...edge,
          animated: true,
          style: {
            ...edge.style,
            stroke: strokeColor,
            strokeWidth: 3,
            opacity: 1,
          },
          zIndex: 10,
          data: edgeData,
        };
      }

      return {
        ...edge,
        animated: false,
        style: {
          ...edge.style,
          opacity: 0.22,
          strokeWidth: 1.5,
        },
        zIndex: 0,
        data: edgeData,
      };
    });
  }, [edges, selectedNodeId, highlightConnections, isReadOnly, simulatingEdge, onInsertNodeOnEdge, onDeleteEdge]);

  return (
    <div
      className="flow-canvas-wrapper"
      ref={reactFlowWrapper}
      onDragOver={handleDragOver}
      onDrop={handleDrop}
      onKeyDown={handleKeyDown}
      tabIndex={0}
      style={{ outline: 'none' }}
    >
      {/* Branch Focus Header Banner */}
      {focusedBranchTitle && (
        <div className="branch-focus-banner">
          <span>
            Enfocando rama: <strong>{focusedBranchTitle}</strong>
          </span>
          <button type="button" onClick={onExitBranchView}>
            <ArrowLeft size={12} style={{ display: 'inline', marginRight: '4px' }} />
            Volver a vista completa
          </button>
        </div>
      )}

      <ReactFlow<Node<EditorNodeData>, Edge>
        nodes={nodes}
        edges={renderedEdges}
        nodeTypes={memoizedNodeTypes}
        edgeTypes={memoizedEdgeTypes}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onConnect={onConnect}
        onConnectEnd={onConnectEnd}
        onInit={handleInit}
        onNodeClick={(_, node) => onNodeClick(node as Node<EditorNodeData>)}
        onEdgeClick={(_, edge) => onEdgeClick && onEdgeClick(edge)}
        onPaneClick={onPaneClick}
        isValidConnection={isValidConnection}
        nodesDraggable={!isReadOnly}
        nodesConnectable={!isReadOnly}
        elementsSelectable={true}
        onlyRenderVisibleElements={true}
        panOnScroll={true}
        zoomOnPinch={true}
        panOnDrag={[1, 2]}
        zoomOnScroll={false}
        fitView
        snapToGrid={true}
        snapGrid={[15, 15]}
        deleteKeyCode={null}
      >
        <Background variant={BackgroundVariant.Dots} gap={16} size={1} color="#d9e3de" />
        <Controls showInteractive={!isReadOnly}>
          {onCenterStart && (
            <ControlButton
              onClick={onCenterStart}
              title="Centrar en el inicio del flujo"
              aria-label="Centrar en inicio"
            >
              <Play size={12} fill="#5d8f82" color="#5d8f82" />
            </ControlButton>
          )}
          {onCenterSelected && (
            <ControlButton
              onClick={onCenterSelected}
              title="Centrar en bloque seleccionado"
              aria-label="Centrar en seleccionado"
              disabled={!hasSelectedNode}
            >
              <Crosshair size={12} />
            </ControlButton>
          )}
          {onResetView && (
            <ControlButton
              onClick={onResetView}
              title="Restaurar zoom original (100%)"
              aria-label="Restaurar zoom"
            >
              <RotateCcw size={12} />
            </ControlButton>
          )}
        </Controls>

        {showMiniMap && (
          <MiniMap
            nodeStrokeWidth={3}
            zoomable
            pannable
            style={{ height: 110, width: 150 }}
            nodeColor={(node) => {
              switch (node.type) {
                case 'start':
                  return '#5d8f82';
                case 'message':
                  return '#5d8f82';
                case 'image':
                  return '#5d8f82';
                case 'question':
                  return '#9333ea';
                case 'condition':
                  return '#d97706';
                case 'transfer_to_agent':
                  return '#5d8f82';
                case 'end':
                  return '#61726d';
                default:
                  return '#80918c';
              }
            }}
          />
        )}
      </ReactFlow>

      {/* Floating quick-add button for Canvas-First UX */}
      {!isReadOnly && onTogglePalette && (
        <button
          type="button"
          className="flow-canvas-floating-add-btn"
          data-testid="floating-add-node-btn"
          onClick={onTogglePalette}
          title="Abrir catálogo de bloques (+)"
          aria-label="Abrir catálogo de bloques"
        >
          <Plus size={15} />
          <span>Agregar bloque</span>
        </button>
      )}

      {/* MiniMap Toggle Button */}
      {onToggleMiniMap && (
        <button
          type="button"
          className="minimap-toggle-btn"
          onClick={onToggleMiniMap}
          title={showMiniMap ? 'Ocultar minimapa' : 'Mostrar minimapa'}
        >
          <Map size={16} />
        </button>
      )}
    </div>
  );
};
