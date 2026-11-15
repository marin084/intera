import React, { useState, useRef, useMemo, useEffect } from 'react';
import type { Node } from '@xyflow/react';
import type { EditorNodeData } from './adapter';
import type { Flow, FlowVersion, FlowVariable } from '../../types';
import { api } from '../../services/api';
import {
  Copy,
  Trash2,
  AlertCircle,
  Plus,
  ArrowUp,
  ArrowDown,
  X,
  Variable,
  Info,
  Layers,
  Clock,
  ExternalLink,
  PanelRightClose,
  PanelRightOpen,
  ChevronDown,
  GripVertical,
  SlidersHorizontal,
  Play,
  MessageSquare,
  ImageIcon,
  ImageOff,
  FileText,
  UploadCloud,
  Loader2,
  ListFilter,
  HelpCircle,
  Split,
  Globe,
  CornerDownRight,
  Headphones,
  CheckCircle2,
  Eye,
  EyeOff,
  Headset,
} from 'lucide-react';
import { MultichannelPreview } from './MultichannelPreview';
import { VariablePicker } from './VariablePicker';
import { resolveMediaUrl } from '../../utils/media';

interface PropertiesPanelProps {
  selectedNode: Node<EditorNodeData> | null;
  flow: Flow | null;
  version: FlowVersion | null;
  availableVariables: string[];
  flowVariables?: FlowVariable[];
  allNodes?: Array<{ node_key: string; name: string; type: string; configuration?: any }>;
  isReadOnly?: boolean;
  isCollapsed?: boolean;
  onToggleCollapse?: () => void;
  onDeselectNode?: () => void;
  onUpdateNodeData: (nodeId: string, updates: Partial<EditorNodeData>) => void;
  onDuplicateNode: (nodeId: string) => void;
  onDeleteNode: (nodeId: string) => void;
  onJumpToNode?: (nodeKey: string) => void;
  onUploadAttachment?: (file: File) => Promise<{ url: string; file_name?: string; mime_type?: string; size_bytes?: number }>;
}

const NODE_TYPE_META: Record<
  string,
  {
    icon: React.ComponentType<{ size?: number; className?: string; color?: string; fill?: string }>;
    label: string;
    color: string;
    bgColor: string;
  }
> = {
  start: { icon: Play, label: 'Inicio', color: '#5d8f82', bgColor: '#eaf1ee' },
  message: { icon: MessageSquare, label: 'Mensaje', color: '#5d8f82', bgColor: '#eaf1ee' },
  image: { icon: ImageIcon, label: 'Imagen', color: '#5d8f82', bgColor: '#ecfeff' },
  file: { icon: FileText, label: 'Documento / Archivo', color: '#5d8f82', bgColor: '#f0fdfa' },
  menu: { icon: ListFilter, label: 'Menú Interactivo', color: '#0284c7', bgColor: '#e0f2fe' },
  question: { icon: HelpCircle, label: 'Pregunta', color: '#9333ea', bgColor: '#faf5ff' },
  condition: { icon: Split, label: 'Condición', color: '#d97706', bgColor: '#fffbeb' },
  set_variable: { icon: Variable, label: 'Variable', color: '#456c61', bgColor: '#eef2ff' },
  http_request: { icon: Globe, label: 'Petición HTTP', color: '#5d8f82', bgColor: '#eaf1ee' },
  goto: { icon: CornerDownRight, label: 'Ir a Bloque', color: '#5d8f82', bgColor: '#eaf1ee' },
  transfer_to_agent: { icon: Headphones, label: 'Transferir', color: '#5d8f82', bgColor: '#ecfeff' },
  end: { icon: CheckCircle2, label: 'Fin de Flujo', color: '#61726d', bgColor: '#eaf1ee' },
};

const CONDITION_OPERATORS = [
  { value: 'equals', label: 'Igual a (=)' },
  { value: 'not_equals', label: 'Distinto de (!=)' },
  { value: 'contains', label: 'Contiene texto' },
  { value: 'not_contains', label: 'No contiene texto' },
  { value: 'starts_with', label: 'Empieza con' },
  { value: 'ends_with', label: 'Termina con' },
  { value: 'gt', label: 'Mayor que (>)' },
  { value: 'gte', label: 'Mayor o igual (>=)' },
  { value: 'lt', label: 'Menor que (<)' },
  { value: 'lte', label: 'Menor o igual (<=)' },
  { value: 'exists', label: 'Existe (no nulo)' },
  { value: 'not_exists', label: 'No existe (nulo o vacío)' },
  { value: 'is_empty', label: 'Está vacío' },
  { value: 'not_empty', label: 'No está vacío' },
];

const BOOLEAN_CONDITION_OPERATORS = [
  { value: 'equals', label: 'Igual a (=)' },
  { value: 'not_equals', label: 'Distinto de (!=)' },
];

const cleanVarName = (name?: string | null): string => {
  if (!name) return '';
  return name.trim().replace(/^[$]+/, '').replace(/^\{\{+|\}\}+$/g, '').trim().toLowerCase();
};

interface FlowAvailableVariable {
  name: string;
  type: 'boolean' | 'number' | 'text' | 'json' | 'date' | 'list' | 'unknown';
  source: 'set_variable' | 'question' | 'http_request' | 'flow_variable' | 'contact' | 'system';
  sourceLabel: string;
  options?: string[];
}

const extractAvailableFlowVariables = (
  allNodesList?: Array<{ node_key?: string; id?: string; name?: string; type: string; configuration?: any; data?: any }>,
  flowVarsList?: FlowVariable[],
  availableVarsStrings?: string[]
): FlowAvailableVariable[] => {
  const map = new Map<string, FlowAvailableVariable>();

  // 1. From allNodes
  if (allNodesList && allNodesList.length > 0) {
    for (const node of allNodesList) {
      const type = node.type || (node as any).data?.type;
      const cfg = node.configuration || (node as any).data?.configuration || {};

      if (type === 'set_variable') {
        const rawName = cfg.variable || cfg.variable_name;
        const clean = cleanVarName(rawName);
        if (clean) {
          const typeVal = (cfg.data_type || cfg.type || 'text') as any;
          map.set(clean, {
            name: String(rawName).trim().replace(/^[$]+/, '').replace(/^\{\{+|\}\}+$/g, '').trim(),
            type: typeVal === 'boolean' || typeVal === 'number' || typeVal === 'json' ? typeVal : 'text',
            source: 'set_variable',
            sourceLabel: node.name || (node as any).data?.name ? `Nodo: ${node.name || (node as any).data?.name}` : 'Asignar Variable',
          });
        }
      } else if (type === 'question') {
        const rawName = cfg.variable || cfg.variable_name;
        const clean = cleanVarName(rawName);
        if (clean) {
          const rawChoices = cfg.choices || cfg.options;
          let extractedOptions: string[] | undefined;
          if (Array.isArray(rawChoices) && rawChoices.length > 0) {
            extractedOptions = rawChoices
              .map((o: any) => {
                if (typeof o === 'string') return o.trim();
                if (o && typeof o === 'object') {
                  const str = o.text ?? o.title ?? o.label ?? o.value;
                  return str !== undefined && str !== null ? String(str).trim() : '';
                }
                return '';
              })
              .filter((s: string) => s.length > 0);
            if (extractedOptions.length === 0) {
              extractedOptions = undefined;
            }
          }

          map.set(clean, {
            name: String(rawName).trim().replace(/^[$]+/, '').replace(/^\{\{+|\}\}+$/g, '').trim(),
            type: 'text',
            source: 'question',
            sourceLabel: node.name || (node as any).data?.name ? `Nodo: ${node.name || (node as any).data?.name}` : 'Pregunta',
            options: extractedOptions,
          });
        }
      } else if (type === 'http_request') {
        if (Array.isArray(cfg.response_mapping)) {
          for (const m of cfg.response_mapping) {
            const rawName = m.variable_name || m.variable;
            const clean = cleanVarName(rawName);
            if (clean && !map.has(clean)) {
              map.set(clean, {
                name: String(rawName).trim().replace(/^[$]+/, '').replace(/^\{\{+|\}\}+$/g, '').trim(),
                type: 'unknown',
                source: 'http_request',
                sourceLabel: node.name || (node as any).data?.name ? `HTTP: ${node.name || (node as any).data?.name}` : 'Petición HTTP',
              });
            }
          }
        }
        if (cfg.variable || cfg.response_variable) {
          const rawName = cfg.variable || cfg.response_variable;
          const clean = cleanVarName(rawName);
          if (clean && !map.has(clean)) {
            map.set(clean, {
              name: String(rawName).trim().replace(/^[$]+/, '').replace(/^\{\{+|\}\}+$/g, '').trim(),
              type: 'unknown',
              source: 'http_request',
              sourceLabel: node.name || (node as any).data?.name ? `HTTP: ${node.name || (node as any).data?.name}` : 'Petición HTTP',
            });
          }
        }
      }
    }
  }

  // 2. From flowVariables
  if (flowVarsList && flowVarsList.length > 0) {
    for (const fv of flowVarsList) {
      const clean = cleanVarName(fv.name);
      if (clean) {
        if (!map.has(clean) || map.get(clean)?.type === 'unknown') {
          map.set(clean, {
            name: fv.name,
            type: fv.type || 'text',
            source: 'flow_variable',
            sourceLabel: 'Variable Global',
          });
        }
      }
    }
  }

  // 3. From availableVarsStrings
  if (availableVarsStrings && availableVarsStrings.length > 0) {
    for (const s of availableVarsStrings) {
      const clean = cleanVarName(s);
      if (clean && !map.has(clean)) {
        map.set(clean, {
          name: s.trim().replace(/^[$]+/, '').replace(/^\{\{+|\}\}+$/g, '').trim(),
          type: 'text',
          source: 'flow_variable',
          sourceLabel: 'Variable del Flujo',
        });
      }
    }
  }

  // 4. Standard Contact & System variables
  const standardVars: FlowAvailableVariable[] = [
    { name: 'contact.name', type: 'text', source: 'contact', sourceLabel: 'Contacto' },
    { name: 'contact.phone', type: 'text', source: 'contact', sourceLabel: 'Contacto' },
    { name: 'contact.email', type: 'text', source: 'contact', sourceLabel: 'Contacto' },
    { name: 'contact.country', type: 'text', source: 'contact', sourceLabel: 'Contacto' },
    { name: 'system.channel', type: 'text', source: 'system', sourceLabel: 'Sistema' },
    { name: 'system.agents_online', type: 'boolean', source: 'system', sourceLabel: 'Sistema: Booleano' },
    { name: 'system.agents_count', type: 'number', source: 'system', sourceLabel: 'Sistema: Número' },
  ];

  for (const sv of standardVars) {
    const clean = cleanVarName(sv.name);
    if (!map.has(clean)) {
      map.set(clean, sv);
    }
  }

  return Array.from(map.values());
};

const isRuleBoolean = (
  ruleOrConfig: { variable?: string; value?: any; data_type?: string; type?: string },
  allNodesList?: Array<{ node_key: string; name: string; type: string; configuration?: any }>,
  flowVarsList?: FlowVariable[],
  availableVarsList?: FlowAvailableVariable[]
): boolean => {
  const explicitType = ruleOrConfig.data_type || ruleOrConfig.type;
  if (explicitType === 'boolean') return true;
  if (explicitType === 'text' || explicitType === 'number') return false;

  const val = ruleOrConfig.value;
  if (typeof val === 'boolean') return true;
  if (typeof val === 'string') {
    const valUpper = val.trim().toUpperCase();
    if (valUpper === 'TRUE' || valUpper === 'FALSE') return true;
  }

  const targetVar = cleanVarName(ruleOrConfig.variable);
  if (targetVar) {
    if (availableVarsList && availableVarsList.length > 0) {
      const match = availableVarsList.find((v) => cleanVarName(v.name) === targetVar);
      if (match && match.type === 'boolean') return true;
    }

    if (allNodesList && allNodesList.length > 0) {
      const matchNode = allNodesList.find((n) => {
        if (n.type !== 'set_variable') return false;
        const nodeVar = cleanVarName(n.configuration?.variable || n.configuration?.variable_name);
        if (nodeVar === targetVar) {
          const dt = n.configuration?.data_type || n.configuration?.type;
          return dt === 'boolean';
        }
        return false;
      });
      if (matchNode) return true;
    }

    if (flowVarsList && flowVarsList.length > 0) {
      const matchFv = flowVarsList.find((fv) => cleanVarName(fv.name) === targetVar && fv.type === 'boolean');
      if (matchFv) return true;
    }
  }

  return false;
};

