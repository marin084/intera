import React from 'react';
import { AlertTriangle } from 'lucide-react';

interface DiscardChangesPanelProps {
  isOpen: boolean;
  onContinueEditing: () => void;
  onDiscard: () => void;
}

/** Side-panel confirmation used before losing unsaved form changes. */
export const DiscardChangesPanel: React.FC<DiscardChangesPanelProps> = ({
  isOpen,
  onContinueEditing,
  onDiscard,
}) => {
  if (!isOpen) return null;

  return (
    <div className="modal-overlay flow-panel-overlay discard-panel-overlay" role="dialog" aria-modal="true" aria-labelledby="discard-changes-title">
      <section className="modal-content flow-side-panel discard-changes-panel">
        <header className="flow-side-panel-header">
          <h2 id="discard-changes-title"><AlertTriangle size={19} aria-hidden="true" /> Cambios sin guardar</h2>
          <button type="button" className="btn btn-secondary btn-sm" onClick={onContinueEditing} aria-label="Seguir editando">×</button>
        </header>
        <div className="flow-side-panel-body">
          <p>Tienes cambios que todavía no se han guardado. ¿Quieres descartarlos?</p>
        </div>
        <footer className="flow-side-panel-footer">
          <button type="button" className="btn btn-secondary" onClick={onContinueEditing}>Seguir editando</button>
          <button type="button" className="btn btn-danger" onClick={onDiscard}>Descartar cambios</button>
        </footer>
      </section>
    </div>
  );
};
