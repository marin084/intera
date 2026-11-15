import React from 'react';
import {
  ArrowLeft,
  Save,
  CheckCircle,
  UploadCloud,
  Maximize2,
  Minimize2,
  Undo2,
  Redo2,
  CopyPlus,
  Clock,
  Lock,
  Search,
  RotateCcw,
  Network,
  Variable as VariableIcon,
  PlayCircle,
  History,
  ChevronDown,
  Sparkles,
  LayoutGrid,
} from 'lucide-react';
import type { Flow, FlowVersion } from '../../types';

interface FlowToolbarProps {
  flow: Flow | null;
  currentVersion: FlowVersion | null;
  versions: FlowVersion[];
  isDirty: boolean;
  isSaving: boolean;
  isValidating: boolean;
  isPublishing: boolean;
  canUndo: boolean;
  canRedo: boolean;
  isReadOnly?: boolean;
  isAutoOrganizing?: boolean;
  isZenMode?: boolean;
  hasSelectedNode?: boolean;
  highlightConnections?: boolean;
  variableCount?: number;
  isPaletteOpen?: boolean;
  onTogglePalette?: () => void;
  onBack: () => void;
  onSelectVersion: (versionId: string) => void;
  onSave: () => void;
  onValidate: () => void;
  onPublish: () => void;
  onFitView?: () => void;
  onCenterStart?: () => void;
  onCenterSelected?: () => void;
  onResetView?: () => void;
  onToggleZenMode?: () => void;
  onUndo: () => void;
  onRedo: () => void;
  onCloneNewVersion: () => void;
  onAutoOrganize: () => void;
  onSearchClick: () => void;
  onToggleHighlightConnections?: () => void;
  onOpenVariableManager?: () => void;
  onOpenSimulator?: () => void;
  onOpenHistory?: () => void;
  onOpenCompare?: () => void;
  onRollback?: () => void;
}