const getVariableOptions = (
  varName?: string | null,
  availableVarsList?: FlowAvailableVariable[],
  allNodesList?: Array<{ node_key?: string; id?: string; name?: string; type: string; configuration?: any; data?: any }>
): string[] | undefined => {
  const clean = cleanVarName(varName);
  if (!clean) return undefined;

  // 1. Check in availableVarsList
  if (availableVarsList && availableVarsList.length > 0) {
    const found = availableVarsList.find((v) => cleanVarName(v.name) === clean);
    if (found?.options && found.options.length > 0) {
      return found.options;
    }
  }

  // 2. Fallback check allNodesList directly
  if (allNodesList && allNodesList.length > 0) {
    for (const node of allNodesList) {
      const type = node.type || (node as any).data?.type;
      if (type === 'question') {
        const cfg = node.configuration || (node as any).data?.configuration || {};
        const rawName = cfg.variable || cfg.variable_name;
        if (cleanVarName(rawName) === clean) {
          const rawChoices = cfg.choices || cfg.options;
          if (Array.isArray(rawChoices) && rawChoices.length > 0) {
            const opts = rawChoices
              .map((o: any) => {
                if (typeof o === 'string') return o.trim();
                if (o && typeof o === 'object') {
                  const str = o.text ?? o.title ?? o.label ?? o.value;
                  return str !== undefined && str !== null ? String(str).trim() : '';
                }
                return '';
              })
              .filter((s: string) => s.length > 0);
            if (opts.length > 0) return opts;
          }
        }
      }
    }
  }

  return undefined;
};

interface VariableAutocompleteInputProps {
  id: string;
  value: string;
  disabled?: boolean;
  testId?: string;
  placeholder?: string;
  availableVars: FlowAvailableVariable[];
  onSelectVariable: (variable: FlowAvailableVariable) => void;
  onChangeValue: (value: string) => void;
}

