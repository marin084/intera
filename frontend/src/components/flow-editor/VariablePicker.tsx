import React, { useState, useRef, useEffect } from 'react';
import { Variable as VariableIcon, ChevronDown } from 'lucide-react';
import type { FlowVariable } from '../../types';

interface VariablePickerProps {
  variables: FlowVariable[];
  onSelectVariable: (variableName: string) => void;
}

const SYSTEM_VARIABLES = [
  { name: 'contact.name', label: 'Nombre del contacto', scope: 'contact' },
  { name: 'contact.email', label: 'Email del contacto', scope: 'contact' },
  { name: 'contact.phone', label: 'Teléfono del contacto', scope: 'contact' },
  { name: 'contact.country', label: 'País (ISO Alfa-2)', scope: 'contact' },
  { name: 'system.channel', label: 'Canal (webchat, etc.)', scope: 'system' },
  { name: 'system.agents_online', label: 'Agentes en línea (TRUE/FALSE)', scope: 'system' },
];

export const VariablePicker: React.FC<VariablePickerProps> = ({ variables, onSelectVariable }) => {
  const [isOpen, setIsOpen] = useState(false);
  const [openUpwards, setOpenUpwards] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  const handleToggle = () => {
    if (!isOpen && containerRef.current) {
      const rect = containerRef.current.getBoundingClientRect();
      const spaceBelow = window.innerHeight - rect.bottom;
      if (spaceBelow < 260 && rect.top > 260) {
        setOpenUpwards(true);
      } else {
        setOpenUpwards(false);
      }
    }
    setIsOpen((prev) => !prev);
  };

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  return (
    <div style={{ position: 'relative', display: 'inline-block' }} ref={containerRef}>
      <button
        type="button"
        className="btn btn-secondary btn-sm"
        style={{
          padding: '0.2rem 0.5rem',
          fontSize: '0.72rem',
          display: 'flex',
          alignItems: 'center',
          gap: '0.3rem',
          borderColor: '#d9e3de',
          background: '#f4f7f5',
        }}
        onClick={handleToggle}
        title="Insertar variable dinámica ({{nombre}})"
      >
        <VariableIcon size={12} color="#456c61" />
        <span style={{ fontWeight: 600, color: '#263c38' }}>Insertar variable</span>
        <ChevronDown size={11} color="#61726d" />
      </button>

      {isOpen && (
        <div
          className="variable-picker-dropdown"
          data-testid="variable-picker-dropdown"
          style={{
            position: 'absolute',
            right: 0,
            ...(openUpwards
              ? { bottom: 'calc(100% + 4px)' }
              : { top: 'calc(100% + 4px)' }),
            background: '#ffffff',
            border: '1px solid #d9e3de',
            borderRadius: '8px',
            boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1)',
            zIndex: 60,
            width: '240px',
            maxHeight: '240px',
            overflowY: 'auto',
            padding: '0.35rem 0',
          }}
        >
          {/* Custom Variables */}
          <div style={{ padding: '0.3rem 0.6rem', fontSize: '0.65rem', fontWeight: 700, color: '#61726d', textTransform: 'uppercase' }}>
            Variables del Flujo
          </div>
          {variables.length === 0 ? (
            <div style={{ padding: '0.4rem 0.75rem', fontSize: '0.72rem', color: '#80918c' }}>
              No hay variables creadas
            </div>
          ) : (
            variables.map((v) => (
              <div
                key={v.name}
                onClick={() => {
                  onSelectVariable(v.name);
                  setIsOpen(false);
                }}
                style={{
                  padding: '0.35rem 0.75rem',
                  fontSize: '0.75rem',
                  cursor: 'pointer',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                }}
                onMouseEnter={(e) => (e.currentTarget.style.background = '#eaf1ee')}
                onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
              >
                <span style={{ fontFamily: 'monospace', fontWeight: 600, color: '#56736b' }}>
                  {`{{${v.name}}}`}
                </span>
                <span style={{ fontSize: '0.65rem', color: '#80918c' }}>{v.type}</span>
              </div>
            ))
          )}

          <div style={{ height: '1px', background: '#eaf1ee', margin: '0.3rem 0' }} />

          {/* System & Contact variables */}
          <div style={{ padding: '0.3rem 0.6rem', fontSize: '0.65rem', fontWeight: 700, color: '#61726d', textTransform: 'uppercase' }}>
            Contacto & Sistema
          </div>
          {SYSTEM_VARIABLES.map((v) => (
            <div
              key={v.name}
              onClick={() => {
                onSelectVariable(v.name);
                setIsOpen(false);
              }}
              style={{
                padding: '0.35rem 0.75rem',
                fontSize: '0.75rem',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
              }}
              onMouseEnter={(e) => (e.currentTarget.style.background = '#eaf1ee')}
              onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
            >
              <span style={{ fontFamily: 'monospace', fontWeight: 600, color: '#56736b' }}>
                {`{{${v.name}}}`}
              </span>
              <span style={{ fontSize: '0.65rem', color: '#80918c' }}>{v.scope}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};
