import React, { useState, useMemo, useEffect } from 'react';
import {
  Variable as VariableIcon,
  Plus,
  Trash2,
  X,
  AlertTriangle,
} from 'lucide-react';
import { ConfirmModal } from '../common/ConfirmModal';
import type { FlowVariable, FlowNode } from '../../types';

interface VariableManagerModalProps {
  isOpen: boolean;
  variables: FlowVariable[];
  nodes: FlowNode[];
  onClose: () => void;
  onSaveVariables: (updatedVariables: FlowVariable[]) => void;
}

export const VariableManagerModal: React.FC<VariableManagerModalProps> = (props) => {
  if (!props.isOpen) return null;
  return <VariableManagerModalContent {...props} />;
};

const VariableManagerModalContent: React.FC<VariableManagerModalProps> = ({
  variables,
  nodes,
  onClose,
  onSaveVariables,
}) => {
  const [localVars, setLocalVars] = useState<FlowVariable[]>(variables);
  const [showAddForm, setShowAddForm] = useState(false);
  const [newName, setNewName] = useState('');
  const [newType, setNewType] = useState<FlowVariable['type']>('text');
  const [newScope, setNewScope] = useState<FlowVariable['scope']>('conversation');
  const [newInitialVal, setNewInitialVal] = useState('');
  const [newDesc, setNewDesc] = useState('');
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [varToDelete, setVarToDelete] = useState<{ name: string; refs: any[] } | null>(null);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [onClose]);

  // Compute references of variables across all nodes
  const referencesMap = useMemo(() => {
    const map = new Map<string, Array<{ nodeKey: string; nodeName: string }>>();

    for (const node of nodes) {
      const cfg = node.configuration || {};
      const cfgStr = JSON.stringify(cfg);

      // Search for {{var}} references or direct variable configs
      const matches = cfgStr.match(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g) || [];
      const varNamesFound = new Set<string>();

      for (const m of matches) {
        const cleanName = m.replace(/[{}\s]/g, '');
        varNamesFound.add(cleanName);
      }

      if (cfg.variable) varNamesFound.add(cfg.variable);
      if (Array.isArray(cfg.rules)) {
        for (const r of cfg.rules) {
          if (r.variable) varNamesFound.add(r.variable);
          if (r.compare_type === 'variable' && r.value) varNamesFound.add(r.value);
        }
      }

      for (const vName of varNamesFound) {
        if (!map.has(vName)) map.set(vName, []);
        map.get(vName)!.push({ nodeKey: node.node_key, nodeName: node.name });
      }
    }

    return map;
  }, [nodes]);

  const handleAddVariable = (e: React.FormEvent) => {
    e.preventDefault();
    const cleanName = newName.trim();

    if (!cleanName) {
      setErrorMsg('El nombre de la variable no puede estar vacío.');
      return;
    }

    if (!/^[a-zA-Z_][a-zA-Z0-9_.]*$/.test(cleanName)) {
      setErrorMsg('El nombre debe empezar con letra o guion bajo y contener solo letras, números y puntos.');
      return;
    }

    if (localVars.some((v) => v.name === cleanName)) {
      setErrorMsg(`La variable '${cleanName}' ya existe.`);
      return;
    }

    const updated = [
      ...localVars,
      {
        name: cleanName,
        type: newType,
        scope: newScope,
        initial_value: newInitialVal || undefined,
        description: newDesc || undefined,
      },
    ];

    setLocalVars(updated);
    onSaveVariables(updated);
    setNewName('');
    setNewInitialVal('');
    setNewDesc('');
    setShowAddForm(false);
    setErrorMsg(null);
  };

  const handleDeleteVariable = (varName: string) => {
    const refs = referencesMap.get(varName) || [];
    setVarToDelete({ name: varName, refs });
  };

  const scopeBadges: Record<FlowVariable['scope'], { label: string; bg: string; color: string }> = {
    conversation: { label: 'Conversación', bg: '#ddeae4', color: '#456c61' },
    contact: { label: 'Contacto (Persistente)', bg: '#fef3c7', color: '#b45309' },
    temporary: { label: 'Temporal', bg: '#eaf1ee', color: '#61726d' },
    system: { label: 'Sistema', bg: '#eaf1ee', color: '#456c61' },
  };

  return (
    <div
      className="modal-overlay flow-panel-overlay"
      role="dialog"
      aria-modal="true"
      onClick={onClose}
      style={{
        position: 'fixed',
        top: 0,
        left: 0,
        right: 0,
        bottom: 0,
        background: 'rgba(15, 23, 42, 0.5)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 1100,
        backdropFilter: 'blur(2px)',
      }}
    >
      <div
        className="modal-container modal-content flow-side-panel"
        onClick={(e) => e.stopPropagation()}
        style={{
          width: '680px',
          maxHeight: '85vh',
          background: '#ffffff',
          borderRadius: '12px',
          boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1)',
          display: 'flex',
          flexDirection: 'column',
          overflow: 'hidden',
          border: '1px solid #d9e3de',
        }}
      >
        {/* Header */}
          <div
          className="flow-side-panel-header"
          style={{
            background: '#ffffff',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            <div style={{ width: '32px', height: '32px', borderRadius: '8px', background: '#eaf1ee', color: '#263c38', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <VariableIcon size={18} />
            </div>
            <div>
              <h3 style={{ fontSize: '1.05rem', fontWeight: 700, margin: 0, color: '#263c38' }}>
                Administrador de Variables
              </h3>
              <p style={{ fontSize: '0.75rem', color: '#61726d', margin: '0.15rem 0 0 0' }}>
                Gestiona variables dinámicas, tipos, alcances y referencias en el flujo
              </p>
            </div>
          </div>
          <button
            type="button"
            className="btn btn-sm"
            style={{ padding: '0.25rem', border: 'none', background: 'transparent', color: '#61726d' }}
            onClick={onClose}
          >
            <X size={18} />
          </button>
        </div>

        {/* Body */}
        <div className="flow-side-panel-body" style={{ flex: 1 }}>
          {errorMsg && (
            <div style={{ padding: '0.6rem 0.8rem', background: '#fee2e2', color: '#991b1b', borderRadius: '6px', fontSize: '0.8rem', marginBottom: '1rem', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
              <AlertTriangle size={15} /> {errorMsg}
            </div>
          )}

          {/* Add form */}
          {showAddForm ? (
            <form onSubmit={handleAddVariable} style={{ background: '#f4f7f5', border: '1px solid #d9e3de', borderRadius: '8px', padding: '1rem', marginBottom: '1.25rem' }}>
              <div style={{ fontWeight: 600, fontSize: '0.9rem', marginBottom: '0.75rem', color: '#263c38' }}>
                Nueva Variable Personalizada
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem', marginBottom: '0.75rem' }}>
                <div>
                  <label style={{ display: 'block', fontSize: '0.75rem', fontWeight: 600, marginBottom: '0.25rem' }}>
                    Nombre (identificador)
                  </label>
                  <input
                    type="text"
                    className="form-control"
                    placeholder="ej: estadoPedido, nombreCliente"
                    value={newName}
                    onChange={(e) => setNewName(e.target.value)}
                    required
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: '0.75rem', fontWeight: 600, marginBottom: '0.25rem' }}>
                    Tipo de dato
                  </label>
                  <select
                    className="form-select"
                    value={newType}
                    onChange={(e) => setNewType(e.target.value as any)}
                  >
                    <option value="text">Texto (string)</option>
                    <option value="number">Número (number)</option>
                    <option value="boolean">Booleano (boolean)</option>
                    <option value="date">Fecha (date)</option>
                    <option value="json">Objeto JSON</option>
                    <option value="list">Lista / Array</option>
                  </select>
                </div>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem', marginBottom: '0.75rem' }}>
                <div>
                  <label style={{ display: 'block', fontSize: '0.75rem', fontWeight: 600, marginBottom: '0.25rem' }}>
                    Alcance (Scope)
                  </label>
                  <select
                    className="form-select"
                    value={newScope}
                    onChange={(e) => setNewScope(e.target.value as any)}
                  >
                    <option value="conversation">Conversación (sesión activa)</option>
                    <option value="contact">Contacto (persistente en CRM)</option>
                    <option value="temporary">Temporal (durante ejecución)</option>
                  </select>
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: '0.75rem', fontWeight: 600, marginBottom: '0.25rem' }}>
                    Valor inicial (opcional)
                  </label>
                  <input
                    type="text"
                    className="form-control"
                    placeholder="Valor por defecto"
                    value={newInitialVal}
                    onChange={(e) => setNewInitialVal(e.target.value)}
                  />
                </div>
              </div>

              <div style={{ marginBottom: '0.75rem' }}>
                <label style={{ display: 'block', fontSize: '0.75rem', fontWeight: 600, marginBottom: '0.25rem' }}>
                  Descripción (opcional)
                </label>
                <input
                  type="text"
                  className="form-control"
                  placeholder="Para qué sirve esta variable"
                  value={newDesc}
                  onChange={(e) => setNewDesc(e.target.value)}
                />
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.5rem' }}>
                <button
                  type="button"
                  className="btn btn-secondary btn-sm"
                  onClick={() => setShowAddForm(false)}
                >
                  Cancelar
                </button>
                <button type="submit" className="btn btn-primary btn-sm">
                  Guardar Variable
                </button>
              </div>
            </form>
          ) : (
            <button
              type="button"
              className="btn btn-primary btn-sm"
              style={{ marginBottom: '1rem', display: 'flex', alignItems: 'center', gap: '0.35rem' }}
              onClick={() => setShowAddForm(true)}
            >
              <Plus size={15} /> Crear Variable
            </button>
          )}

          {/* Variables Table */}
          {localVars.length === 0 ? (
            <div style={{ textAlign: 'center', padding: '2rem 1rem', color: '#61726d' }}>
              <VariableIcon size={32} style={{ margin: '0 auto 0.5rem auto', opacity: 0.5 }} />
              <p style={{ margin: 0, fontWeight: 500 }}>No hay variables personalizadas definidas.</p>
              <p style={{ fontSize: '0.75rem', margin: '0.25rem 0 0 0' }}>
                Crea variables para almacenar datos de respuestas, llamadas HTTP o resultados de condiciones.
              </p>
            </div>
          ) : (
            <div style={{ border: '1px solid #d9e3de', borderRadius: '8px', overflow: 'hidden' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.8rem' }}>
                <thead style={{ background: '#f4f7f5', borderBottom: '1px solid #d9e3de', color: '#61726d', fontWeight: 600 }}>
                  <tr>
                    <th style={{ padding: '0.5rem 0.75rem', textAlign: 'left' }}>Variable</th>
                    <th style={{ padding: '0.5rem 0.75rem', textAlign: 'left' }}>Tipo</th>
                    <th style={{ padding: '0.5rem 0.75rem', textAlign: 'left' }}>Alcance</th>
                    <th style={{ padding: '0.5rem 0.75rem', textAlign: 'left' }}>Uso en Flujo</th>
                    <th style={{ padding: '0.5rem 0.75rem', textAlign: 'right' }}>Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  {localVars.map((v) => {
                    const refs = referencesMap.get(v.name) || [];
                    const badge = scopeBadges[v.scope] || scopeBadges.conversation;

                    return (
                      <tr key={v.name} style={{ borderBottom: '1px solid #eaf1ee' }}>
                        <td style={{ padding: '0.6rem 0.75rem', fontFamily: 'monospace', fontWeight: 700, color: '#56736b' }}>
                          {`{{${v.name}}}`}
                          {v.description && (
                            <div style={{ fontSize: '0.7rem', color: '#80918c', fontFamily: 'sans-serif', fontWeight: 400 }}>
                              {v.description}
                            </div>
                          )}
                        </td>
                        <td style={{ padding: '0.6rem 0.75rem' }}>
                          <span style={{ background: '#eaf1ee', padding: '0.15rem 0.4rem', borderRadius: '4px', fontSize: '0.7rem' }}>
                            {v.type}
                          </span>
                        </td>
                        <td style={{ padding: '0.6rem 0.75rem' }}>
                          <span
                            style={{
                              background: badge.bg,
                              color: badge.color,
                              padding: '0.15rem 0.45rem',
                              borderRadius: '4px',
                              fontSize: '0.7rem',
                              fontWeight: 600,
                            }}
                          >
                            {badge.label}
                          </span>
                        </td>
                        <td style={{ padding: '0.6rem 0.75rem' }}>
                          {refs.length > 0 ? (
                            <span style={{ color: '#5d8f82', fontWeight: 600, fontSize: '0.72rem' }}>
                              ● {refs.length} bloque{refs.length > 1 ? 's' : ''}
                            </span>
                          ) : (
                            <span style={{ color: '#80918c', fontSize: '0.72rem' }}>Sin uso</span>
                          )}
                        </td>
                        <td style={{ padding: '0.6rem 0.75rem', textAlign: 'right' }}>
                          <button
                            type="button"
                            className="btn btn-sm"
                            style={{ padding: '0.2rem 0.4rem', color: '#dc2626', border: 'none', background: 'transparent' }}
                            onClick={() => handleDeleteVariable(v.name)}
                            title="Eliminar variable"
                          >
                            <Trash2 size={14} />
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flow-side-panel-footer">
          <button type="button" className="btn btn-secondary btn-sm" onClick={onClose}>
            Cerrar
          </button>
        </div>
      </div>

      <ConfirmModal
        isOpen={Boolean(varToDelete)}
        onClose={() => setVarToDelete(null)}
        onConfirm={() => {
          if (varToDelete) {
            const updated = localVars.filter((v) => v.name !== varToDelete.name);
            setLocalVars(updated);
            onSaveVariables(updated);
            setVarToDelete(null);
          }
        }}
        title={varToDelete ? `¿Eliminar variable «${varToDelete.name}»?` : '¿Eliminar variable?'}
        description={
          varToDelete
            ? varToDelete.refs.length > 0
              ? `Esta variable se utiliza en ${varToDelete.refs.length} bloque(s) (${varToDelete.refs.map((r: any) => r.nodeName).join(', ')}). Eliminarla puede causar errores en tiempo de ejecución.`
              : 'Esta acción quitará la variable del flujo y no se puede deshacer.'
            : ''
        }
        confirmText="Eliminar variable"
        cancelText="Cancelar"
        confirmVariant="danger"
        testId="confirm-delete-var-modal"
        requiredText={varToDelete?.name}
        requiredTextLabel="variable"
      />
    </div>
  );
};