const VariableAutocompleteInput: React.FC<VariableAutocompleteInputProps> = ({
  id,
  value,
  disabled = false,
  testId = 'condition-variable-input',
  placeholder = 'Variable (ej. estado)',
  availableVars,
  onSelectVariable,
  onChangeValue,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as unknown as globalThis.Node)) {
        setIsOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const datalistId = `datalist-flow-vars-${id}`;

  return (
    <div style={{ position: 'relative', width: '100%' }} ref={containerRef}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
        <input
          type="text"
          className="form-input"
          data-testid={testId}
          placeholder={placeholder}
          value={value}
          disabled={disabled}
          list={datalistId}
          onChange={(e) => onChangeValue(e.target.value)}
          onFocus={() => {
            if (!disabled && availableVars.length > 0) {
              setIsOpen(true);
            }
          }}
          style={{ fontSize: '0.75rem', padding: '0.25rem 0.4rem', flex: 1 }}
        />

        <button
          type="button"
          className="btn btn-secondary btn-sm"
          data-testid={`btn-variable-suggestions-${id}`}
          style={{
            padding: '0.2rem 0.45rem',
            fontSize: '0.68rem',
            display: 'flex',
            alignItems: 'center',
            gap: '0.25rem',
            background: '#f4f7f5',
            border: '1px solid #d9e3de',
            borderRadius: '4px',
            color: '#263c38',
            fontWeight: 600,
            whiteSpace: 'nowrap',
            cursor: disabled ? 'not-allowed' : 'pointer',
          }}
          disabled={disabled}
          onClick={() => setIsOpen((prev) => !prev)}
          title="Variables disponibles en el flujo"
        >
          <Variable size={11} color="#456c61" />
          <span>Variables ({availableVars.length})</span>
          <ChevronDown size={10} color="#61726d" />
        </button>
      </div>

      <datalist id={datalistId}>
        {availableVars.map((v) => (
          <option key={v.name} value={v.name}>
            {`${v.name} [${v.type === 'boolean' ? 'Booleano' : v.type === 'number' ? 'Número' : 'Texto'}] - ${v.sourceLabel}`}
          </option>
        ))}
      </datalist>

      {isOpen && !disabled && (
        <div
          className="variable-suggestions-dropdown"
          data-testid={`variable-suggestions-dropdown-${id}`}
          style={{
            position: 'absolute',
            left: 0,
            top: 'calc(100% + 4px)',
            width: '100%',
            minWidth: '260px',
            maxWidth: '340px',
            background: '#ffffff',
            border: '1px solid #d9e3de',
            borderRadius: '6px',
            boxShadow: '0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1)',
            zIndex: 70,
            maxHeight: '200px',
            overflowY: 'auto',
            padding: '0.25rem 0',
          }}
        >
          <div
            style={{
              padding: '0.25rem 0.5rem',
              fontSize: '0.63rem',
              fontWeight: 700,
              color: '#61726d',
              textTransform: 'uppercase',
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
            }}
          >
            <span>Variables Disponibles ({availableVars.length})</span>
            <span style={{ fontSize: '0.58rem', fontWeight: 400, color: '#80918c' }}>clic para aplicar</span>
          </div>

          {availableVars.length === 0 ? (
            <div style={{ padding: '0.4rem 0.5rem', fontSize: '0.72rem', color: '#80918c' }}>
              No se detectaron variables en el flujo
            </div>
          ) : (
            availableVars.map((v) => {
              const isSelected = cleanVarName(value) === cleanVarName(v.name);
              return (
                <div
                  key={v.name}
                  data-testid={`suggestion-option-${v.name}`}
                  role="button"
                  tabIndex={0}
                  style={{
                    padding: '0.35rem 0.5rem',
                    fontSize: '0.73rem',
                    cursor: 'pointer',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    borderBottom: '1px solid #f4f7f5',
                    backgroundColor: isSelected ? '#eaf1ee' : 'transparent',
                  }}
                  onMouseDown={(e) => {
                    e.preventDefault();
                    onSelectVariable(v);
                    setIsOpen(false);
                  }}
                  onMouseEnter={(e) => {
                    if (!isSelected) e.currentTarget.style.backgroundColor = '#eaf1ee';
                  }}
                  onMouseLeave={(e) => {
                    if (!isSelected) e.currentTarget.style.backgroundColor = 'transparent';
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                    <span style={{ fontFamily: 'monospace', fontWeight: 600, color: '#35564f' }}>
                      {v.name}
                    </span>
                    <span
                      style={{
                        fontSize: '0.6rem',
                        padding: '0.05rem 0.3rem',
                        borderRadius: '3px',
                        fontWeight: 600,
                        backgroundColor:
                          v.type === 'boolean'
                            ? '#eaf1ee'
                            : v.type === 'number'
                            ? '#fef3c7'
                            : v.options && v.options.length > 0
                            ? '#eaf1ee'
                            : v.source === 'contact'
                            ? '#ecfeff'
                            : '#eaf1ee',
                        color:
                          v.type === 'boolean'
                            ? '#263c38'
                            : v.type === 'number'
                            ? '#b45309'
                            : v.options && v.options.length > 0
                            ? '#263c38'
                            : v.source === 'contact'
                            ? '#0e7490'
                            : '#456c61',
                      }}
                    >
                      {v.type === 'boolean'
                        ? 'Booleano'
                        : v.type === 'number'
                        ? 'Número'
                        : v.options && v.options.length > 0
                        ? `Opciones (${v.options.length})`
                        : v.source === 'contact'
                        ? 'Contacto'
                        : 'Texto'}
                    </span>
                  </div>
                  <span style={{ fontSize: '0.62rem', color: '#80918c' }}>
                    {v.sourceLabel}
                  </span>
                </div>
              );
            })
          )}
        </div>
      )}
    </div>
  );
};

export const PropertiesPanel: React.FC<PropertiesPanelProps> = ({
  selectedNode,
  flow,
  version,
  availableVariables,
  flowVariables = [],
  allNodes = [],
  isReadOnly = false,
  isCollapsed = false,
  onToggleCollapse,
  onDeselectNode,
  onUpdateNodeData,
  onDuplicateNode,
  onDeleteNode,
  onJumpToNode,
  onUploadAttachment,
}) => {
  const [newChoiceText, setNewChoiceText] = useState('');
  const [newMenuOptionText, setNewMenuOptionText] = useState('');
  const [customChoiceMode, setCustomChoiceMode] = useState<Record<string, boolean>>({});
  const [newJsonPath, setNewJsonPath] = useState('');
  const [newMappingVar, setNewMappingVar] = useState('');
  const [isAdvancedOpen, setIsAdvancedOpen] = useState(false);
  const [isUploading, setIsUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [isDragOver, setIsDragOver] = useState(false);
  const [previewError, setPreviewError] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const docFileInputRef = useRef<HTMLInputElement>(null);

  const availableFlowVars = useMemo(() => {
    return extractAvailableFlowVariables(allNodes, flowVariables, availableVariables);
  }, [allNodes, flowVariables, availableVariables]);

  if (!selectedNode) {
    return (
      <aside className={`properties-panel ${isCollapsed ? 'collapsed' : ''}`} data-testid="properties-panel">
        <div className="properties-header">
          <div className="properties-header-main">
            <div
              className="properties-node-icon"
              style={{ backgroundColor: '#eaf1ee', color: '#5d8f82' }}
            >
              <Layers size={16} />
            </div>
            <div className="properties-header-title-wrap">
              <span style={{ fontWeight: 700, fontSize: '0.95rem', color: 'var(--text-main)' }}>
                Detalles del Flujo
              </span>
              {version && (
                <div className="properties-header-meta">
                  <span className={`badge ${version.status === 'published' ? 'badge-success' : 'badge-warning'}`}>
                    v{version.version_number} - {version.status === 'published' ? 'Publicada' : 'Borrador'}
                  </span>
                </div>
              )}
            </div>
          </div>

          <div className="properties-header-actions">
            {onToggleCollapse && (
              <button
                type="button"
                className="btn btn-secondary btn-sm properties-toggle-btn"
                data-testid="properties-toggle-btn"
                onClick={onToggleCollapse}
                title={isCollapsed ? 'Mostrar panel' : 'Ocultar panel'}
                aria-label={isCollapsed ? 'Mostrar panel' : 'Ocultar panel'}
                style={{ padding: '0.3rem 0.45rem', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}
              >
                {isCollapsed ? <PanelRightOpen size={16} /> : <PanelRightClose size={16} />}
              </button>
            )}
          </div>
        </div>

        <div className="properties-body">
          {flow && (
            <div className="properties-section" style={{ padding: '0.875rem' }}>
              <div style={{ fontWeight: 700, fontSize: '0.95rem', color: 'var(--text-main)' }}>
                {flow.name}
              </div>
              {flow.description && (
                <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                  {flow.description}
                </div>
              )}

              <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.72rem', color: 'var(--text-muted)' }}>
                <Clock size={12} />
                <span>Actualizado: {flow.updated_at ? new Date(flow.updated_at).toLocaleString() : 'N/A'}</span>
              </div>
            </div>
          )}

          {/* Available variables overview */}
          <div className="properties-section">
            <div className="properties-section-header">
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                <Variable size={14} color="#456c61" />
                <span className="properties-section-title">Variables Detectadas</span>
              </div>
            </div>
            {availableVariables.length === 0 ? (
              <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', margin: 0 }}>
                No hay variables creadas todavía. Añade nodos de <strong>Pregunta</strong> o <strong>Variable</strong> para almacenar datos.
              </p>
            ) : (
              <div className="properties-vars-chips">
                {availableVariables.map((v) => (
                  <span
                    key={v}
                    className="badge badge-info"
                    style={{ fontSize: '0.72rem', fontFamily: 'monospace' }}
                  >
                    {`{{${v}}}`}
                  </span>
                ))}
              </div>
            )}
          </div>

          {/* Quick instructions / tips */}
          <div style={{ background: '#f4f7f5', padding: '0.85rem', borderRadius: '0.5rem', border: '1px solid #d9e3de', fontSize: '0.75rem' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontWeight: 600, marginBottom: '0.4rem', color: '#35564f' }}>
              <Info size={14} color="#0284c7" /> Atajos del Constructor
            </div>
            <ul style={{ paddingLeft: '1.25rem', color: '#61726d', lineHeight: '1.5', margin: 0 }}>
              <li><strong>Clic en nodo:</strong> abre su inspector</li>
              <li><strong>Arrastrar salida:</strong> conecta nodos</li>
              <li><strong>Supr / Backspace:</strong> elimina selección</li>
              <li><strong>Cmd/Ctrl + S:</strong> guardar cambios</li>
              <li><strong>Cmd/Ctrl + Z / Shift + Z:</strong> deshacer / rehacer</li>
            </ul>
          </div>
        </div>
      </aside>
    );
  }

  const { data } = selectedNode;
  const nodeType = data.type;
  const config = data.configuration || {};

  const meta = NODE_TYPE_META[nodeType] || {
    icon: Layers,
    label: nodeType,
    color: '#61726d',
    bgColor: '#eaf1ee',
  };
  const NodeIcon = meta.icon;

  const handleNameChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    onUpdateNodeData(selectedNode.id, { name: e.target.value });
  };

  const handleConfigChange = (key: string, value: any) => {
    onUpdateNodeData(selectedNode.id, {
      configuration: {
        ...config,
        [key]: value,
      },
    });
  };

  const insertVariableIntoField = (field: string, varName: string) => {
    if (isReadOnly) return;
    const currentVal = config[field] || '';
    handleConfigChange(field, `${currentVal}{{${varName}}}`);
  };

  const handleFileUpload = async (file: File) => {
    if (isReadOnly || !file) return;

    if (nodeType === 'file') {
      const allowedExts = ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'txt', 'csv'];
      const fileExt = file.name.split('.').pop()?.toLowerCase() || '';
      const validDocMimes = [
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      ];

      const isExtAllowed = allowedExts.includes(fileExt);
      const isMimeAllowed = validDocMimes.includes(file.type);

      if (!isExtAllowed && !isMimeAllowed) {
        setUploadError('Formato no permitido. Solo se admiten archivos PDF, Word, Excel, PowerPoint, TXT y CSV.');
        return;
      }
      if (file.size > 10 * 1024 * 1024) {
        setUploadError('El archivo supera el tamaño máximo permitido (10 MB).');
        return;
      }
    } else {
      const validMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
      if (!validMimes.includes(file.type)) {
        setUploadError('Formato no permitido. Solo se admiten imágenes JPG, PNG, WEBP o GIF.');
        return;
      }
      if (file.size > 5 * 1024 * 1024) {
        setUploadError('El archivo no debe superar los 5 MB.');
        return;
      }
    }

    setUploadError(null);
    setIsUploading(true);

    try {
      let resultUrl = '';
      let resultFileName = file.name;
      let resultMime = file.type;
      let resultSize: number | null = file.size;

      if (onUploadAttachment) {
        const res = await onUploadAttachment(file);
        resultUrl = res.url;
        if (res.file_name) resultFileName = res.file_name;
        if (res.mime_type) resultMime = res.mime_type;
        if (res.size_bytes) resultSize = res.size_bytes;
      } else if (flow?.id) {
        const res = await api.uploadFlowAttachment(flow.id, file);
        const data = (res as any).data?.data || (res as any).data || res;
        resultUrl = data.url || (res as any).url;
        resultFileName = data.file_name || (res as any).file_name || file.name;
        resultMime = data.mime_type || (res as any).mime_type || file.type;
        resultSize = data.size_bytes || (res as any).size_bytes || file.size;
      } else {
        resultUrl = URL.createObjectURL(file);
      }

      onUpdateNodeData(selectedNode.id, {
        configuration: {
          ...config,
          url: resultUrl,
          file_name: resultFileName,
          mime_type: resultMime,
          size_bytes: resultSize,
        },
      });
    } catch (err: any) {
      const status = err?.response?.status;
      if (status === 413) {
        setUploadError('El archivo es demasiado pesado para el servidor. Tamaño máximo: 10 MB.');
      } else {
        const msg = err?.response?.data?.message || err?.message || (nodeType === 'file' ? 'Error al subir el documento.' : 'Error al subir la imagen.');
        setUploadError(msg);
      }
    } finally {
      setIsUploading(false);
    }
  };

  const handleDragOver = (e: React.DragEvent) => {
    e.preventDefault();
    if (!isReadOnly) setIsDragOver(true);
  };

  const handleDragLeave = (e: React.DragEvent) => {
    e.preventDefault();
    setIsDragOver(false);
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setIsDragOver(false);
    if (isReadOnly) return;
    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
      handleFileUpload(e.dataTransfer.files[0]);
    }
  };

  const handleRemoveImage = () => {
    if (isReadOnly) return;
    setPreviewError(false);
    onUpdateNodeData(selectedNode.id, {
      configuration: {
        ...config,
        url: '',
        file_name: '',
        mime_type: '',
      },
    });
    setUploadError(null);
  };

  const handleRemoveFile = () => {
    if (isReadOnly) return;
    onUpdateNodeData(selectedNode.id, {
      configuration: {
        ...config,
        url: '',
        file_name: '',
        mime_type: '',
        size_bytes: null,
      },
    });
    setUploadError(null);
  };

  // Variable validation regex
  const variableRegex = /^[a-zA-Z_][a-zA-Z0-9_.]*$/;
  const isVariableValid = !config.variable || variableRegex.test(config.variable.trim());

  return (
    <aside className={`properties-panel ${isCollapsed ? 'collapsed' : ''}`} data-testid="properties-panel">
      {/* 1. Compact Header with Node Icon + Editable Title */}
      <div className="properties-header">
        <div className="properties-header-main">
          <div
            className="properties-node-icon"
            style={{ backgroundColor: meta.bgColor, color: meta.color }}
            title={meta.label}
          >
            <NodeIcon size={16} />
          </div>
          <div className="properties-header-title-wrap">
            <input
              type="text"
              className="properties-title-input"
              value={data.name || ''}
              onChange={handleNameChange}
              disabled={isReadOnly}
              placeholder={meta.label || 'Nombre del nodo'}
              title="Haz clic para editar el nombre del nodo"
            />
            <div className="properties-header-meta">
              <span
                className={`badge ${
                  nodeType === 'start'
                    ? 'badge-success'
                    : nodeType === 'condition'
                    ? 'badge-warning'
                    : 'badge-info'
                }`}
              >
                {nodeType.toUpperCase()}
              </span>
              <span className="properties-node-key-hint">{selectedNode.id}</span>
            </div>
          </div>
        </div>

        <div className="properties-header-actions">
          {onToggleCollapse && (
            <button
              type="button"
              className="btn btn-secondary btn-sm properties-toggle-btn"
              data-testid="properties-toggle-btn"
              onClick={onToggleCollapse}
              title={isCollapsed ? 'Mostrar panel' : 'Ocultar panel'}
              aria-label={isCollapsed ? 'Mostrar panel' : 'Ocultar panel'}
              style={{ padding: '0.3rem 0.45rem', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}
            >
              {isCollapsed ? <PanelRightOpen size={16} /> : <PanelRightClose size={16} />}
            </button>
          )}

          {onDeselectNode && (
            <button
              type="button"
              className="btn btn-secondary btn-sm properties-close-btn"
              data-testid="properties-close-btn"
              onClick={onDeselectNode}
              title="Cerrar inspector de nodo [Esc]"
              aria-label="Cerrar inspector de nodo [Esc]"
              style={{ padding: '0.3rem 0.45rem', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}
            >
              <X size={16} />
            </button>
          )}
        </div>
      </div>

      <div className="properties-body">
        {/* 1. START NODE */}
        {nodeType === 'start' && (
          <div style={{ background: '#eaf1ee', padding: '0.875rem', borderRadius: '0.5rem', border: '1px solid #a9c8bb', fontSize: '0.8rem', color: '#263c38', lineHeight: '1.5' }}>
            Este es el punto de inicio de la conversación. No requiere configuración adicional. Solo debe tener una salida hacia el primer nodo del flujo.
          </div>
        )}

        {/* 2. MESSAGE NODE */}
        {nodeType === 'message' && (
          <div className="properties-section">
            <div className="properties-section-header">
              <span className="properties-section-title">Contenido</span>
            </div>
            <div className="form-group" style={{ marginBottom: 0 }}>
              <label className="form-label">Mensaje al Usuario *</label>
              <div className="properties-textarea-container overflow-visible">
                <textarea
                  className="form-textarea properties-textarea rounded-t-lg"
                  rows={4}
                  value={config.text || ''}
                  onChange={(e) => handleConfigChange('text', e.target.value)}
                  disabled={isReadOnly}
                  placeholder="Escribe el texto que enviará el bot..."
                />
                <div className="properties-textarea-footer rounded-b-lg overflow-visible">
                  <span className="properties-field-hint">
                    Puedes interpolar con <code>{'{{variable}}'}</code>
                  </span>
                  <VariablePicker
                    variables={flowVariables}
                    onSelectVariable={(v) => insertVariableIntoField('text', v)}
                  />
                </div>
              </div>
            </div>

            {availableVariables.length > 0 && (
              <div className="properties-vars-quickrow">
                <span className="properties-vars-quickrow-label">Variables disponibles:</span>
                <div className="properties-vars-chips">
                  {availableVariables.map((v) => (
                    <button
                      key={v}
                      type="button"
                      className="properties-var-chip"
                      onClick={() => insertVariableIntoField('text', v)}
                      disabled={isReadOnly}
                      title={`Insertar {{${v}}}`}
                    >
                      + {`{{${v}}}`}
                    </button>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        {/* 2.2 IMAGE NODE */}
        {nodeType === 'image' && (
          <div className="properties-section" data-testid="image-properties-section">
            <div className="properties-section-header">
              <span className="properties-section-title">Contenido Multimedia</span>
            </div>

            {/* Previsualización si existe URL */}
            {config.url ? (
              <div
                className="properties-image-preview-card"
                data-testid="properties-image-preview"
                style={{
                  borderRadius: '8px',
                  overflow: 'hidden',
                  border: '1px solid #d9e3de',
                  backgroundColor: '#f4f7f5',
                  marginBottom: '1rem',
                  padding: '6px',
                }}
              >
                <div
                  style={{
                    maxHeight: '180px',
                    width: '100%',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    backgroundColor: '#263c3808',
                    borderRadius: '6px',
                    overflow: 'hidden',
                    padding: previewError ? '1rem' : '0',
                  }}
                >
                  {previewError ? (
                    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '0.35rem', color: '#b91c1c' }}>
                      <ImageOff size={22} color="#ef4444" />
                      <span style={{ fontSize: '0.75rem', fontWeight: 500 }}>No se pudo cargar la imagen</span>
                    </div>
                  ) : (
                    <img
                      src={resolveMediaUrl(config.url)}
                      alt={config.file_name || 'Vista previa'}
                      style={{
                        maxHeight: '180px',
                        maxWidth: '100%',
                        objectFit: 'contain',
                        display: 'block',
                      }}
                      onError={() => setPreviewError(true)}
                    />
                  )}
                </div>
                <div
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    padding: '0.5rem 0.25rem 0.25rem',
                    fontSize: '0.75rem',
                    color: '#61726d',
                  }}
                >
                  <span
                    style={{
                      overflow: 'hidden',
                      textOverflow: 'ellipsis',
                      whiteSpace: 'nowrap',
                      maxWidth: '160px',
                      fontWeight: 500,
                    }}
                    title={config.file_name || config.url}
                  >
                    {config.file_name || 'Imagen cargada'}
                  </span>
                  <div style={{ display: 'flex', gap: '0.35rem' }}>
                    <button
                      type="button"
                      className="btn btn-secondary btn-sm"
                      onClick={() => fileInputRef.current?.click()}
                      disabled={isReadOnly || isUploading}
                      style={{ fontSize: '0.7rem', padding: '0.2rem 0.45rem' }}
                      title="Reemplazar archivo"
                    >
                      Reemplazar
                    </button>
                    <button
                      type="button"
                      className="btn btn-danger btn-sm"
                      onClick={handleRemoveImage}
                      disabled={isReadOnly}
                      style={{ fontSize: '0.7rem', padding: '0.2rem 0.45rem', color: '#ef4444' }}
                      title="Quitar imagen"
                      data-testid="remove-image-btn"
                    >
                      <Trash2 size={11} style={{ display: 'inline', marginRight: '2px' }} /> Quitar
                    </button>
                  </div>
                </div>
              </div>
            ) : null}

            {/* Zona de Carga / Drag & Drop */}
            <div className="form-group">
              <label className="form-label" style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span>Subir Archivo de Imagen</span>
                <span style={{ fontSize: '0.7rem', color: '#80918c', fontWeight: 'normal' }}>Máx. 5 MB</span>
              </label>
              <div
                className={`properties-dropzone ${isDragOver ? 'drag-over' : ''}`}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
                onClick={() => !isUploading && !isReadOnly && fileInputRef.current?.click()}
                data-testid="image-dropzone"
                style={{
                  border: isDragOver ? '2px dashed #5d8f82' : '2px dashed #d9e3de',
                  backgroundColor: isDragOver ? '#ecfeff' : '#f4f7f5',
                  borderRadius: '8px',
                  padding: '1.25rem 0.75rem',
                  textAlign: 'center',
                  cursor: isReadOnly || isUploading ? 'not-allowed' : 'pointer',
                  transition: 'all 0.2s ease',
                }}
              >
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/gif"
                  style={{ display: 'none' }}
                  onChange={(e) => {
                    if (e.target.files && e.target.files[0]) {
                      handleFileUpload(e.target.files[0]);
                    }
                  }}
                  disabled={isReadOnly || isUploading}
                  data-testid="image-file-input"
                />

                {isUploading ? (
                  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '0.5rem', color: '#5d8f82' }}>
                    <Loader2 size={22} className="animate-spin" />
                    <span style={{ fontSize: '0.8rem', fontWeight: 500 }}>Subiendo imagen...</span>
                  </div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '0.35rem', color: '#61726d' }}>
                    <UploadCloud size={24} color="#5d8f82" />
                    <div style={{ fontSize: '0.8rem', fontWeight: 600, color: '#56736b' }}>
                      Arrastra una imagen o <span style={{ color: '#5d8f82', textDecoration: 'underline' }}>haz clic para explorar</span>
                    </div>
                    <div style={{ fontSize: '0.7rem', color: '#80918c' }}>
                      Formatos soportados: JPG, PNG, WEBP, GIF
                    </div>
                  </div>
                )}
              </div>

              {uploadError && (
                <div
                  className="properties-error-notice"
                  data-testid="upload-error"
                  style={{ marginTop: '0.4rem', color: '#dc2626', fontSize: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.3rem' }}
                >
                  <AlertCircle size={13} /> {uploadError}
                </div>
              )}
            </div>

            {/* Campo de URL directa */}
            <div className="form-group" style={{ marginTop: '0.75rem' }}>
              <label className="form-label" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span>URL de la Imagen</span>
                <span style={{ fontSize: '0.7rem', color: '#80918c', fontWeight: 'normal' }}>URL externa o CDN</span>
              </label>
              <input
                type="url"
                className="form-input"
                value={config.url || ''}
                onChange={(e) => handleConfigChange('url', e.target.value)}
                disabled={isReadOnly}
                placeholder="https://dominio.com/imagen.png"
                data-testid="image-url-input"
              />
            </div>

            {/* Texto descriptivo / Pie de foto (Caption) */}
            <div className="form-group" style={{ marginTop: '0.75rem', marginBottom: 0 }}>
              <label className="form-label">Pie de Foto / Descripción (Caption)</label>
              <div className="properties-textarea-container overflow-visible">
                <textarea
                  className="form-textarea properties-textarea rounded-t-lg"
                  rows={3}
                  value={config.caption || ''}
                  onChange={(e) => handleConfigChange('caption', e.target.value)}
                  disabled={isReadOnly}
                  placeholder="Escribe el texto que acompañará a la imagen..."
                  data-testid="image-caption-input"
                />
                <div className="properties-textarea-footer rounded-b-lg overflow-visible">
                  <span className="properties-field-hint">
                    Soporta variables <code>{'{{variable}}'}</code>
                  </span>
                  <VariablePicker
                    variables={flowVariables}
                    onSelectVariable={(v) => insertVariableIntoField('caption', v)}
                  />
                </div>
              </div>
            </div>

            {availableVariables.length > 0 && (
              <div className="properties-vars-quickrow" style={{ marginTop: '0.4rem' }}>
                <span className="properties-vars-quickrow-label">Variables disponibles:</span>
                <div className="properties-vars-chips">
                  {availableVariables.map((v) => (
                    <button
                      key={v}
                      type="button"
                      className="properties-var-chip"
                      onClick={() => insertVariableIntoField('caption', v)}
                      disabled={isReadOnly}
                      title={`Insertar {{${v}}}`}
                    >
                      + {`{{${v}}}`}
                    </button>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        {/* 2.3 FILE NODE */}
        {nodeType === 'file' && (
          <div className="properties-section" data-testid="file-properties-section">
            <div className="properties-section-header">
              <span className="properties-section-title">Contenido de Documento</span>
            </div>

            {/* Previsualización / Tarjeta si existe URL */}
            {config.url ? (
              <div
                className="properties-file-preview-card"
                data-testid="properties-file-preview"
                style={{
                  borderRadius: '8px',
                  border: '1px solid #ccfbf1',
                  backgroundColor: '#f0fdfa',
                  marginBottom: '1rem',
                  padding: '0.75rem',
                }}
              >
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.65rem' }}>
                  <div
                    style={{
                      width: '36px',
                      height: '36px',
                      borderRadius: '6px',
                      backgroundColor: '#ccfbf1',
                      color: '#5d8f82',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      flexShrink: 0,
                    }}
                  >
                    <FileText size={20} />
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div
                      style={{
                        fontWeight: 600,
                        fontSize: '0.8rem',
                        color: '#134e4a',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                        whiteSpace: 'nowrap',
                      }}
                      title={config.file_name || config.url}
                    >
                      {config.file_name || 'documento.pdf'}
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', marginTop: '2px', fontSize: '0.7rem', color: '#0f766e' }}>
                      <span style={{ fontWeight: 600, textTransform: 'uppercase' }}>
                        {config.file_name ? config.file_name.split('.').pop() : 'DOC'}
                      </span>
                      {config.size_bytes ? (
                        <span>
                          • {config.size_bytes < 1048576
                            ? `${(config.size_bytes / 1024).toFixed(1)} KB`
                            : `${(config.size_bytes / 1048576).toFixed(1)} MB`}
                        </span>
                      ) : null}
                    </div>
                  </div>
                </div>

                <div
                  style={{
                    display: 'flex',
                    justifyContent: 'flex-end',
                    gap: '0.4rem',
                    marginTop: '0.6rem',
                    paddingTop: '0.5rem',
                    borderTop: '1px solid #e6fffa',
                  }}
                >
                  <button
                    type="button"
                    className="btn btn-secondary btn-sm"
                    onClick={() => docFileInputRef.current?.click()}
                    disabled={isReadOnly || isUploading}
                    style={{ fontSize: '0.7rem', padding: '0.2rem 0.5rem' }}
                    title="Reemplazar archivo"
                  >
                    Reemplazar
                  </button>
                  <button
                    type="button"
                    className="btn btn-danger btn-sm"
                    onClick={handleRemoveFile}
                    disabled={isReadOnly}
                    style={{ fontSize: '0.7rem', padding: '0.2rem 0.5rem', color: '#ef4444' }}
                    title="Quitar archivo"
                    data-testid="remove-file-btn"
                  >
                    <Trash2 size={11} style={{ display: 'inline', marginRight: '2px' }} /> Quitar
                  </button>
                </div>
              </div>
            ) : null}

            {/* Zona de Carga / Drag & Drop */}
            <div className="form-group">
              <label className="form-label" style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span>Subir Documento</span>
                <span style={{ fontSize: '0.7rem', color: '#80918c', fontWeight: 'normal' }}>Máx. 10 MB</span>
              </label>
              <div
                className={`properties-dropzone ${isDragOver ? 'drag-over' : ''}`}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
                onClick={() => !isUploading && !isReadOnly && docFileInputRef.current?.click()}
                data-testid="file-dropzone"
                style={{
                  border: isDragOver ? '2px dashed #5d8f82' : '2px dashed #d9e3de',
                  backgroundColor: isDragOver ? '#f0fdfa' : '#f4f7f5',
                  borderRadius: '8px',
                  padding: '1.25rem 0.75rem',
                  textAlign: 'center',
                  cursor: isReadOnly || isUploading ? 'not-allowed' : 'pointer',
                  transition: 'all 0.2s ease',
                }}
              >
                <input
                  ref={docFileInputRef}
                  type="file"
                  accept=".pdf,.docx,.doc,.xlsx,.xls,.pptx,.ppt,.txt,.csv,application/pdf,text/plain,text/csv,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation"
                  style={{ display: 'none' }}
                  onChange={(e) => {
                    if (e.target.files && e.target.files[0]) {
                      handleFileUpload(e.target.files[0]);
                    }
                  }}
                  disabled={isReadOnly || isUploading}
                  data-testid="file-file-input"
                />

                {isUploading ? (
                  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '0.5rem', color: '#5d8f82' }}>
                    <Loader2 size={22} className="animate-spin" />
                    <span style={{ fontSize: '0.8rem', fontWeight: 500 }}>Subiendo documento...</span>
                  </div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '0.35rem', color: '#61726d' }}>
                    <UploadCloud size={24} color="#5d8f82" />
                    <div style={{ fontSize: '0.8rem', fontWeight: 600, color: '#56736b' }}>
                      Arrastra un documento o <span style={{ color: '#5d8f82', textDecoration: 'underline' }}>haz clic para explorar</span>
                    </div>
                    <div style={{ fontSize: '0.7rem', color: '#80918c' }}>
                      Formatos soportados: PDF, Word, Excel, PPT, TXT, CSV (máx. 10 MB)
                    </div>
                  </div>
                )}
              </div>

              {uploadError && (
                <div
                  className="properties-error-notice"
                  data-testid="upload-error"
                  style={{ marginTop: '0.4rem', color: '#dc2626', fontSize: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.3rem' }}
                >
                  <AlertCircle size={13} /> {uploadError}
                </div>
              )}
            </div>

            {/* Campo de URL directa */}
            <div className="form-group" style={{ marginTop: '0.75rem' }}>
              <label className="form-label" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span>URL del Documento</span>
                <span style={{ fontSize: '0.7rem', color: '#80918c', fontWeight: 'normal' }}>URL externa, S3 o CDN</span>
              </label>
              <input
                type="url"
                className="form-input"
                value={config.url || ''}
                onChange={(e) => handleConfigChange('url', e.target.value)}
                disabled={isReadOnly}
                placeholder="https://dominio.com/documento.pdf"
                data-testid="file-url-input"
              />
            </div>

            {/* Nombre de Archivo (file_name) */}
            <div className="form-group" style={{ marginTop: '0.75rem' }}>
              <label className="form-label" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span>Nombre del Archivo (file_name)</span>
                <span style={{ fontSize: '0.7rem', color: '#80918c', fontWeight: 'normal' }}>Personalizado para el usuario</span>
              </label>
              <input
                type="text"
                className="form-input"
                value={config.file_name || ''}
                onChange={(e) => handleConfigChange('file_name', e.target.value)}
                disabled={isReadOnly}
                placeholder="Ej. Catalogo_Productos_2026.pdf"
                data-testid="file-name-input"
              />
            </div>

            {/* Pie de Foto / Descripción (Caption) */}
            <div className="form-group" style={{ marginTop: '0.75rem', marginBottom: 0 }}>
              <label className="form-label">Pie de Texto / Mensaje Adjunto (Caption)</label>
              <div className="properties-textarea-container overflow-visible">
                <textarea
                  className="form-textarea properties-textarea rounded-t-lg"
                  rows={3}
                  value={config.caption || ''}
                  onChange={(e) => handleConfigChange('caption', e.target.value)}
                  disabled={isReadOnly}
                  placeholder="Escribe un mensaje que acompañará al documento..."
                  data-testid="file-caption-input"
                />
                <div className="properties-textarea-footer rounded-b-lg overflow-visible">
                  <span className="properties-field-hint">
                    Soporta variables <code>{'{{variable}}'}</code>
                  </span>
                  <VariablePicker
                    variables={flowVariables}
                    onSelectVariable={(v) => insertVariableIntoField('caption', v)}
                  />
                </div>
              </div>
            </div>

            {availableVariables.length > 0 && (
              <div className="properties-vars-quickrow" style={{ marginTop: '0.4rem' }}>
                <span className="properties-vars-quickrow-label">Variables disponibles:</span>
                <div className="properties-vars-chips">
                  {availableVariables.map((v) => (
                    <button
                      key={v}
                      type="button"
                      className="properties-var-chip"
                      onClick={() => insertVariableIntoField('caption', v)}
                      disabled={isReadOnly}
                      title={`Insertar {{${v}}}`}
                    >
                      + {`{{${v}}}`}
                    </button>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        {/* 2.5 MENU NODE */}
        {nodeType === 'menu' && (
          <>
            <div className="properties-section">
              <div className="properties-section-header">
                <span className="properties-section-title">Contenido</span>
              </div>
              <div className="form-group" style={{ marginBottom: 0 }}>
                <label className="form-label">Texto de Introducción / Pregunta *</label>
                <div className="properties-textarea-container overflow-visible">
                  <textarea
                    className="form-textarea properties-textarea rounded-t-lg"
                    rows={3}
                    value={config.text || config.title || ''}
                    onChange={(e) => handleConfigChange('text', e.target.value)}
                    disabled={isReadOnly}
                    placeholder="Ej. Selecciona una de las siguientes opciones:"
                  />
                  <div className="properties-textarea-footer rounded-b-lg overflow-visible">
                    <span className="properties-field-hint">Soporta interpolación</span>
                    <VariablePicker
                      variables={flowVariables}
                      onSelectVariable={(v) => insertVariableIntoField('text', v)}
                    />
                  </div>
                </div>
              </div>
            </div>

            {/* Menu Options Manager */}
            <div className="properties-section">
              <div className="properties-section-header">
                <span className="properties-section-title">
                  Opciones del Menú ({(config.options || []).length}) *
                </span>
              </div>

              <div className="properties-choices-list">
                {(config.options || []).map((opt: any, idx: number) => {
                  const isHidden = opt.is_hidden === true || opt.visible === false;
                  return (
                    <div
                      key={opt.id || idx}
                      className={`properties-choice-card ${isHidden ? 'is-hidden-choice' : ''}`}
                      style={{
                        background: isHidden ? '#eaf1ee' : '#f4f7f5',
                        padding: '0.55rem',
                        borderRadius: '0.5rem',
                        border: `1px solid ${isHidden ? '#d9e3de' : '#d9e3de'}`,
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '0.4rem',
                        transition: 'all 0.15s ease',
                        opacity: isHidden ? 0.88 : 1,
                      }}
                    >
                      <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                        <div className="properties-choice-drag" title="Arrastrar para reordenar">
                          <GripVertical size={14} />
                        </div>
                        <span
                          style={{
                            width: '18px',
                            height: '18px',
                            borderRadius: '50%',
                            background: isHidden ? '#80918c' : '#0284c7',
                            color: '#ffffff',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            fontSize: '0.65rem',
                            fontWeight: 700,
                            flexShrink: 0,
                          }}
                        >
                          {idx + 1}
                        </span>
                        <input
                          type="text"
                          className="form-input properties-choice-input flex-1 min-w-[120px]"
                          value={opt.text || ''}
                          disabled={isReadOnly}
                          placeholder="Texto visible de la opción"
                          style={{
                            flex: 1,
                            minWidth: '120px',
                            color: isHidden ? '#61726d' : undefined,
                          }}
                          onChange={(e) => {
                            const updated = [...(config.options || [])];
                            updated[idx] = { ...updated[idx], text: e.target.value };
                            handleConfigChange('options', updated);
                          }}
                        />
                        {isHidden && (
                          <span
                            className="badge-hidden-option"
                            data-testid={`badge-hidden-${idx}`}
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '0.2rem',
                              fontSize: '0.65rem',
                              fontWeight: 600,
                              padding: '0.1rem 0.35rem',
                              borderRadius: '4px',
                              background: '#d9e3de',
                              color: '#61726d',
                              flexShrink: 0,
                            }}
                            title="Oculta en chat (mantiene conexión en lienzo)"
                          >
                            <EyeOff size={10} />
                            Oculta
                          </span>
                        )}
                        {!isReadOnly && (
                          <div className="properties-choice-actions" style={{ display: 'flex', alignItems: 'center', gap: '0.15rem' }}>
                            <button
                              type="button"
                              className={`properties-visibility-btn ${isHidden ? 'is-hidden' : ''}`}
                              data-testid={`toggle-visibility-${idx}`}
                              onClick={() => {
                                const updated = [...(config.options || [])];
                                updated[idx] = {
                                  ...updated[idx],
                                  is_hidden: !isHidden,
                                };
                                handleConfigChange('options', updated);
                              }}
                              title={isHidden ? 'Mostrar en chat' : 'Ocultar en chat (mantiene conexión en lienzo)'}
                              aria-label={isHidden ? 'Mostrar en chat' : 'Ocultar en chat (mantiene conexión en lienzo)'}
                              style={{
                                background: isHidden ? '#d9e3de' : 'transparent',
                                border: 'none',
                                padding: '0.2rem 0.3rem',
                                borderRadius: '4px',
                                cursor: 'pointer',
                                color: isHidden ? '#61726d' : '#0284c7',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                              }}
                            >
                              {isHidden ? <EyeOff size={13} /> : <Eye size={13} />}
                            </button>
                            <button
                              type="button"
                              className={`properties-agent-toggle-btn ${opt.hide_if_no_agents ? 'is-agent-only' : ''}`}
                              data-testid={`option-agent-toggle-${idx}`}
                              onClick={() => {
                                const updated = [...(config.options || [])];
                                updated[idx] = {
                                  ...updated[idx],
                                  hide_if_no_agents: !opt.hide_if_no_agents,
                                };
                                handleConfigChange('options', updated);
                              }}
                              title={
                                opt.hide_if_no_agents
                                  ? 'Visible solo si hay agentes en línea (activo)'
                                  : 'Ocultar si no hay agentes conectados'
                              }
                              aria-label={
                                opt.hide_if_no_agents
                                  ? 'Visible solo si hay agentes en línea (activo)'
                                  : 'Ocultar si no hay agentes conectados'
                              }
                              style={{
                                background: opt.hide_if_no_agents ? '#e0f2fe' : 'transparent',
                                border: 'none',
                                padding: '0.2rem 0.3rem',
                                borderRadius: '4px',
                                cursor: 'pointer',
                                color: opt.hide_if_no_agents ? '#0284c7' : '#80918c',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                              }}
                            >
                              <Headset size={13} />
                            </button>
                            <button
                              type="button"
                              className="properties-reorder-btn"
                              disabled={idx === 0}
                              onClick={() => {
                                const updated = [...(config.options || [])];
                                const temp = updated[idx - 1];
                                updated[idx - 1] = updated[idx];
                                updated[idx] = temp;
                                handleConfigChange('options', updated);
                              }}
                              title="Subir opción"
                            >
                              <ArrowUp size={12} />
                            </button>
                            <button
                              type="button"
                              className="properties-reorder-btn"
                              disabled={idx === (config.options?.length || 0) - 1}
                              onClick={() => {
                                const updated = [...(config.options || [])];
                                const temp = updated[idx + 1];
                                updated[idx + 1] = updated[idx];
                                updated[idx] = temp;
                                handleConfigChange('options', updated);
                              }}
                              title="Bajar opción"
                            >
                              <ArrowDown size={12} />
                            </button>
                            <button
                              type="button"
                              className="properties-choice-delete-btn"
                              onClick={() => {
                                const updated = (config.options || []).filter((_: any, i: number) => i !== idx);
                                handleConfigChange('options', updated);
                              }}
                              title="Eliminar opción"
                            >
                              <X size={13} />
                            </button>
                          </div>
                        )}
                      </div>

                      {/* Destination selection & secondary indicators */}
                      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '0.4rem', fontSize: '0.72rem', paddingLeft: '1.75rem', flexWrap: 'wrap' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', flex: 1, minWidth: '160px' }}>
                          <span style={{ color: 'var(--text-muted)', flexShrink: 0 }}>Destino:</span>
                          <select
                            className="form-select"
                            style={{ padding: '0.2rem 0.4rem', fontSize: '0.72rem', borderRadius: '4px', flex: 1 }}
                            value={opt.target_node_key || ''}
                            disabled={isReadOnly}
                            onChange={(e) => {
                              const updated = [...(config.options || [])];
                              updated[idx] = { ...updated[idx], target_node_key: e.target.value || undefined };
                              handleConfigChange('options', updated);
                            }}
                          >
                            <option value="">(Usar conexión del lienzo)</option>
                            {allNodes
                              .filter((n) => n.node_key !== selectedNode.id)
                              .map((n) => (
                                <option key={n.node_key} value={n.node_key}>
                                  {n.name} ({n.node_key})
                                </option>
                              ))}
                          </select>
                        </div>
                        {opt.hide_if_no_agents && (
                          <span
                            className="badge-agent-option"
                            data-testid={`badge-agent-${idx}`}
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '0.2rem',
                              fontSize: '0.65rem',
                              fontWeight: 600,
                              padding: '0.1rem 0.35rem',
                              borderRadius: '4px',
                              background: '#e0f2fe',
                              color: '#0369a1',
                              flexShrink: 0,
                            }}
                            title="Visible solo si hay agentes en línea"
                          >
                            <Headset size={10} />
                            Solo con agentes en línea
                          </span>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>

              {!isReadOnly && (
                <div className="properties-add-choice-row">
                  <input
                    type="text"
                    className="form-input properties-add-choice-input"
                    placeholder="Nueva opción de menú..."
                    value={newMenuOptionText}
                    onChange={(e) => setNewMenuOptionText(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        if (newMenuOptionText.trim()) {
                          const newId = `opt_${Date.now()}_${Math.random().toString(36).substr(2, 4)}`;
                          const updated = [
                            ...(config.options || []),
                            { id: newId, text: newMenuOptionText.trim(), is_hidden: false },
                          ];
                          handleConfigChange('options', updated);
                          setNewMenuOptionText('');
                        }
                      }
                    }}
                  />
                  <button
                    type="button"
                    className="btn btn-secondary btn-sm properties-add-btn"
                    onClick={() => {
                      if (newMenuOptionText.trim()) {
                        const newId = `opt_${Date.now()}_${Math.random().toString(36).substr(2, 4)}`;
                        const updated = [
                          ...(config.options || []),
                          { id: newId, text: newMenuOptionText.trim(), is_hidden: false },
                        ];
                        handleConfigChange('options', updated);
                        setNewMenuOptionText('');
                      }
                    }}
                  >
                    <Plus size={14} /> Añadir
                  </button>
                </div>
              )}
            </div>

            {/* Storage / Variable Collapsible */}
            <div className={`properties-collapsible-card ${isAdvancedOpen ? 'is-expanded' : ''}`}>
              <button
                type="button"
                className="properties-collapsible-toggle"
                onClick={() => setIsAdvancedOpen(!isAdvancedOpen)}
                aria-expanded={isAdvancedOpen}
              >
                <div className="properties-collapsible-heading">
                  <SlidersHorizontal size={14} className="properties-collapsible-icon" />
                  <span className="properties-collapsible-label">Almacenamiento y Avanzado</span>
                  {config.variable && (
                    <span className="properties-variable-preview" title="Variable vinculada">
                      {`{{${config.variable}}}`}
                    </span>
                  )}
                </div>
                <ChevronDown
                  size={15}
                  className={`properties-chevron ${isAdvancedOpen ? 'rotated' : ''}`}
                />
              </button>

              <div
                className="properties-collapsible-body"
                style={{ display: isAdvancedOpen ? 'flex' : 'none' }}
              >
                <div className="form-group" style={{ marginBottom: 0 }}>
                  <label className="form-label">Guardar Selección en Variable (Opcional)</label>
                  <input
                    type="text"
                    className="form-input"
                    value={config.variable || ''}
                    onChange={(e) => handleConfigChange('variable', e.target.value.trim())}
                    disabled={isReadOnly}
                    placeholder="Ej. opcion_seleccionada"
                  />
                  <span className="properties-field-hint">
                    Guarda el texto o valor de la opción elegida por el usuario.
                  </span>
                </div>
              </div>
            </div>

            {/* Multichannel Preview */}
            <MultichannelPreview
              title={config.text || config.title || 'Selecciona una opción:'}
              options={(config.options || []).filter((opt: any) => !opt.is_hidden && opt.visible !== false)}
            />
          </>
        )}

        {/* 3. QUESTION NODE */}
        {nodeType === 'question' && (
          <>
            {/* A. Bloque Contenido / Mensaje */}
            <div className="properties-section">
              <div className="properties-section-header">
                <span className="properties-section-title">Contenido</span>
              </div>
              <div className="form-group" style={{ marginBottom: 0 }}>
                <label className="form-label">Pregunta al Usuario *</label>
                <div className="properties-textarea-container overflow-visible">
                  <textarea
                    className="form-textarea properties-textarea rounded-t-lg"
                    rows={3}
                    value={config.text || ''}
                    onChange={(e) => handleConfigChange('text', e.target.value)}
                    disabled={isReadOnly}
                    placeholder="Ej. ¿Cuál es tu nombre completo?"
                  />
                  <div className="properties-textarea-footer rounded-b-lg overflow-visible">
                    <span className="properties-field-hint">Soporta interpolación</span>
                    <VariablePicker
                      variables={flowVariables}
                      onSelectVariable={(v) => insertVariableIntoField('text', v)}
                    />
                  </div>
                </div>
              </div>

              {availableVariables.length > 0 && (
                <div className="properties-vars-quickrow">
                  <span className="properties-vars-quickrow-label">Variables disponibles:</span>
                  <div className="properties-vars-chips">
                    {availableVariables.map((v) => (
                      <button
                        key={v}
                        type="button"
                        className="properties-var-chip"
                        onClick={() => insertVariableIntoField('text', v)}
                        disabled={isReadOnly}
                        title={`Insertar {{${v}}}`}
                      >
                        + {`{{${v}}}`}
                      </button>
                    ))}
                  </div>
                </div>
              )}
            </div>

            {/* B. Bloque Opciones Interactivas / Tipo de Respuesta */}
            <div className="properties-section">
              <div className="properties-section-header">
                <span className="properties-section-title">Respuesta Esperada</span>
              </div>

              <div className="form-group" style={{ marginBottom: config.input_type === 'choice' ? '0.75rem' : 0 }}>
                <label className="form-label">Tipo de Respuesta Esperada</label>
                <select
                  className="form-select"
                  value={config.input_type || 'text'}
                  onChange={(e) => handleConfigChange('input_type', e.target.value)}
                  disabled={isReadOnly}
                >
                  <option value="text">Texto Libre (String)</option>
                  <option value="number">Número</option>
                  <option value="email">Correo Electrónico (Email)</option>
                  <option value="country">País (Country)</option>
                  <option value="choice">Opciones Múltiples (Choice)</option>
                </select>
              </div>

              {config.input_type === 'choice' && (
                <div className="properties-choices-container">
                  <div className="properties-choices-header">
                    <label className="form-label" style={{ margin: 0 }}>
                      Opciones Disponibles *
                    </label>
                  </div>

                  <div className="properties-choices-list">
                    {(config.choices || []).map((choice: string, idx: number) => (
                      <div key={idx} className="properties-choice-row">
                        <div className="properties-choice-drag" title="Arrastrar para reordenar">
                          <GripVertical size={14} />
                        </div>
                        <span className="properties-choice-idx">{idx + 1}.</span>
                        <input
                          type="text"
                          className="form-input properties-choice-input"
                          value={choice}
                          disabled={isReadOnly}
                          onChange={(e) => {
                            const updated = [...(config.choices || [])];
                            updated[idx] = e.target.value;
                            handleConfigChange('choices', updated);
                          }}
                        />
                        {!isReadOnly && (
                          <div className="properties-choice-actions">
                            <button
                              type="button"
                              className="properties-reorder-btn"
                              disabled={idx === 0}
                              onClick={() => {
                                const updated = [...(config.choices || [])];
                                const temp = updated[idx - 1];
                                updated[idx - 1] = updated[idx];
                                updated[idx] = temp;
                                handleConfigChange('choices', updated);
                              }}
                              title="Subir opción"
                            >
                              <ArrowUp size={12} />
                            </button>
                            <button
                              type="button"
                              className="properties-reorder-btn"
                              disabled={idx === (config.choices?.length || 0) - 1}
                              onClick={() => {
                                const updated = [...(config.choices || [])];
                                const temp = updated[idx + 1];
                                updated[idx + 1] = updated[idx];
                                updated[idx] = temp;
                                handleConfigChange('choices', updated);
                              }}
                              title="Bajar opción"
                            >
                              <ArrowDown size={12} />
                            </button>
                            <button
                              type="button"
                              className="properties-choice-delete-btn"
                              onClick={() => {
                                const updated = (config.choices || []).filter((_: any, i: number) => i !== idx);
                                handleConfigChange('choices', updated);
                              }}
                              title="Eliminar opción"
                            >
                              <X size={13} />
                            </button>
                          </div>
                        )}
                      </div>
                    ))}
                  </div>

                  {!isReadOnly && (
                    <div className="properties-add-choice-row">
                      <input
                        type="text"
                        className="form-input properties-add-choice-input"
                        placeholder="Nueva opción (ej. Ventas)"
                        value={newChoiceText}
                        onChange={(e) => setNewChoiceText(e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') {
                            e.preventDefault();
                            if (newChoiceText.trim()) {
                              const updated = [...(config.choices || []), newChoiceText.trim()];
                              handleConfigChange('choices', updated);
                              setNewChoiceText('');
                            }
                          }
                        }}
                      />
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm properties-add-btn"
                        onClick={() => {
                          if (newChoiceText.trim()) {
                            const updated = [...(config.choices || []), newChoiceText.trim()];
                            handleConfigChange('choices', updated);
                            setNewChoiceText('');
                          }
                        }}
                      >
                        <Plus size={14} /> Añadir
                      </button>
                    </div>
                  )}
                </div>
              )}
            </div>

            {/* C. Bloque Almacenamiento y Avanzado (Colapsable) */}
            <div className={`properties-collapsible-card ${isAdvancedOpen ? 'is-expanded' : ''}`}>
              <button
                type="button"
                className="properties-collapsible-toggle"
                onClick={() => setIsAdvancedOpen(!isAdvancedOpen)}
                aria-expanded={isAdvancedOpen}
              >
                <div className="properties-collapsible-heading">
                  <SlidersHorizontal size={14} className="properties-collapsible-icon" />
                  <span className="properties-collapsible-label">Almacenamiento y Avanzado</span>
                  {config.variable && (
                    <span className="properties-variable-preview" title="Variable vinculada">
                      {`{{${config.variable}}}`}
                    </span>
                  )}
                </div>
                <ChevronDown
                  size={15}
                  className={`properties-chevron ${isAdvancedOpen ? 'rotated' : ''}`}
                />
              </button>

              <div
                className="properties-collapsible-body"
                style={{ display: isAdvancedOpen ? 'flex' : 'none' }}
              >
                <div className="form-group">
                  <label className="form-label">Nombre de Variable donde se guarda *</label>
                  <input
                    type="text"
                    className="form-input"
                    value={config.variable || ''}
                    onChange={(e) => handleConfigChange('variable', e.target.value.trim())}
                    disabled={isReadOnly}
                    placeholder="Ej. nombre_cliente"
                  />
                  {!isVariableValid && (
                    <div className="properties-field-error">
                      <AlertCircle size={14} /> Debe iniciar con letra o guión bajo y no tener espacios.
                    </div>
                  )}
                  <span className="properties-field-hint">
                    Variable del flujo donde se almacenará el valor capturado.
                  </span>
                </div>

                <div className="form-group">
                  <label className="form-label">Guardar respuesta también en contacto</label>
                  <select
                    className="form-select"
                    value={config.contact_field || config.sync_contact_field || 'none'}
                    onChange={(e) => {
                      const val = e.target.value;
                      onUpdateNodeData(selectedNode.id, {
                        configuration: {
                          ...config,
                          contact_field: val,
                          sync_contact_field: val,
                        },
                      });
                    }}
                    disabled={isReadOnly}
                  >
                    <option value="none">No guardar</option>
                    <option value="display_name">Nombre</option>
                    <option value="email">Email</option>
                    <option value="phone">Teléfono</option>
                    <option value="country">País</option>
                  </select>
                  <span className="properties-field-hint">
                    Sincroniza automáticamente la respuesta con el perfil del usuario.
                  </span>
                </div>

                <div className="form-group" style={{ marginBottom: 0 }}>
                  <label className="form-label">Mensaje para Respuesta Inválida (Opcional)</label>
                  <input
                    type="text"
                    className="form-input"
                    value={config.invalid_message || ''}
                    onChange={(e) => handleConfigChange('invalid_message', e.target.value)}
                    disabled={isReadOnly}
                    placeholder="Por favor selecciona una opción válida."
                  />
                  <span className="properties-field-hint">
                    Mensaje amigable mostrado si la respuesta no cumple con el formato requerido.
                  </span>
                </div>
              </div>
            </div>
          </>
        )}

        {/* 4. CONDITION NODE */}
        {nodeType === 'condition' && (
          <div className="properties-section">
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.25rem' }}>
              <span className="properties-section-title">Reglas de Decisión</span>
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                style={{ fontSize: '0.72rem', padding: '0.2rem 0.5rem', borderRadius: '4px' }}
                disabled={isReadOnly}
                onClick={() => {
                  const hasRules = Array.isArray(config.rules) && config.rules.length > 0;
                  if (hasRules) {
                    handleConfigChange('rules', undefined);
                  } else {
                    handleConfigChange('rules', [
                      {
                        variable: config.variable || '',
                        operator: config.operator || 'equals',
                        value: config.value || '',
                        compare_type: 'static',
                      },
                    ]);
                    handleConfigChange('logic', 'and');
                  }
                }}
              >
                {Array.isArray(config.rules) && config.rules.length > 0 ? 'Modo simple' : 'Modo avanzado (multi-regla)'}
              </button>
            </div>

            {Array.isArray(config.rules) && config.rules.length > 0 ? (
              /* Multi-rule Builder */
              <div>
                <div className="form-group">
                  <label className="form-label">Lógica de evaluación</label>
                  <select
                    className="form-select"
                    value={config.logic || 'and'}
                    onChange={(e) => handleConfigChange('logic', e.target.value)}
                    disabled={isReadOnly}
                  >
                    <option value="and">Cumplir TODAS las reglas (AND)</option>
                    <option value="or">Cumplir AL MENOS UNA regla (OR)</option>
                  </select>
                </div>

                <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem', marginBottom: '0.75rem' }}>
                  {config.rules.map((rule: any, idx: number) => {
                    const isBool = isRuleBoolean(rule, allNodes, flowVariables, availableFlowVars);
                    const qOptions = !isBool ? getVariableOptions(rule.variable, availableFlowVars, allNodes) : undefined;
                    const operatorsList = isBool ? BOOLEAN_CONDITION_OPERATORS : CONDITION_OPERATORS;
                    const ruleVal = rule.value === false || String(rule.value).toUpperCase() === 'FALSE' ? 'FALSE' : 'TRUE';
                    const isManualChoice = !!customChoiceMode[`rule-${idx}`];
                    const isCustomValue = rule.value !== undefined && rule.value !== '' && qOptions && !qOptions.includes(String(rule.value));

                    return (
                      <div
                        key={idx}
                        style={{
                          background: '#f4f7f5',
                          border: isBool ? '1px solid #c7d2fe' : '1px solid #d9e3de',
                          borderRadius: '0.5rem',
                          padding: '0.6rem',
                          display: 'flex',
                          flexDirection: 'column',
                          gap: '0.35rem',
                        }}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                            <span style={{ fontSize: '0.7rem', fontWeight: 700, color: '#61726d' }}>
                              Regla #{idx + 1}
                            </span>
                            {isBool && (
                              <span
                                className="badge"
                                style={{
                                  fontSize: '0.62rem',
                                  padding: '0.1rem 0.35rem',
                                  backgroundColor: '#eaf1ee',
                                  color: '#263c38',
                                  fontWeight: 600,
                                }}
                              >
                                Booleano
                              </span>
                            )}
                          </div>

                          <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                            {/* Discrete Quick Type Selector (Fallback manual) */}
                            <div style={{ display: 'inline-flex', background: '#d9e3de', borderRadius: '4px', padding: '1px' }}>
                              <button
                                type="button"
                                title="Evaluar como Texto"
                                style={{
                                  border: 'none',
                                  background: !isBool ? '#ffffff' : 'transparent',
                                  color: !isBool ? '#263c38' : '#61726d',
                                  fontWeight: !isBool ? 600 : 400,
                                  fontSize: '0.65rem',
                                  padding: '0.1rem 0.35rem',
                                  borderRadius: '3px',
                                  cursor: 'pointer',
                                }}
                                onClick={() => {
                                  const updated = [...config.rules];
                                  updated[idx] = { ...updated[idx], data_type: 'text' };
                                  handleConfigChange('rules', updated);
                                }}
                              >
                                Texto
                              </button>
                              <button
                                type="button"
                                title="Evaluar como Booleano (TRUE/FALSE)"
                                style={{
                                  border: 'none',
                                  background: isBool ? '#456c61' : 'transparent',
                                  color: isBool ? '#ffffff' : '#61726d',
                                  fontWeight: isBool ? 600 : 400,
                                  fontSize: '0.65rem',
                                  padding: '0.1rem 0.35rem',
                                  borderRadius: '3px',
                                  cursor: 'pointer',
                                }}
                                onClick={() => {
                                  const updated = [...config.rules];
                                  const curVal = updated[idx].value;
                                  const curValUpper = typeof curVal === 'string' ? curVal.toUpperCase() : '';
                                  const nextVal = curVal === false || curValUpper === 'FALSE' ? 'FALSE' : 'TRUE';
                                  const op = updated[idx].operator;
                                  const nextOp = op === 'not_equals' ? 'not_equals' : 'equals';
                                  updated[idx] = {
                                    ...updated[idx],
                                    data_type: 'boolean',
                                    value: nextVal,
                                    operator: nextOp,
                                  };
                                  handleConfigChange('rules', updated);
                                }}
                              >
                                Booleano
                              </button>
                            </div>

                            {!isReadOnly && config.rules.length > 1 && (
                              <button
                                type="button"
                                className="btn btn-danger btn-sm"
                                style={{ padding: '0.1rem 0.3rem' }}
                                onClick={() => {
                                  const updated = config.rules.filter((_: any, i: number) => i !== idx);
                                  handleConfigChange('rules', updated);
                                }}
                              >
                                <X size={12} />
                              </button>
                            )}
                          </div>
                        </div>

                        <VariableAutocompleteInput
                          id={`rule-${idx}`}
                          value={rule.variable || ''}
                          disabled={isReadOnly}
                          testId={`rule-variable-input-${idx}`}
                          placeholder="Variable (ej. estado)"
                          availableVars={availableFlowVars}
                          onSelectVariable={(v) => {
                            const updated = [...config.rules];
                            const isVarBool = v.type === 'boolean';
                            if (isVarBool) {
                              const curVal = updated[idx].value;
                              const curValUpper = typeof curVal === 'string' ? curVal.toUpperCase() : '';
                              const nextVal = curVal === false || curValUpper === 'FALSE' ? 'FALSE' : 'TRUE';
                              const op = updated[idx].operator;
                              const nextOp = op === 'not_equals' ? 'not_equals' : 'equals';
                              updated[idx] = {
                                ...updated[idx],
                                variable: v.name,
                                data_type: 'boolean',
                                value: nextVal,
                                operator: nextOp,
                              };
                            } else {
                              const qOpts = v.options || getVariableOptions(v.name, availableFlowVars, allNodes);
                              const nextVal = (!updated[idx].value && qOpts && qOpts.length > 0) ? qOpts[0] : updated[idx].value;
                              updated[idx] = {
                                ...updated[idx],
                                variable: v.name,
                                data_type: v.type === 'number' ? 'number' : 'text',
                                ...(nextVal !== undefined ? { value: nextVal } : {}),
                              };
                            }
                            handleConfigChange('rules', updated);
                          }}
                          onChangeValue={(newVar) => {
                            const updated = [...config.rules];
                            const nextRule = { ...updated[idx], variable: newVar };
                            if (isRuleBoolean(nextRule, allNodes, flowVariables, availableFlowVars) && (nextRule.value === undefined || nextRule.value === '')) {
                              nextRule.value = 'TRUE';
                            } else {
                              const qOpts = getVariableOptions(newVar, availableFlowVars, allNodes);
                              if (qOpts && qOpts.length > 0 && (nextRule.value === undefined || nextRule.value === '')) {
                                nextRule.value = qOpts[0];
                              }
                            }
                            updated[idx] = nextRule;
                            handleConfigChange('rules', updated);
                          }}
                        />

                        <select
                          className="form-select"
                          style={{ fontSize: '0.75rem', padding: '0.25rem 0.4rem' }}
                          value={rule.operator || 'equals'}
                          disabled={isReadOnly}
                          onChange={(e) => {
                            const updated = [...config.rules];
                            updated[idx] = { ...updated[idx], operator: e.target.value };
                            handleConfigChange('rules', updated);
                          }}
                        >
                          {operatorsList.map((op) => (
                            <option key={op.value} value={op.value}>
                              {op.label}
                            </option>
                          ))}
                        </select>

                        {rule.operator !== 'exists' && rule.operator !== 'not_exists' && rule.operator !== 'is_empty' && rule.operator !== 'not_empty' && (
                          <div style={{ display: 'flex', gap: '0.3rem' }}>
                            {isBool ? (
                              <select
                                className="form-select"
                                style={{ fontSize: '0.75rem', padding: '0.25rem 0.4rem', flex: 1 }}
                                data-testid={`rule-boolean-value-select-${idx}`}
                                aria-label="Valor de regla booleana"
                                value={ruleVal}
                                disabled={isReadOnly}
                                onChange={(e) => {
                                  const updated = [...config.rules];
                                  updated[idx] = { ...updated[idx], value: e.target.value };
                                  handleConfigChange('rules', updated);
                                }}
                              >
                                <option value="TRUE">TRUE</option>
                                <option value="FALSE">FALSE</option>
                              </select>
                            ) : qOptions && qOptions.length > 0 && !isManualChoice && !isCustomValue ? (
                              <div style={{ display: 'flex', gap: '0.3rem', flex: 1 }}>
                                <select
                                  className="form-select"
                                  style={{ fontSize: '0.75rem', padding: '0.25rem 0.4rem', flex: 1 }}
                                  data-testid={`rule-choice-value-select-${idx}`}
                                  aria-label="Valor de opción de pregunta"
                                  value={rule.value !== undefined && rule.value !== '' ? String(rule.value) : qOptions[0]}
                                  disabled={isReadOnly}
                                  onChange={(e) => {
                                    if (e.target.value === '__custom__') {
                                      setCustomChoiceMode((prev) => ({ ...prev, [`rule-${idx}`]: true }));
                                    } else {
                                      const updated = [...config.rules];
                                      updated[idx] = { ...updated[idx], value: e.target.value };
                                      handleConfigChange('rules', updated);
                                    }
                                  }}
                                >
                                  {qOptions.map((opt) => (
                                    <option key={opt} value={opt}>
                                      {opt}
                                    </option>
                                  ))}
                                  <option value="__custom__">── [Ingresar otro valor...] ──</option>
                                </select>
                                <button
                                  type="button"
                                  className="btn btn-ghost btn-sm"
                                  style={{ fontSize: '0.7rem', padding: '0.2rem 0.35rem', color: '#61726d' }}
                                  title="Escribir valor manual"
                                  onClick={() => {
                                    setCustomChoiceMode((prev) => ({ ...prev, [`rule-${idx}`]: true }));
                                  }}
                                >
                                  Manual
                                </button>
                              </div>
                            ) : (
                              <div style={{ display: 'flex', gap: '0.3rem', flex: 1 }}>
                                <input
                                  type="text"
                                  className="form-input"
                                  style={{ fontSize: '0.75rem', padding: '0.25rem 0.4rem', flex: 1 }}
                                  data-testid={`rule-value-input-${idx}`}
                                  placeholder="Valor esperado"
                                  value={rule.value !== undefined ? String(rule.value) : ''}
                                  disabled={isReadOnly}
                                  onChange={(e) => {
                                    const updated = [...config.rules];
                                    updated[idx] = { ...updated[idx], value: e.target.value };
                                    handleConfigChange('rules', updated);
                                  }}
                                />
                                {qOptions && qOptions.length > 0 && (
                                  <button
                                    type="button"
                                    className="btn btn-secondary btn-sm"
                                    style={{ fontSize: '0.7rem', padding: '0.2rem 0.4rem', whiteSpace: 'nowrap' }}
                                    title="Volver a opciones de la pregunta"
                                    onClick={() => {
                                      setCustomChoiceMode((prev) => ({ ...prev, [`rule-${idx}`]: false }));
                                      const updated = [...config.rules];
                                      updated[idx] = { ...updated[idx], value: qOptions[0] };
                                      handleConfigChange('rules', updated);
                                    }}
                                  >
                                    Opciones
                                  </button>
                                )}
                              </div>
                            )}
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>

                {!isReadOnly && (
                  <button
                    type="button"
                    className="btn btn-secondary btn-sm"
                    style={{ width: '100%', marginBottom: '1rem', borderRadius: '0.5rem' }}
                    onClick={() => {
                      const updated = [
                        ...config.rules,
                        { variable: '', operator: 'equals', value: '', compare_type: 'static' },
                      ];
                      handleConfigChange('rules', updated);
                    }}
                  >
                    <Plus size={14} /> Añadir regla
                  </button>
                )}
              </div>
            ) : (
              /* Single-rule builder */
              <div>
                {(() => {
                  const isBool = isRuleBoolean(config, allNodes, flowVariables, availableFlowVars);
                  const qOptions = !isBool ? getVariableOptions(config.variable, availableFlowVars, allNodes) : undefined;
                  const operatorsList = isBool ? BOOLEAN_CONDITION_OPERATORS : CONDITION_OPERATORS;
                  const configVal = config.value === false || String(config.value).toUpperCase() === 'FALSE' ? 'FALSE' : 'TRUE';
                  const isManualChoice = !!customChoiceMode['single-rule'];
                  const isCustomValue = config.value !== undefined && config.value !== '' && qOptions && !qOptions.includes(String(config.value));

                  return (
                    <>
                      <div className="form-group">
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.25rem' }}>
                          <label className="form-label" style={{ margin: 0 }}>Variable a Evaluar *</label>
                          {/* Discrete Quick Type Selector (Fallback manual) */}
                          <div style={{ display: 'inline-flex', background: '#d9e3de', borderRadius: '4px', padding: '1px' }}>
                            <button
                              type="button"
                              title="Evaluar como Texto"
                              style={{
                                border: 'none',
                                background: !isBool ? '#ffffff' : 'transparent',
                                color: !isBool ? '#263c38' : '#61726d',
                                fontWeight: !isBool ? 600 : 400,
                                fontSize: '0.65rem',
                                padding: '0.1rem 0.35rem',
                                borderRadius: '3px',
                                cursor: 'pointer',
                              }}
                              onClick={() => {
                                handleConfigChange('data_type', 'text');
                              }}
                            >
                              Texto
                            </button>
                            <button
                              type="button"
                              title="Evaluar como Booleano (TRUE/FALSE)"
                              style={{
                                border: 'none',
                                background: isBool ? '#456c61' : 'transparent',
                                color: isBool ? '#ffffff' : '#61726d',
                                fontWeight: isBool ? 600 : 400,
                                fontSize: '0.65rem',
                                padding: '0.1rem 0.35rem',
                                borderRadius: '3px',
                                cursor: 'pointer',
                              }}
                              onClick={() => {
                                const curVal = config.value;
                                const curValUpper = typeof curVal === 'string' ? curVal.toUpperCase() : '';
                                const nextVal = curVal === false || curValUpper === 'FALSE' ? 'FALSE' : 'TRUE';
                                const op = config.operator;
                                const nextOp = op === 'not_equals' ? 'not_equals' : 'equals';
                                onUpdateNodeData(selectedNode.id, {
                                  configuration: {
                                    ...config,
                                    data_type: 'boolean',
                                    value: nextVal,
                                    operator: nextOp,
                                  },
                                });
                              }}
                            >
                              Booleano
                            </button>
                          </div>
                        </div>

                        <VariableAutocompleteInput
                          id="single-rule"
                          value={config.variable || ''}
                          disabled={isReadOnly}
                          testId="condition-variable-input"
                          placeholder="Ej. estadoPedido, MaratonicaMode"
                          availableVars={availableFlowVars}
                          onSelectVariable={(v) => {
                            const isVarBool = v.type === 'boolean';
                            if (isVarBool) {
                              const curVal = config.value;
                              const curValUpper = typeof curVal === 'string' ? curVal.toUpperCase() : '';
                              const nextVal = curVal === false || curValUpper === 'FALSE' ? 'FALSE' : 'TRUE';
                              const op = config.operator;
                              const nextOp = op === 'not_equals' ? 'not_equals' : 'equals';
                              onUpdateNodeData(selectedNode.id, {
                                configuration: {
                                  ...config,
                                  variable: v.name,
                                  data_type: 'boolean',
                                  value: nextVal,
                                  operator: nextOp,
                                },
                              });
                            } else {
                              const qOpts = v.options || getVariableOptions(v.name, availableFlowVars, allNodes);
                              const nextVal = (!config.value && qOpts && qOpts.length > 0) ? qOpts[0] : config.value;
                              onUpdateNodeData(selectedNode.id, {
                                configuration: {
                                  ...config,
                                  variable: v.name,
                                  data_type: v.type === 'number' ? 'number' : 'text',
                                  ...(nextVal !== undefined ? { value: nextVal } : {}),
                                },
                              });
                            }
                          }}
                          onChangeValue={(newVar) => {
                            const trimmed = newVar.trim();
                            const nextConfig: Record<string, any> = { ...config, variable: trimmed };
                            if (isRuleBoolean(nextConfig, allNodes, flowVariables, availableFlowVars) && (nextConfig.value === undefined || nextConfig.value === '')) {
                              nextConfig.value = 'TRUE';
                            } else {
                              const qOpts = getVariableOptions(trimmed, availableFlowVars, allNodes);
                              if (qOpts && qOpts.length > 0 && (nextConfig.value === undefined || nextConfig.value === '')) {
                                nextConfig.value = qOpts[0];
                              }
                            }
                            onUpdateNodeData(selectedNode.id, { configuration: nextConfig });
                          }}
                        />
                      </div>

                      <div className="form-group">
                        <label className="form-label">Operador *</label>
                        <select
                          className="form-select"
                          value={config.operator || 'equals'}
                          onChange={(e) => handleConfigChange('operator', e.target.value)}
                          disabled={isReadOnly}
                        >
                          {operatorsList.map((op) => (
                            <option key={op.value} value={op.value}>
                              {op.label}
                            </option>
                          ))}
                        </select>
                      </div>

                      {config.operator !== 'exists' && config.operator !== 'not_exists' && config.operator !== 'is_empty' && config.operator !== 'not_empty' && (
                        <div className="form-group">
                          <label className="form-label">Valor de Comparación</label>
                          {isBool ? (
                            <select
                              className="form-select"
                              data-testid="condition-boolean-value-select"
                              aria-label="Valor de Comparación"
                              value={configVal}
                              onChange={(e) => handleConfigChange('value', e.target.value)}
                              disabled={isReadOnly}
                            >
                              <option value="TRUE">TRUE</option>
                              <option value="FALSE">FALSE</option>
                            </select>
                          ) : qOptions && qOptions.length > 0 && !isManualChoice && !isCustomValue ? (
                            <div style={{ display: 'flex', gap: '0.3rem' }}>
                              <select
                                className="form-select"
                                data-testid="condition-choice-value-select"
                                aria-label="Valor de Comparación"
                                value={config.value !== undefined && config.value !== '' ? String(config.value) : qOptions[0]}
                                onChange={(e) => {
                                  if (e.target.value === '__custom__') {
                                    setCustomChoiceMode((prev) => ({ ...prev, 'single-rule': true }));
                                  } else {
                                    handleConfigChange('value', e.target.value);
                                  }
                                }}
                                disabled={isReadOnly}
                              >
                                {qOptions.map((opt) => (
                                  <option key={opt} value={opt}>
                                    {opt}
                                  </option>
                                ))}
                                <option value="__custom__">── [Ingresar otro valor...] ──</option>
                              </select>
                              <button
                                type="button"
                                className="btn btn-ghost btn-sm"
                                style={{ fontSize: '0.7rem', padding: '0.2rem 0.35rem', color: '#61726d' }}
                                title="Escribir valor manual"
                                onClick={() => {
                                  setCustomChoiceMode((prev) => ({ ...prev, 'single-rule': true }));
                                }}
                              >
                                Manual
                              </button>
                            </div>
                          ) : (
                            <div style={{ display: 'flex', gap: '0.3rem' }}>
                              <input
                                type="text"
                                className="form-input"
                                data-testid="condition-value-input"
                                value={config.value !== undefined ? String(config.value) : ''}
                                onChange={(e) => handleConfigChange('value', e.target.value)}
                                disabled={isReadOnly}
                                placeholder="Ej. si"
                              />
                              {qOptions && qOptions.length > 0 && (
                                <button
                                  type="button"
                                  className="btn btn-secondary btn-sm"
                                  style={{ fontSize: '0.7rem', padding: '0.2rem 0.4rem', whiteSpace: 'nowrap' }}
                                  title="Volver a opciones de la pregunta"
                                  onClick={() => {
                                    setCustomChoiceMode((prev) => ({ ...prev, 'single-rule': false }));
                                    handleConfigChange('value', qOptions[0]);
                                  }}
                                >
                                  Opciones
                                </button>
                              )}
                            </div>
                          )}
                        </div>
                      )}
                    </>
                  );
                })()}
              </div>
            )}

            <div style={{ background: '#fffbeb', padding: '0.75rem', borderRadius: '0.5rem', border: '1px solid #fef3c7', fontSize: '0.75rem', color: '#92400e', lineHeight: '1.45' }}>
              <div style={{ fontWeight: 600, marginBottom: '0.25rem' }}>Conexiones requeridas:</div>
              <div>• Salida verde: Se ejecuta si la condición evalúa a <strong>TRUE</strong>.</div>
              <div>• Salida roja: Se ejecuta si la condición evalúa a <strong>FALSE</strong>.</div>
            </div>
          </div>
        )}

        {/* 4.5 SET VARIABLE NODE */}
        {nodeType === 'set_variable' && (
          <div className="properties-section">
            <div className="properties-section-header">
              <span className="properties-section-title">Asignación de Variable</span>
            </div>

            <div className="form-group">
              <label className="form-label">Nombre de Variable *</label>
              <input
                type="text"
                className="form-input"
                value={config.variable || ''}
                onChange={(e) => handleConfigChange('variable', e.target.value.trim())}
                disabled={isReadOnly}
                placeholder="Ej. contador, totalPuntos"
              />
            </div>

            <div className="form-group">
              <label className="form-label">Operación</label>
              <select
                className="form-select"
                value={config.operation || 'set'}
                onChange={(e) => handleConfigChange('operation', e.target.value)}
                disabled={isReadOnly}
              >
                <option value="set">Asignar valor (=)</option>
                <option value="increment">Incrementar número (+=)</option>
                <option value="decrement">Decrementar número (-=)</option>
                <option value="clear">Borrar variable (limpiar)</option>
              </select>
            </div>

            {config.operation !== 'clear' && (
              <>
                <div className="form-group">
                  <label className="form-label">Tipo de Dato</label>
                  <select
                    className="form-select"
                    data-testid="set-variable-type-select"
                    value={config.data_type || config.type || 'text'}
                    onChange={(e) => {
                      const newType = e.target.value;
                      const nextConfig: Record<string, any> = {
                        ...config,
                        type: newType,
                        data_type: newType,
                      };
                      if (newType === 'boolean') {
                        const cur = config.value;
                        const curUpper = typeof cur === 'string' ? cur.toUpperCase() : '';
                        if (cur !== true && cur !== false && curUpper !== 'TRUE' && curUpper !== 'FALSE') {
                          nextConfig.value = 'TRUE';
                        }
                      }
                      onUpdateNodeData(selectedNode.id, { configuration: nextConfig });
                    }}
                    disabled={isReadOnly}
                  >
                    <option value="text">Texto</option>
                    <option value="number">Número</option>
                    <option value="boolean">Booleano</option>
                    <option value="json">Objeto JSON</option>
                  </select>
                </div>

                <div className="form-group" style={{ marginBottom: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.35rem' }}>
                    <label className="form-label" style={{ margin: 0 }}>Valor a Asignar</label>
                    {(config.data_type || config.type) !== 'boolean' && (
                      <VariablePicker
                        variables={flowVariables}
                        onSelectVariable={(v) => insertVariableIntoField('value', v)}
                      />
                    )}
                  </div>
                  {(config.data_type || config.type) === 'boolean' ? (
                    <select
                      className="form-select"
                      data-testid="set-variable-boolean-value-select"
                      aria-label="Valor a Asignar"
                      value={
                        config.value === false || String(config.value).toUpperCase() === 'FALSE'
                          ? 'FALSE'
                          : 'TRUE'
                      }
                      onChange={(e) => handleConfigChange('value', e.target.value)}
                      disabled={isReadOnly}
                    >
                      <option value="TRUE">TRUE</option>
                      <option value="FALSE">FALSE</option>
                    </select>
                  ) : (
                    <input
                      type="text"
                      className="form-input"
                      value={config.value !== undefined ? String(config.value) : ''}
                      onChange={(e) => handleConfigChange('value', e.target.value)}
                      disabled={isReadOnly}
                      placeholder="Valor estático o {{variable}}"
                    />
                  )}
                </div>
              </>
            )}
          </div>
        )}

        {/* 4.6 HTTP REQUEST NODE */}
        {nodeType === 'http_request' && (
          <div className="properties-section">
            <div className="properties-section-header">
              <span className="properties-section-title">Petición HTTP Externa</span>
            </div>

            <div className="form-group">
              <label className="form-label">Método HTTP *</label>
              <select
                className="form-select"
                value={config.method || 'GET'}
                onChange={(e) => handleConfigChange('method', e.target.value)}
                disabled={isReadOnly}
              >
                <option value="GET">GET</option>
                <option value="POST">POST</option>
                <option value="PUT">PUT</option>
                <option value="PATCH">PATCH</option>
                <option value="DELETE">DELETE</option>
              </select>
            </div>

            <div className="form-group">
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.35rem' }}>
                <label className="form-label" style={{ margin: 0 }}>URL del Endpoint *</label>
                <VariablePicker
                  variables={flowVariables}
                  onSelectVariable={(v) => insertVariableIntoField('url', v)}
                />
              </div>
              <input
                type="text"
                className="form-input"
                value={config.url || ''}
                onChange={(e) => handleConfigChange('url', e.target.value)}
                disabled={isReadOnly}
                placeholder="https://api.ejemplo.com/recurso/{{id}}"
              />
            </div>

            {/* Response Mapping */}
            <div className="form-group">
              <label className="form-label">Mapeo de Respuesta JSON a Variables</label>
              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.35rem', marginBottom: '0.5rem' }}>
                {(config.response_mapping || []).map((m: any, idx: number) => (
                  <div
                    key={idx}
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'space-between',
                      background: '#f4f7f5',
                      padding: '0.35rem 0.5rem',
                      borderRadius: '0.375rem',
                      border: '1px solid #d9e3de',
                      fontSize: '0.75rem',
                    }}
                  >
                    <div>
                      <code>{m.json_path || m.path}</code> → <strong>{m.variable_name || m.variable}</strong>
                    </div>
                    {!isReadOnly && (
                      <button
                        type="button"
                        className="btn btn-danger btn-sm"
                        style={{ padding: '0.1rem 0.3rem' }}
                        onClick={() => {
                          const updated = (config.response_mapping || []).filter((_: any, i: number) => i !== idx);
                          handleConfigChange('response_mapping', updated);
                        }}
                      >
                        <X size={12} />
                      </button>
                    )}
                  </div>
                ))}
              </div>

              {!isReadOnly && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: '0.35rem' }}>
                  <div style={{ display: 'flex', gap: '0.35rem' }}>
                    <input
                      type="text"
                      className="form-input"
                      style={{ fontSize: '0.75rem', flex: 1 }}
                      placeholder="Ruta JSON (ej: order.status)"
                      value={newJsonPath}
                      onChange={(e) => setNewJsonPath(e.target.value)}
                    />
                    <input
                      type="text"
                      className="form-input"
                      style={{ fontSize: '0.75rem', flex: 1 }}
                      placeholder="Variable (ej: estadoPedido)"
                      value={newMappingVar}
                      onChange={(e) => setNewMappingVar(e.target.value)}
                    />
                  </div>
                  <button
                    type="button"
                    className="btn btn-secondary btn-sm"
                    style={{ borderRadius: '0.375rem' }}
                    onClick={() => {
                      if (newJsonPath.trim() && newMappingVar.trim()) {
                        const updated = [
                          ...(config.response_mapping || []),
                          { json_path: newJsonPath.trim(), variable_name: newMappingVar.trim() },
                        ];
                        handleConfigChange('response_mapping', updated);
                        setNewJsonPath('');
                        setNewMappingVar('');
                      }
                    }}
                  >
                    <Plus size={13} /> Añadir mapeo
                  </button>
                  <span style={{ fontSize: '0.72rem', color: 'var(--text-muted)' }}>
                    Usa notación por puntos (ej: <code>data.id</code> o <code>resultado.nombre</code>) para asignar campos de la respuesta JSON a variables del flujo.
                  </span>
                </div>
              )}
            </div>

            <div className="form-group" style={{ marginBottom: 0 }}>
              <label className="form-label">Timeout (segundos)</label>
              <input
                type="number"
                min="1"
                max="15"
                className="form-input"
                value={config.timeout || 5}
                onChange={(e) => handleConfigChange('timeout', parseInt(e.target.value, 10))}
                disabled={isReadOnly}
              />
              <span style={{ fontSize: '0.72rem', color: 'var(--text-muted)' }}>
                Tiempo máximo de espera de respuesta antes de continuar por la ruta de fallo (1 a 15 segundos).
              </span>
            </div>
          </div>
        )}

        {/* 4.7 GOTO NODE */}
        {nodeType === 'goto' && (
          <div className="properties-section">
            <div className="properties-section-header">
              <span className="properties-section-title">Destino del Salto</span>
            </div>

            <div className="form-group">
              <label className="form-label">Bloque de Destino *</label>
              <select
                className="form-select"
                value={config.target_node_key || ''}
                onChange={(e) => handleConfigChange('target_node_key', e.target.value)}
                disabled={isReadOnly}
              >
                <option value="">Selecciona un bloque de destino...</option>
                {allNodes
                  .filter((n) => n.node_key !== selectedNode.id && n.type !== 'start')
                  .map((n) => (
                    <option key={n.node_key} value={n.node_key}>
                      {n.name} ({n.node_key} • {n.type})
                    </option>
                  ))}
              </select>
            </div>

            {config.target_node_key && onJumpToNode && (
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                style={{ width: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '0.35rem', borderRadius: '0.5rem' }}
                onClick={() => onJumpToNode(config.target_node_key)}
              >
                <ExternalLink size={14} /> Centrar y enfocar bloque destino
              </button>
            )}

            <div style={{ background: '#eaf1ee', padding: '0.75rem', borderRadius: '0.5rem', border: '1px solid #ddd6fe', fontSize: '0.75rem', color: '#5b21b6', lineHeight: '1.45' }}>
              El bloque <strong>Ir a Bloque</strong> permite saltar a cualquier otro punto del flujo sin dibujar líneas cruzadas largas en el lienzo.
            </div>
          </div>
        )}

        {/* 5. TRANSFER TO AGENT */}
        {nodeType === 'transfer_to_agent' && (
          <div className="properties-section">
            <div className="properties-section-header">
              <span className="properties-section-title">Transferencia Humana</span>
            </div>
            <div className="form-group" style={{ marginBottom: 0 }}>
              <label className="form-label">Mensaje al Transferir (Opcional)</label>
              <textarea
                className="form-textarea"
                rows={3}
                value={config.message || ''}
                onChange={(e) => handleConfigChange('message', e.target.value)}
                disabled={isReadOnly}
                placeholder="Ej. Te comunicaré con uno de nuestros agentes en este momento..."
              />
              <span style={{ fontSize: '0.72rem', color: 'var(--text-muted)' }}>
                Este nodo es terminal: finaliza la ejecución automática y pasa el control a la cola humana.
              </span>
            </div>
          </div>
        )}

        {/* 6. END NODE */}
        {nodeType === 'end' && (
          <div className="properties-section">
            <div className="properties-section-header">
              <span className="properties-section-title">Fin de Conversación</span>
            </div>
            <div className="form-group" style={{ marginBottom: 0 }}>
              <label className="form-label">Mensaje Final de Despedida (Opcional)</label>
              <textarea
                className="form-textarea"
                rows={3}
                value={config.message || ''}
                onChange={(e) => handleConfigChange('message', e.target.value)}
                disabled={isReadOnly}
                placeholder="Ej. Gracias por contactarnos. ¡Que tengas un excelente día!"
              />
              <span style={{ fontSize: '0.72rem', color: 'var(--text-muted)' }}>
                Este nodo es terminal: cierra la conversación automáticamente.
              </span>
            </div>
          </div>
        )}

        {/* Node Actions: Duplicate and Delete */}
        {!isReadOnly && nodeType !== 'start' && (
          <div className="properties-footer">
            <button
              type="button"
              className="btn btn-secondary btn-sm properties-action-btn"
              onClick={() => onDuplicateNode(selectedNode.id)}
            >
              <Copy size={14} /> Duplicar
            </button>
            <button
              type="button"
              className="btn btn-danger btn-sm properties-action-btn"
              onClick={() => onDeleteNode(selectedNode.id)}
            >
              <Trash2 size={14} /> Eliminar
            </button>
          </div>
        )}
      </div>
    </aside>
  );
};
