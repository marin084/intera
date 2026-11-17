import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { DashboardsView } from '../DashboardsView';
import { api } from '../../../services/api';
import type {
  FlowAnalyticsData,
  UsersAndSessionsData,
  FunnelContact,
  RealtimeData,
  AgentMetricsData,
  User as UserType,
} from '../../../types';

const mockAuth = {
  user: { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai' } as any,
  hasRole: vi.fn(),
  hasPermission: vi.fn(),
};

vi.mock('../../../context/useAuth', () => ({
  useAuth: () => mockAuth,
}));

vi.mock('../../../services/api', () => ({
  api: {
    getFlows: vi.fn(),
    getChannels: vi.fn(),
    getUsers: vi.fn(),
    getFunnels: vi.fn(),
    getFunnelContacts: vi.fn(),
    getFunnelContactsExportUrl: vi.fn().mockReturnValue('/api/v1/analytics/funnels/contacts?export=csv&flow_id=flow-1'),
    getUsersAndSessions: vi.fn(),
    getUsersAndSessionsExportUrl: vi.fn().mockReturnValue('/api/v1/analytics/users-and-sessions/export?channel_id='),
    getStepContacts: vi.fn(),
    getStepContactsExportUrl: vi.fn().mockReturnValue('/api/v1/analytics/funnels/step-contacts?export=csv&flow_id=flow-1&step_number=1'),
    getSavedFunnels: vi.fn(),
    createSavedFunnel: vi.fn(),
    updateSavedFunnel: vi.fn(),
    deleteSavedFunnel: vi.fn(),
    getRealtimeAnalytics: vi.fn(),
    getAgentMetrics: vi.fn(),
    getAgentsExportSummaryUrl: vi.fn().mockReturnValue('/api/v1/analytics/agents/export-summary'),
    getAgentsExportConversationsUrl: vi.fn().mockReturnValue('/api/v1/analytics/agents/export-conversations'),
  },
}));

describe('DashboardsView & Analytics Tabs (Fase 1 y Fase 2)', () => {
  const mockFlows = [
    {
      id: 'flow-1',
      name: 'Flujo de Bienvenida Conexa',
      description: 'Menú interactivo de atención',
      status: 'published',
      channel_applicability: ['webchat', 'whatsapp'],
      created_at: '2026-10-01T00:00:00Z',
      updated_at: '2026-10-05T00:00:00Z',
    },
    {
      id: 'flow-2',
      name: 'Flujo Soporte',
      description: 'Flujo secundario',
      status: 'draft',
      channel_applicability: ['webchat'],
      created_at: '2026-10-02T00:00:00Z',
      updated_at: '2026-10-06T00:00:00Z',
    },
  ];

  const mockChannels = [
    {
      id: 'chan-1',
      name: 'Webchat Principal',
      type: 'webchat',
      is_active: true,
    },
    {
      id: 'chan-2',
      name: 'WhatsApp Soporte',
      type: 'whatsapp',
      is_active: true,
    },
  ];

  const mockUsers: UserType[] = [
    {
      id: 1,
      name: 'Carlos Operador',
      email: 'carlos@test.com',
      is_active: true,
      roles: ['agent'],
      last_login_at: null,
      created_at: '2026-10-01T00:00:00Z',
    },
    {
      id: 2,
      name: 'Mariana Agente',
      email: 'mariana@test.com',
      is_active: true,
      roles: ['agent'],
      last_login_at: null,
      created_at: '2026-10-02T00:00:00Z',
    },
  ];

  const mockFunnelData: FlowAnalyticsData = {
    flow_id: 'flow-1',
    flow_name: 'Flujo de Bienvenida Conexa',
    date_range: {
      from: '2026-10-08T00:00:00Z',
      to: '2026-11-07T23:59:59Z',
      from_formatted: '2026-10-08',
      to_formatted: '2026-11-07',
    },
    kpis: {
      total_executions: 1200,
      unique_contacts: 850,
      completed_executions: 900,
      completion_rate: 75.0,
      dropoff_count: 120,
      dropoff_rate: 10.0,
      top_section: {
        title: 'Petición de Oración',
        clicks_count: 600,
        percentage: 50.0,
        menu_title: 'Menú Principal',
      },
    },
    menu_breakdown: [
      {
        node_id: 'node-menu-1',
        node_key: 'menu_principal',
        node_title: 'Menú Principal',
        total_selections: 1200,
        options: [
          {
            option_id: 'opt-1',
            title: 'Petición de Oración',
            target_node_id: 'node-prayer',
            target_node_key: 'target_prayer',
            clicks_count: 600,
            percentage: 50.0,
          },
          {
            option_id: 'opt-2',
            title: 'Ofrendar',
            target_node_id: 'node-tithe',
            target_node_key: 'target_tithe',
            clicks_count: 360,
            percentage: 30.0,
          },
        ],
      },
    ],
    time_series: [],
    available_steps: [
      {
        id: 'node_start',
        node_id: 'node-start',
        node_key: 'start_node',
        node_type: 'start',
        label: 'Inicio',
      },
      {
        id: 'opt_menu_1',
        node_id: 'node-menu-1',
        node_key: 'menu_principal',
        node_type: 'menu_option',
        label: 'Menú Principal › Petición de Oración',
        option_id: 'opt-1',
        option_title: 'Petición de Oración',
      },
      {
        id: 'opt_menu_2',
        node_id: 'node-menu-1',
        node_key: 'menu_principal',
        node_type: 'menu_option',
        label: 'Menú Principal › Ofrendar',
        option_id: 'opt-2',
        option_title: 'Ofrendar',
      },
    ],
    default_steps: [
      {
        id: 'step_1',
        node_id: 'node-start',
        node_key: 'start_node',
        node_type: 'start',
        label: 'Inicio',
        is_active: true,
      },
      {
        id: 'step_2',
        node_id: 'node-menu-1',
        node_key: 'menu_principal',
        node_type: 'menu_option',
        label: 'Menú Principal › Petición de Oración',
        option_id: 'opt-1',
        option_title: 'Petición de Oración',
        is_active: true,
      },
    ],
    sequential_funnel: [
      {
        step_number: 1,
        label: 'Inicio',
        node_id: 'node-start',
        node_key: 'start_node',
        users_count: 850,
        conversion_from_previous: 100.0,
        dropoff_from_previous: 0.0,
        conversion_from_first: 100.0,
      },
      {
        step_number: 2,
        label: 'Menú Principal › Petición de Oración',
        node_id: 'node-menu-1',
        node_key: 'menu_principal',
        option_id: 'opt-1',
        option_title: 'Petición de Oración',
        users_count: 600,
        conversion_from_previous: 70.6,
        dropoff_from_previous: 29.4,
        conversion_from_first: 70.6,
      },
    ],
  };

  const mockUsersAndSessionsData: UsersAndSessionsData = {
    date_range: {
      from: '2026-10-08T00:00:00Z',
      to: '2026-11-07T23:59:59Z',
      from_formatted: '2026-10-08',
      to_formatted: '2026-11-07',
    },
    kpis: {
      total_sessions: 2500,
      unique_contacts: 1800,
      agent_sessions: 800,
      bot_only_sessions: 1700,
      closed_by_agents: 650,
      agent_session_percentage: 32.0,
      bot_only_percentage: 68.0,
      message_counts: {
        user_messages: 5500,
        bot_messages: 4500,
        agent_messages: 2000,
        total_messages: 12000,
      },
    },
    channel_distribution: [
      {
        channel_id: 'chan-2',
        channel_type: 'whatsapp',
        channel_name: 'WhatsApp Soporte',
        count: 1750,
        percentage: 70.0,
      },
      {
        channel_id: 'chan-1',
        channel_type: 'webchat',
        channel_name: 'Webchat Principal',
        count: 750,
        percentage: 30.0,
      },
    ],
    time_series: [],
  };

  const mockContactsList: FunnelContact[] = [
    {
      contact_id: 'contact-1',
      contact_name: 'Juan Pérez',
      phone_number: '+5491112345678',
      email: 'juan@test.com',
      channel_type: 'whatsapp',
      channel_name: 'WhatsApp Soporte',
      conversation_id: 'conv-101',
      stepped_at: '2026-11-07T10:30:00Z',
      stepped_at_formatted: '07/11/2026 10:30',
    },
    {
      contact_id: 'contact-2',
      contact_name: 'María García',
      phone_number: null,
      email: 'maria@test.com',
      channel_type: 'webchat',
      channel_name: 'Webchat Principal',
      conversation_id: 'conv-102',
      stepped_at: '2026-11-07T11:15:00Z',
      stepped_at_formatted: '07/11/2026 11:15',
    },
  ];

  const mockRealtimeData: RealtimeData = {
    last_hour_kpis: {
      active_webchat_visitors: 14,
      unread_chats: 5,
      chats_last_hour: 42,
      chats_previous_hour: 35,
      chats_trend_percentage: 20.0,
      messages_last_hour: {
        sent: 88,
        received: 134,
        total: 222,
      },
    },
    operating_times: {
      avg_queue_wait_seconds: 45,
      avg_queue_wait_formatted: '45s',
      avg_first_response_seconds: 90,
      avg_first_response_formatted: '1m 30s',
      avg_next_response_seconds: 60,
      avg_next_response_formatted: '1m',
      avg_resolution_seconds: 720,
      avg_resolution_formatted: '12m',
    },
    agent_conversations: {
      ongoing: 8,
      closed_today: 34,
      active_agents_count: 6,
    },
    channels_summary: [
      {
        channel_id: 'chan-1',
        channel_name: 'Webchat Principal',
        channel_type: 'webchat',
        is_active: true,
        ongoing_conversations: 5,
        closed_conversations: 20,
        waiting_response_count: 3,
        avg_response_time_seconds: 85,
        avg_response_time_formatted: '1m 25s',
      },
      {
        channel_id: 'chan-2',
        channel_name: 'WhatsApp Soporte',
        channel_type: 'whatsapp',
        is_active: true,
        ongoing_conversations: 3,
        closed_conversations: 14,
        waiting_response_count: 2,
        avg_response_time_seconds: 95,
        avg_response_time_formatted: '1m 35s',
      },
    ],
    team_sla_summary: [],
    updated_at: '2026-11-07T15:00:00Z',
  };

  const mockAgentMetricsData: AgentMetricsData = {
    date_range: {
      from: '2026-11-07T00:00:00Z',
      to: '2026-11-07T23:59:59Z',
      from_formatted: '2026-11-07',
      to_formatted: '2026-11-07',
    },
    kpis: {
      total_assigned: 48,
      total_active: 8,
      total_closed: 40,
      avg_first_response_seconds: 80,
      avg_first_response_formatted: '1m 20s',
      avg_response_time_seconds: 45,
      avg_response_time_formatted: '45s',
      avg_attention_duration_seconds: 650,
      avg_attention_duration_formatted: '10m 50s',
    },
    agents: [
      {
        agent_id: 1,
        name: 'Carlos Operador',
        email: 'carlos@test.com',
        avatar_url: null,
        roles: ['agent'],
        presence_status: 'online',
        last_seen_at: '2026-11-07T14:58:00Z',
        last_seen_formatted: 'Hace 2 minutos',
        channels: [{ id: 'chan-2', name: 'WhatsApp Soporte', type: 'whatsapp' }],
        assigned_conversations: 28,
        active_conversations: 5,
        closed_conversations: 23,
        closed_percentage: 57.5,
        waiting_response_count: 2,
        avg_first_response_seconds: 70,
        avg_first_response_formatted: '1m 10s',
        avg_response_time_seconds: 40,
        avg_response_time_formatted: '40s',
        avg_attention_duration_seconds: 600,
        avg_attention_duration_formatted: '10m',
      },
      {
        agent_id: 2,
        name: 'Mariana Agente',
        email: 'mariana@test.com',
        avatar_url: null,
        roles: ['agent'],
        presence_status: 'offline',
        last_seen_at: '2026-11-07T12:00:00Z',
        last_seen_formatted: 'Hace 3 horas',
        channels: [{ id: 'chan-1', name: 'Webchat Principal', type: 'webchat' }],
        assigned_conversations: 20,
        active_conversations: 3,
        closed_conversations: 17,
        closed_percentage: 42.5,
        waiting_response_count: 1,
        avg_first_response_seconds: 90,
        avg_first_response_formatted: '1m 30s',
        avg_response_time_seconds: 50,
        avg_response_time_formatted: '50s',
        avg_attention_duration_seconds: 700,
        avg_attention_duration_formatted: '11m 40s',
      },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    mockAuth.user = { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai' };
    mockAuth.hasRole.mockImplementation((role: string) => role === 'super_admin' || role === 'admin');
    mockAuth.hasPermission.mockReturnValue(true);

    (api.getFlows as any).mockResolvedValue({ data: mockFlows });
    (api.getChannels as any).mockResolvedValue({ data: mockChannels });
    (api.getUsers as any).mockResolvedValue({ data: mockUsers });
    (api.getFunnels as any).mockResolvedValue({ data: mockFunnelData });
    (api.getUsersAndSessions as any).mockResolvedValue({ data: mockUsersAndSessionsData });
    (api.getRealtimeAnalytics as any).mockResolvedValue({ data: mockRealtimeData });
    (api.getAgentMetrics as any).mockResolvedValue({ data: mockAgentMetricsData });
    (api.getFunnelContacts as any).mockResolvedValue({
      data: mockContactsList,
      total: 2,
      page: 1,
      last_page: 1,
    });
    (api.getStepContacts as any).mockResolvedValue({
      data: mockContactsList,
      total: 2,
      page: 1,
      last_page: 1,
    });
    (api.getSavedFunnels as any).mockResolvedValue({
      data: [
        {
          id: 'saved-funnel-1',
          name: 'Ruta Ofrendas a Peticiones',
          flow_id: 'flow-1',
          steps: [
            {
              id: 'step-1',
              label: 'Inicio',
              node_id: 'node-start',
              node_key: 'start_node',
              is_active: true,
            },
            {
              id: 'step-2',
              label: 'Menú Principal › Petición de Oración',
              node_id: 'node-menu-1',
              node_key: 'menu_principal',
              option_id: 'opt-1',
              option_title: 'Petición de Oración',
              is_active: true,
            },
          ],
          created_by: 1,
          creator: { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai' },
          created_at: '2026-10-05T00:00:00Z',
        },
      ],
    });
    (api.createSavedFunnel as any).mockImplementation((payload: any) =>
      Promise.resolve({
        data: {
          id: 'saved-funnel-new',
          ...payload,
          created_by: 1,
        },
        message: 'Embudo guardado exitosamente.',
      })
    );
    (api.updateSavedFunnel as any).mockImplementation((id: string, payload: any) =>
      Promise.resolve({
        data: {
          id,
          ...payload,
          name: payload.name || 'Ruta Ofrendas a Peticiones',
        },
        message: 'Embudo actualizado exitosamente.',
      })
    );
    (api.deleteSavedFunnel as any).mockResolvedValue({ message: 'Embudo eliminado exitosamente.' });
  });

  it('renders top navigation tabs and dynamic breadcrumb for admin', async () => {
    render(<DashboardsView />);

    const breadcrumb = screen.getByTestId('dashboards-breadcrumb');
    expect(within(breadcrumb).getByText('Dashboards')).toBeInTheDocument();
    expect(within(breadcrumb).getByText('Funnels')).toBeInTheDocument();

    expect(screen.getByRole('button', { name: /TIEMPO REAL/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /USUARIOS Y SESIONES/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /MÉTRICAS DE AGENTES/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /FUNNELS/i })).toBeInTheDocument();

    // Obsolete tabs permanently removed
    expect(screen.queryByRole('button', { name: /PRODUCTIVIDAD DE AGENTES/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /MÉTRICAS DE INICIO DE CONVERSACIÓN/i })).not.toBeInTheDocument();
  });

  it('updates dynamic breadcrumb when switching tabs', async () => {
    render(<DashboardsView />);

    const breadcrumb = screen.getByTestId('dashboards-breadcrumb');

    // Click TIEMPO REAL
    fireEvent.click(screen.getByRole('button', { name: /TIEMPO REAL/i }));
    expect(within(breadcrumb).getByText('Tiempo Real')).toBeInTheDocument();

    // Click USUARIOS Y SESIONES
    fireEvent.click(screen.getByRole('button', { name: /USUARIOS Y SESIONES/i }));
    expect(within(breadcrumb).getByText('Usuarios y Sesiones')).toBeInTheDocument();

    // Click MÉTRICAS DE AGENTES
    fireEvent.click(screen.getByRole('button', { name: /MÉTRICAS DE AGENTES/i }));
    expect(within(breadcrumb).getByText('Métricas de Agentes')).toBeInTheDocument();

    // Click FUNNELS
    fireEvent.click(screen.getByRole('button', { name: /FUNNELS/i }));
    expect(within(breadcrumb).getByText('Funnels')).toBeInTheDocument();
  });

  it('renders restricted view for agent role: only MÉTRICAS DE AGENTES tab, agent breadcrumb, and agent badge', async () => {
    mockAuth.user = { id: 1, name: 'Carlos Operador', email: 'carlos@test.com' };
    mockAuth.hasRole.mockReturnValue(false);
    mockAuth.hasPermission.mockReturnValue(false);

    render(<DashboardsView />);

    // Breadcrumb displays agent path
    const breadcrumb = screen.getByTestId('dashboards-breadcrumb');
    expect(within(breadcrumb).getByText('Métricas')).toBeInTheDocument();
    expect(within(breadcrumb).getByText('Mis Métricas de Atención')).toBeInTheDocument();

    // Title
    expect(screen.getByRole('heading', { level: 1, name: 'Mis Métricas de Atención' })).toBeInTheDocument();

    // Only agent metrics tab is shown
    const tabsBar = screen.getByTestId('dashboards-tabs-bar');
    expect(within(tabsBar).getByRole('button', { name: /MÉTRICAS DE AGENTES/i })).toBeInTheDocument();
    expect(within(tabsBar).queryByRole('button', { name: /TIEMPO REAL/i })).not.toBeInTheDocument();
    expect(within(tabsBar).queryByRole('button', { name: /USUARIOS Y SESIONES/i })).not.toBeInTheDocument();
    expect(within(tabsBar).queryByRole('button', { name: /FUNNELS/i })).not.toBeInTheDocument();

    // Agent readonly badge displayed in AgentMetricsTab instead of dropdown
    await waitFor(() => {
      expect(screen.getByTestId('agent-readonly-badge')).toBeInTheDocument();
      expect(screen.getByText('Carlos Operador')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('agent-select')).not.toBeInTheDocument();
    expect(screen.queryByTestId('online-only-checkbox')).not.toBeInTheDocument();
  });

  it('loads and renders Funnels tab with sequential funnel visualization and without static menu breakdown cards', async () => {
    render(<DashboardsView initialTab="funnels" />);

    await waitFor(() => {
      expect(api.getFlows).toHaveBeenCalled();
      expect(api.getFunnels).toHaveBeenCalledWith(
        expect.objectContaining({
          flow_id: 'flow-1',
          from: expect.any(String),
          to: expect.any(String),
        })
      );
    });

    // Verify Sequential Funnel Visualization is present
    await waitFor(() => {
      expect(screen.getByTestId('sequential-funnel-visualization')).toBeInTheDocument();
      expect(screen.getByTestId('funnel-step-card-1')).toBeInTheDocument();
      expect(screen.getByTestId('funnel-step-card-2')).toBeInTheDocument();
    });

    // Verify static menu breakdown cards container is permanently removed
    expect(screen.queryByTestId('funnel-breakdown')).not.toBeInTheDocument();
  });

  it('configures sequential funnel: adds step, toggles active switch, and renders real funnel drop-off', async () => {
    render(<DashboardsView initialTab="funnels" />);

    // Wait for sequential funnel visualization to load
    await waitFor(() => {
      expect(screen.getByTestId('sequential-funnel-visualization')).toBeInTheDocument();
      expect(screen.getByTestId('funnel-step-card-1')).toBeInTheDocument();
      expect(screen.getByTestId('funnel-step-card-2')).toBeInTheDocument();
    });

    // Check step content and drop-off badge
    expect(screen.getByText('850 usuarios únicos')).toBeInTheDocument();
    expect(screen.getByText('600 usuarios únicos')).toBeInTheDocument();
    expect(screen.getByText(/Drop-off: -29.4% abandono/i)).toBeInTheDocument();
    expect(screen.getByText(/↓ 70.6% conversión/i)).toBeInTheDocument();

    // Check step configurator
    expect(screen.getByTestId('funnel-steps-configurator')).toBeInTheDocument();
    const toggleStep2 = screen.getByTestId('step-toggle-2');
    expect(toggleStep2).toBeChecked();

    // Toggle Step 2 off
    fireEvent.click(toggleStep2);
    expect(toggleStep2).not.toBeChecked();

    // Add another step from the dropdown
    const addStepSelect = screen.getByTestId('add-step-select');
    fireEvent.change(addStepSelect, { target: { value: 'opt_menu_2' } });

    await waitFor(() => {
      expect(api.getFunnels).toHaveBeenCalled();
    });
  });

  it('manages saved funnels: lists, selects, creates new funnel, saves changes, and deletes with confirm modal', async () => {
    render(<DashboardsView initialTab="funnels" />);

    // Wait for saved funnels to be fetched and populated in selector
    await waitFor(() => {
      expect(api.getSavedFunnels).toHaveBeenCalledWith('flow-1');
    });

    const funnelSelector = screen.getByTestId('saved-funnel-selector') as HTMLSelectElement;
    expect(funnelSelector).toBeInTheDocument();
    expect(screen.getByText(/Ruta Ofrendas a Peticiones/i)).toBeInTheDocument();

    // 1. Select the saved funnel
    fireEvent.change(funnelSelector, { target: { value: 'saved-funnel-1' } });
    expect(funnelSelector.value).toBe('saved-funnel-1');

    // "Guardar Cambios" and "Eliminar Funnel" buttons become available
    expect(screen.getByTestId('btn-save-funnel-changes')).toBeInTheDocument();
    expect(screen.getByTestId('btn-delete-funnel')).toBeInTheDocument();

    // 2. Click "Guardar Cambios"
    fireEvent.click(screen.getByTestId('btn-save-funnel-changes'));
    await waitFor(() => {
      expect(api.updateSavedFunnel).toHaveBeenCalledWith(
        'saved-funnel-1',
        expect.objectContaining({
          steps: expect.any(Array),
        })
      );
    });
    expect(screen.getByText(/Cambios guardados en "Ruta Ofrendas a Peticiones"/i)).toBeInTheDocument();

    // 3. Click "+ Nuevo Funnel"
    fireEvent.click(screen.getByTestId('btn-new-funnel'));
    expect(screen.getByTestId('new-funnel-modal')).toBeInTheDocument();

    const nameInput = screen.getByTestId('new-funnel-name-input');
    fireEvent.change(nameInput, { target: { value: 'Funnel Soporte Urgente' } });

    fireEvent.click(screen.getByTestId('confirm-new-funnel-btn'));
    await waitFor(() => {
      expect(api.createSavedFunnel).toHaveBeenCalledWith(
        expect.objectContaining({
          name: 'Funnel Soporte Urgente',
          flow_id: 'flow-1',
          steps: expect.any(Array),
        })
      );
    });

    // 4. Delete saved funnel with ConfirmModal
    // First select our saved funnel again
    fireEvent.change(funnelSelector, { target: { value: 'saved-funnel-1' } });
    const deleteBtn = screen.getByTestId('btn-delete-funnel');
    fireEvent.click(deleteBtn);

    // ConfirmModal should open
    expect(screen.getByTestId('delete-funnel-confirm-modal')).toBeInTheDocument();
    expect(screen.getByText(/¿Estás seguro de que deseas eliminar permanentemente el embudo/i)).toBeInTheDocument();

    // Click confirm delete
    fireEvent.click(screen.getByTestId('confirm-delete-funnel-btn'));
    await waitFor(() => {
      expect(api.deleteSavedFunnel).toHaveBeenCalledWith('saved-funnel-1');
    });
    expect(screen.getByText(/Embudo eliminado exitosamente/i)).toBeInTheDocument();
  });

  it('opens FunnelContactsModal for sequential step and displays drill-down list', async () => {
    render(<DashboardsView initialTab="funnels" />);

    await waitFor(() => {
      expect(screen.getByTestId('funnel-step-card-1')).toBeInTheDocument();
    });

    const stepContactsBtn = screen.getByTestId('funnel-step-contacts-btn-1');
    fireEvent.click(stepContactsBtn);

    await waitFor(() => {
      expect(api.getStepContacts).toHaveBeenCalledWith(
        expect.objectContaining({
          flow_id: 'flow-1',
          step_number: 1,
        })
      );
    });

    await waitFor(() => {
      expect(screen.getByText(/Contactos en: Paso 1/i)).toBeInTheDocument();
      expect(screen.getByText('Juan Pérez')).toBeInTheDocument();
    });

    // Close modal
    fireEvent.click(screen.getByTestId('close-modal-btn'));
    await waitFor(() => {
      expect(screen.queryByText(/Contactos en: Paso 1/i)).not.toBeInTheDocument();
    });
  });

  it('switches to Users and Sessions tab and displays KPIs and distribution', async () => {
    render(<DashboardsView />);

    const usersTab = screen.getByRole('button', { name: /USUARIOS Y SESIONES/i });
    fireEvent.click(usersTab);

    await waitFor(() => {
      expect(api.getUsersAndSessions).toHaveBeenCalled();
    });

    await waitFor(() => {
      expect(screen.getByText('2,500')).toBeInTheDocument();
      expect(screen.getByText('1,800')).toBeInTheDocument();
      expect(screen.getByText(/12,000/)).toBeInTheDocument();
      expect(screen.getByText(/\(32% del total\)/)).toBeInTheDocument();
      expect(screen.getByText('68%')).toBeInTheDocument();
    });

    const waRow = screen.getByTestId('channel-distribution-row-whatsapp');
    expect(within(waRow).getByText(/WhatsApp Soporte/i)).toBeInTheDocument();
    expect(within(waRow).getByText('70%')).toBeInTheDocument();

    const webchatRow = screen.getByTestId('channel-distribution-row-webchat');
    expect(within(webchatRow).getByText(/Webchat Principal/i)).toBeInTheDocument();
    expect(within(webchatRow).getByText('30%')).toBeInTheDocument();

    const exportBtn = screen.getByRole('link', { name: /Descargar usuarios/i });
    expect(exportBtn).toBeInTheDocument();
    expect(exportBtn.getAttribute('href')).toContain('/api/v1/analytics/users-and-sessions/export');
  });

  it('switches to Realtime tab and renders live monitoring KPIs and queues', async () => {
    render(<DashboardsView initialTab="realtime" />);

    await waitFor(() => {
      expect(api.getRealtimeAnalytics).toHaveBeenCalled();
    });

    // Check Last Hour KPIs
    await waitFor(() => {
      expect(screen.getAllByText('14').length).toBeGreaterThanOrEqual(1); // Visitantes Webchat
      expect(screen.getAllByText('5').length).toBeGreaterThanOrEqual(1); // Chats No Leídos
      expect(screen.getByText('42')).toBeInTheDocument(); // Chats en última hora
      expect(screen.getByText('88 / 134')).toBeInTheDocument(); // Mensajes Enviados / Recibidos
      expect(screen.getByText(/\+20%/)).toBeInTheDocument(); // Tendencia
    });

    // Check Operating Times
    expect(screen.getByText('45s')).toBeInTheDocument(); // Espera en cola
    expect(screen.getByText('1m 30s')).toBeInTheDocument(); // Primera respuesta
    expect(screen.getByText('12m')).toBeInTheDocument(); // Resolución media

    // Check Channel Summary Table
    const waRow = screen.getByTestId('channel-row-whatsapp');
    expect(within(waRow).getByText('WhatsApp Soporte')).toBeInTheDocument();
    expect(within(waRow).getByText('1m 35s')).toBeInTheDocument();

    const webchatRow = screen.getByTestId('channel-row-webchat');
    expect(within(webchatRow).getByText('Webchat Principal')).toBeInTheDocument();
    expect(within(webchatRow).getByText('1m 25s')).toBeInTheDocument();
  });

  it('switches to Agent Metrics tab and displays KPIs, operators table and export buttons', async () => {
    render(<DashboardsView initialTab="agent_metrics" />);

    await waitFor(() => {
      expect(api.getAgentMetrics).toHaveBeenCalled();
      expect(api.getUsers).toHaveBeenCalled();
    });

    // Check Consolidated KPIs
    await waitFor(() => {
      expect(screen.getByText('48')).toBeInTheDocument(); // Total asignadas
      expect(screen.getByText('1m 20s')).toBeInTheDocument(); // FRT medio
      expect(screen.getByText('45s')).toBeInTheDocument(); // Tiempo medio respuesta
      expect(screen.getByText('10m 50s')).toBeInTheDocument(); // Duración media
    });

    // Check Agents in Table
    const carlosRow = screen.getByTestId('agent-row-1');
    expect(within(carlosRow).getByText('Carlos Operador')).toBeInTheDocument();
    expect(within(carlosRow).getByText(/57.5%/)).toBeInTheDocument();
    expect(within(carlosRow).getByText('1m 10s')).toBeInTheDocument();

    const marianaRow = screen.getByTestId('agent-row-2');
    expect(within(marianaRow).getByText('Mariana Agente')).toBeInTheDocument();
    expect(within(marianaRow).getByText(/42.5%/)).toBeInTheDocument();
    expect(within(marianaRow).getByText('1m 30s')).toBeInTheDocument();

    // Check Export Buttons
    const exportSummaryBtn = screen.getByTestId('export-agents-summary-btn');
    expect(exportSummaryBtn).toBeInTheDocument();
    expect(exportSummaryBtn.getAttribute('href')).toContain('/api/v1/analytics/agents/export-summary');

    const exportConvsBtn = screen.getByTestId('export-conversations-detail-btn');
    expect(exportConvsBtn).toBeInTheDocument();
    expect(exportConvsBtn.getAttribute('href')).toContain('/api/v1/analytics/agents/export-conversations');
  });
});