export const FlowToolbar: React.FC<FlowToolbarProps> = ({
  flow,
  currentVersion,
  versions,
  isDirty,
  isSaving,
  isValidating,
  isPublishing,
  canUndo,
  canRedo,
  isReadOnly,
  isAutoOrganizing = false,
  isZenMode = false,
  highlightConnections = true,
  variableCount = 0,
  isPaletteOpen = false,
  onTogglePalette,
  onBack,
  onSelectVersion,
  onSave,
  onValidate,
  onPublish,
  onToggleZenMode,
  onUndo,
  onRedo,
  onCloneNewVersion,
  onAutoOrganize,
  onSearchClick,
  onToggleHighlightConnections,
  onOpenVariableManager,
  onOpenSimulator,
  onOpenHistory,
  onRollback,
}) => {
  const isPublished = currentVersion?.status === 'published';
  const isActiveInProduction = flow?.active_version_id === currentVersion?.id;
  const isEffectiveReadOnly = isReadOnly !== undefined ? isReadOnly : isPublished;

  return (
    <header className="flow-toolbar">
      {/* Zona A: Lado Izquierdo (Contexto del Flujo) */}
      <div className="flow-toolbar-left">
        <button
          type="button"
          className="btn btn-secondary btn-sm flow-back-btn"
          onClick={onBack}
          title="Volver a lista de flujos"
          aria-label="Volver a lista de flujos"
        >
          <ArrowLeft size={15} />
          <span className="flow-toolbar-label-responsive">Volver</span>
        </button>

        <span className="flow-toolbar-title" title={flow?.name || 'Constructor de Flujo'}>
          {flow?.name || 'Constructor de Flujo'}
        </span>

        {/* Selector de versiones estilizado como cápsula compacta */}
        <div
          className={`flow-version-capsule ${
            isPublished
              ? isActiveInProduction
                ? 'capsule-active'
                : 'capsule-history'
              : 'capsule-draft'
          }`}
          title={
            isPublished
              ? isActiveInProduction
                ? 'Versión publicada y activa en producción'
                : 'Versión histórica publicada (solo lectura)'
              : 'Versión borrador (editable)'
          }
        >
          {isPublished ? (
            <Lock size={12} className="flow-capsule-icon" />
          ) : (
            <Clock size={12} className="flow-capsule-icon" />
          )}

          {versions.length > 0 ? (
            <select
              className="flow-version-select"
              value={currentVersion?.id || ''}
              onChange={(e) => onSelectVersion(e.target.value)}
              aria-label="Seleccionar versión del flujo"
            >
              {versions.map((ver) => {
                const isVerPublished = ver.status === 'published';
                const isVerActive = ver.id === flow?.active_version_id;
                const statusLabel = isVerPublished
                  ? isVerActive
                    ? 'Publicada (Activa)'
                    : 'Publicada (Histórica)'
                  : 'Borrador';
                return (
                  <option key={ver.id} value={ver.id}>
                    v{ver.version_number} — {statusLabel}
                  </option>
                );
              })}
            </select>
          ) : (
            <span className="flow-version-fallback-label">
              v{currentVersion?.version_number ?? 1} —{' '}
              {isPublished
                ? isActiveInProduction
                  ? 'Publicada (Activa)'
                  : 'Publicada (Histórica)'
                : 'Borrador'}
            </span>
          )}
          <ChevronDown size={12} className="flow-capsule-chevron" />
        </div>

        {/* Catálogo de Bloques */}
        {!isEffectiveReadOnly && onTogglePalette && (
          <button
            type="button"
            className={`btn ${isPaletteOpen ? 'btn-primary' : 'btn-secondary'} btn-sm flow-palette-toggle-btn`}
            data-testid="toggle-palette-btn"
            onClick={onTogglePalette}
            title={isPaletteOpen ? 'Cerrar catálogo de bloques' : 'Abrir catálogo de bloques (+)'}
            aria-label="Catálogo de bloques"
          >
            <LayoutGrid size={14} />
            <span className="flow-toolbar-label-responsive">Bloques</span>
          </button>
        )}
      </div>

      {/* Zona B: Centro (Herramientas de Edición del Grafo) */}
      <div className="flow-toolbar-center">
        {/* Deshacer / Rehacer */}
        <div className="flow-btn-group">
          <button
            type="button"
            className="btn btn-secondary btn-sm flow-icon-btn"
            disabled={!canUndo || isEffectiveReadOnly}
            onClick={onUndo}
            title="Deshacer (Cmd+Z / Ctrl+Z)"
            aria-label="Deshacer"
          >
            <Undo2 size={14} />
          </button>
          <button
            type="button"
            className="btn btn-secondary btn-sm flow-icon-btn"
            disabled={!canRedo || isEffectiveReadOnly}
            onClick={onRedo}
            title="Rehacer (Cmd+Shift+Z / Ctrl+Y)"
            aria-label="Rehacer"
          >
            <Redo2 size={14} />
          </button>
        </div>

        {/* Autoorganizar */}
        <button
          type="button"
          className="btn btn-secondary btn-sm"
          disabled={isEffectiveReadOnly || isAutoOrganizing}
          onClick={onAutoOrganize}
          title="Autoorganizar flujo inteligentemente de arriba hacia abajo (ELK Layout)"
          aria-label={isAutoOrganizing ? 'Organizando flujo' : 'Autoorganizar flujo'}
        >
          <Sparkles size={14} color="#5d8f82" />
          <span>{isAutoOrganizing ? 'Organizando...' : 'Organizar'}</span>
        </button>

        {/* Variables con badge */}
        {onOpenVariableManager && (
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={onOpenVariableManager}
            title="Administrador de variables personalizadas y del sistema"
            aria-label={`Variables (${variableCount})`}
          >
            <VariableIcon size={14} color="#5d8f82" />
            <span className="flow-toolbar-label-responsive">Variables</span>
            {variableCount > 0 && (
              <span className="flow-counter-badge">{variableCount}</span>
            )}
          </button>
        )}

        {/* Resaltado contextual de conexiones */}
        {onToggleHighlightConnections && (
          <button
            type="button"
            className={`btn btn-sm flow-icon-btn ${highlightConnections ? 'btn-primary' : 'btn-secondary'}`}
            onClick={onToggleHighlightConnections}
            title={highlightConnections ? 'Desactivar resaltado contextual de conexiones' : 'Activar resaltado contextual de conexiones'}
            aria-label="Resaltar conexiones"
          >
            <Network size={14} />
          </button>
        )}
      </div>

      {/* Zona C: Lado Derecho (Operación, Pruebas y Pantalla Completa) */}
      <div className="flow-toolbar-right">
        {/* Buscar */}
        <button
          type="button"
          className="btn btn-secondary btn-sm"
          onClick={onSearchClick}
          title="Buscar bloques (⌘K / Ctrl+F)"
          aria-label="Buscar bloques"
        >
          <Search size={14} color="#61726d" />
          <span className="flow-toolbar-label-responsive">Buscar</span>
          <kbd className="flow-kbd-shortcut">⌘K</kbd>
        </button>

        {/* Historial */}
        {onOpenHistory && (
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={onOpenHistory}
            title="Ver historial de versiones, notas y auditoría"
            aria-label="Historial de versiones"
          >
            <History size={14} color="#5d8f82" />
            <span className="flow-toolbar-label-responsive">Historial</span>
          </button>
        )}

        {/* Simulador conversacional */}
        {onOpenSimulator && (
          <button
            type="button"
            data-testid="open-simulator-btn"
            className="btn btn-sm flow-simulator-btn"
            onClick={onOpenSimulator}
            title="Abrir simulador conversacional en tiempo real"
            aria-label="Abrir simulador"
          >
            <PlayCircle size={14} />
            <span className="flow-toolbar-label-responsive">Simulador</span>
          </button>
        )}

        {/* Pantalla completa / Zen Mode */}
        {onToggleZenMode && (
          <button
            type="button"
            className={`btn ${isZenMode ? 'btn-primary' : 'btn-secondary'} btn-sm flow-icon-btn`}
            data-testid="zen-mode-toggle-btn"
            onClick={onToggleZenMode}
            title={isZenMode ? 'Salir de Modo Zen / Pantalla completa [F11]' : 'Modo Zen / Pantalla completa [F11]'}
            aria-label={isZenMode ? 'Salir de Modo Zen' : 'Modo Zen'}
          >
            {isZenMode ? <Minimize2 size={14} /> : <Maximize2 size={14} />}
          </button>
        )}

        {/* Separador fluido sutil */}
        <div className="flow-toolbar-divider" />

        {/* Estado y Acciones Principales */}
        {!isEffectiveReadOnly ? (
          <>
            <div className="flow-save-status">
              {isDirty ? (
                <span className="status-dirty" title="Hay cambios sin guardar">● Cambios sin guardar</span>
              ) : (
                <span className="status-saved" title="Todos los cambios están guardados">
                  <CheckCircle size={13} /> Guardado
                </span>
              )}
            </div>

            <button
              type="button"
              className="btn btn-secondary btn-sm"
              onClick={onSave}
              disabled={isSaving || !isDirty}
              title={isDirty ? 'Guardar cambios pendientes (Cmd+S)' : 'No hay cambios pendientes'}
              aria-label="Guardar"
            >
              <Save size={14} />
              <span className="flow-toolbar-label-responsive">{isSaving ? 'Guardando...' : 'Guardar'}</span>
            </button>

            <button
              type="button"
              className="btn btn-secondary btn-sm"
              onClick={onValidate}
              disabled={isValidating}
              title="Comprobar integridad estructural con el validador del backend"
              aria-label="Validar flujo"
            >
              <CheckCircle size={14} color="#5d8f82" />
              <span className="flow-toolbar-label-responsive">{isValidating ? 'Validando...' : 'Validar flujo'}</span>
            </button>

            <button
              type="button"
              className="btn btn-primary btn-sm flow-main-action-btn"
              onClick={onPublish}
              disabled={isPublishing}
              title="Validar y publicar versión inmutable"
              aria-label="Publicar"
            >
              <UploadCloud size={14} />
              <span>{isPublishing ? 'Publicando...' : 'Publicar versión'}</span>
            </button>
          </>
        ) : (
          <div className="flow-toolbar-published-actions">
            {!isActiveInProduction && onRollback && (
              <button
                type="button"
                className="btn btn-warning btn-sm"
                style={{ background: '#fff1f2', color: '#e11d48', borderColor: '#fecdd3' }}
                onClick={onRollback}
                title="Restaurar esta versión histórica como activa en producción"
              >
                <RotateCcw size={13} />
                <span className="flow-toolbar-label-responsive">Restaurar versión</span>
              </button>
            )}

            <button
              type="button"
              className="btn btn-primary btn-sm flow-main-action-btn"
              onClick={onCloneNewVersion}
              title="Crear un nuevo borrador editable a partir de esta versión"
              aria-label="Crear nueva versión borrador"
            >
              <CopyPlus size={14} />
              <span>Crear borrador para editar</span>
            </button>
          </div>
        )}
      </div>
    </header>
  );
};
