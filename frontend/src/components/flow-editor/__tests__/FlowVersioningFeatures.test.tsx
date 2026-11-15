import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { FlowVersionHistoryDrawer } from '../FlowVersionHistoryDrawer';
import { FlowVersionCompareModal } from '../FlowVersionCompareModal';
import { FlowRollbackModal } from '../FlowRollbackModal';
import { api } from '../../../services/api';
import type { FlowVersion, FlowPublication, FlowComparisonResult } from '../../../types';

vi.mock('../../../services/api', () => ({
  api: {
    compareFlowVersions: vi.fn(),
    rollbackFlowVersion: vi.fn(),
  },
}));

describe('Flow Versioning UI Components (Fase 3)', () => {
  const mockVersions: FlowVersion[] = [
    {
      id: 'ver-1',
      flow_id: 'flow-123',
      version_number: 1,
      status: 'published',
      is_active: false,
      published_at: '2026-10-08T09:00:00Z',
      description: 'Versión inicial',
      nodes: [
        { id: 'node-1', node_key: 'start', type: 'start', name: 'Inicio', configuration: {}, position_x: 0, position_y: 0 },
      ],
      edges: [],
      metadata: { variables: [] },
      created_at: '2026-10-08T08:50:00Z',
      updated_at: '2026-10-08T09:00:00Z',
    },
    {
      id: 'ver-2',
      flow_id: 'flow-123',
      version_number: 2,
      status: 'published',
      is_active: true,
      published_at: '2026-10-08T10:00:00Z',
      description: 'Versión con soporte y ventas',
      nodes: [
        { id: 'node-1', node_key: 'start', type: 'start', name: 'Inicio', configuration: {}, position_x: 0, position_y: 0 },
        { id: 'node-2', node_key: 'menu', type: 'menu', name: 'Menú Principal', configuration: {}, position_x: 200, position_y: 0 },
      ],
      edges: [],
      metadata: { variables: [] },
      created_at: '2026-10-08T09:50:00Z',
      updated_at: '2026-10-08T10:00:00Z',
    },
    {
      id: 'ver-3',
      flow_id: 'flow-123',
      version_number: 3,
      status: 'draft',
      is_active: false,
      description: 'Borrador en desarrollo',
      published_at: null,
      nodes: [],
      edges: [],
      created_at: '2026-10-08T10:10:00Z',
      updated_at: '2026-10-08T10:10:00Z',
    },
  ];

  const mockPublications: FlowPublication[] = [
    {
      id: 'pub-1',
      flow_id: 'flow-123',
      flow_version_id: 'ver-1',
      previous_version_id: null,
      published_by: 'user-1',
      action: 'publish',
      notes: 'Lanzamiento inicial',
      created_at: '2026-10-08T09:00:00Z',
      version: mockVersions[0],
      user: { id: 'user-1', name: 'Admin Conexa', email: 'admin@conexa.io', role: 'admin' },
    },
    {
      id: 'pub-2',
      flow_id: 'flow-123',
      flow_version_id: 'ver-2',
      previous_version_id: 'ver-1',
      published_by: 'user-1',
      action: 'publish',
      notes: 'Actualización importante',
      created_at: '2026-10-08T10:00:00Z',
      version: mockVersions[1],
      user: { id: 'user-1', name: 'Admin Conexa', email: 'admin@conexa.io', role: 'admin' },
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('FlowVersionHistoryDrawer', () => {
    it('renders list of versions with active, historical and draft badges', () => {
      render(
        <FlowVersionHistoryDrawer
          flowName="Flujo de Atención"
          activeVersionId="ver-2"
          currentVersionId="ver-3"
          versions={mockVersions}
          publications={mockPublications}
          isOpen={true}
          canPublish={true}
          canEdit={true}
          onClose={vi.fn()}
          onSelectVersion={vi.fn()}
          onCloneVersion={vi.fn()}
          onPublishVersion={vi.fn()}
          onRollbackVersion={vi.fn()}
          onCompareVersions={vi.fn()}
        />
      );

      expect(screen.getByText('Historial de Versiones')).toBeInTheDocument();
      expect(screen.getByText('Flujo de Atención')).toBeInTheDocument();

      // Check version items by version label
      expect(screen.getByText('v3')).toBeInTheDocument();
      expect(screen.getByText('v2')).toBeInTheDocument();
      expect(screen.getByText('v1')).toBeInTheDocument();

      // Check badges
      expect(screen.getByText(/Activa en Producción/i)).toBeInTheDocument();
      expect(screen.getByText(/Histórica/i)).toBeInTheDocument();
      expect(screen.getAllByText(/Borrador/i).length).toBeGreaterThan(0);
    });

    it('triggers onRollbackVersion when clicking rollback button on a historical version', () => {
      const onRollback = vi.fn();
      render(
        <FlowVersionHistoryDrawer
          flowName="Flujo de Atención"
          activeVersionId="ver-2"
          currentVersionId="ver-3"
          versions={mockVersions}
          publications={mockPublications}
          isOpen={true}
          canPublish={true}
          canEdit={true}
          onClose={vi.fn()}
          onSelectVersion={vi.fn()}
          onCloneVersion={vi.fn()}
          onPublishVersion={vi.fn()}
          onRollbackVersion={onRollback}
          onCompareVersions={vi.fn()}
        />
      );

      // Ver 1 is historical (published but not active), so it has a rollback button
      const rollbackButtons = screen.getAllByRole('button', { name: /Restaurar \(Rollback\)/i });
      expect(rollbackButtons.length).toBeGreaterThan(0);
      fireEvent.click(rollbackButtons[0]);

      expect(onRollback).toHaveBeenCalledWith(mockVersions[0]);
    });

    it('switches to audit log tab and displays publication history', () => {
      render(
        <FlowVersionHistoryDrawer
          flowName="Flujo de Atención"
          activeVersionId="ver-2"
          currentVersionId="ver-3"
          versions={mockVersions}
          publications={mockPublications}
          isOpen={true}
          canPublish={true}
          canEdit={true}
          onClose={vi.fn()}
          onSelectVersion={vi.fn()}
          onCloneVersion={vi.fn()}
          onPublishVersion={vi.fn()}
          onRollbackVersion={vi.fn()}
          onCompareVersions={vi.fn()}
        />
      );

      // Click tab Auditoría
      const auditTab = screen.getByRole('button', { name: /Auditoría/i });
      fireEvent.click(auditTab);

      expect(screen.getByText(/Actualización importante/i)).toBeInTheDocument();
      expect(screen.getByText(/Lanzamiento inicial/i)).toBeInTheDocument();
      expect(screen.getAllByText(/user-1/i).length).toBeGreaterThan(0);
    });
  });

  describe('FlowRollbackModal', () => {
    it('renders rollback confirmation modal with warnings and triggers onConfirm with notes', async () => {
      const onConfirm = vi.fn().mockResolvedValue(undefined);
      const onClose = vi.fn();

      render(
        <FlowRollbackModal
          version={mockVersions[0]}
          activeVersionNumber={2}
          isOpen={true}
          onClose={onClose}
          onConfirm={onConfirm}
        />
      );

      expect(screen.getByText(/Restaurar Versión Histórica v1/i)).toBeInTheDocument();
      expect(screen.getByText(/Estás por restaurar la versión v1/i)).toBeInTheDocument();
      expect(screen.getByText(/Las conversaciones actuales continuarán/i)).toBeInTheDocument();

      const notesInput = screen.getByPlaceholderText(/Incidencia en producción/i);
      fireEvent.change(notesInput, { target: { value: 'Bug crítico en menú' } });

      const submitBtn = screen.getByRole('button', { name: /Confirmar Rollback a v1/i });
      fireEvent.click(submitBtn);

      await waitFor(() => {
        expect(onConfirm).toHaveBeenCalledWith('Bug crítico en menú');
        expect(onClose).toHaveBeenCalled();
      });
    });
  });

  describe('FlowVersionCompareModal', () => {
    it('fetches and renders semantic comparison between versions', async () => {
      const mockDiffResult: FlowComparisonResult = {
        source_version: { id: 'ver-1', version_number: 1, status: 'published' },
        target_version: { id: 'ver-2', version_number: 2, status: 'published' },
        is_identical: false,
        summary: '1 nodo agregado, 1 nodo movido',
        stats: {
          nodes_added: 1,
          nodes_removed: 0,
          nodes_modified: 0,
          nodes_moved: 1,
          edges_added: 0,
          edges_removed: 0,
          variables_added: 0,
          variables_removed: 0,
        },
        highlights: [
          'Nuevo nodo agregado: "Menú Principal" (menu)',
          'Nodo "Inicio" desplazado en el lienzo sin cambios lógicos',
        ],
        nodes: {
          added: [{ node_key: 'menu', type: 'menu', name: 'Menú Principal' }],
          removed: [],
          modified: [],
          moved: [{ node_key: 'start', name: 'Inicio', from: { x: 0, y: 0 }, to: { x: 50, y: 50 } }],
        },
        edges: { added: [], removed: [] },
        variables: { added: [], removed: [], modified: [] },
      };

      (api.compareFlowVersions as any).mockResolvedValue({
        success: true,
        data: mockDiffResult,
      });

      render(
        <FlowVersionCompareModal
          flowId="flow-123"
          versions={mockVersions}
          initialSourceVersionId="ver-1"
          initialTargetVersionId="ver-2"
          isOpen={true}
          onClose={vi.fn()}
        />
      );

      expect(screen.getByText('Comparador Semántico de Versiones')).toBeInTheDocument();

      await waitFor(() => {
        expect(api.compareFlowVersions).toHaveBeenCalledWith('flow-123', 'ver-1', 'ver-2');
        expect(screen.getByText('1 nodo agregado, 1 nodo movido')).toBeInTheDocument();
        expect(screen.getByText(/Nuevo nodo agregado: "Menú Principal"/i)).toBeInTheDocument();
      });
    });
  });
});
