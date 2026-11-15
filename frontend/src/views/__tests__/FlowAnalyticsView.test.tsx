import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { FlowAnalyticsView } from '../FlowAnalyticsView';
import { api } from '../../services/api';
import type { FlowAnalyticsData } from '../../types';

vi.mock('../../services/api', () => ({
  api: {
    getFlows: vi.fn(),
    getChannels: vi.fn(),
    getFlowAnalytics: vi.fn(),
  },
}));

describe('FlowAnalyticsView', () => {
  const mockFlows = [
    {
      id: 'flow-1',
      name: 'Flujo de Bienvenida Iglesia',
      description: 'Menú principal de atención',
      status: 'published',
      channel_applicability: ['webchat', 'whatsapp'],
      created_at: '2026-10-01T00:00:00Z',
      updated_at: '2026-10-05T00:00:00Z',
    },
    {
      id: 'flow-2',
      name: 'Flujo de Oración Especial',
      description: 'Campaña especial',
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
      name: 'WhatsApp Iglesia',
      type: 'whatsapp',
      is_active: true,
    },
  ];

  const mockAnalyticsData: FlowAnalyticsData = {
    flow_id: 'flow-1',
    flow_name: 'Flujo de Bienvenida Iglesia',
    date_range: {
      from: '2026-10-08T00:00:00Z',
      to: '2026-11-07T23:59:59Z',
      from_formatted: '2026-10-08',
      to_formatted: '2026-11-07',
    },
    kpis: {
      total_executions: 1420,
      unique_contacts: 980,
      completed_executions: 1065,
      completion_rate: 75.0,
      dropoff_count: 142,
      dropoff_rate: 10.0,
      top_section: {
        title: 'Petición de Oración',
        clicks_count: 632,
        percentage: 44.5,
        menu_title: 'Menú Principal',
      },
    },
    menu_breakdown: [
      {
        node_id: 'node-menu-1',
        node_key: 'menu_principal',
        node_title: 'Menú Principal',
        total_selections: 1420,
        options: [
          {
            option_id: '1',
            title: 'Petición de Oración',
            target_node_id: 'node-oracion',
            target_node_key: 'nodo_oracion',
            clicks_count: 632,
            percentage: 44.5,
          },
          {
            option_id: '2',
            title: 'Ofrendar',
            target_node_id: 'node-ofrendar',
            target_node_key: 'nodo_ofrendar',
            clicks_count: 426,
            percentage: 30.0,
          },
          {
            option_id: '3',
            title: 'Horarios de Culto',
            target_node_id: 'node-horarios',
            target_node_key: 'nodo_horarios',
            clicks_count: 362,
            percentage: 25.5,
          },
        ],
      },
    ],
    time_series: [
      { date: '2026-11-01', executions: 45, completed: 35 },
      { date: '2026-11-02', executions: 50, completed: 40 },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.getFlows).mockResolvedValue({ data: mockFlows } as any);
    vi.mocked(api.getChannels).mockResolvedValue({ data: mockChannels } as any);
    vi.mocked(api.getFlowAnalytics).mockResolvedValue({ data: mockAnalyticsData } as any);
  });

  it('renders KPI metric cards and menu breakdown correctly', async () => {
    render(<FlowAnalyticsView initialFlowId="flow-1" />);

    // Wait for analytics data to be loaded
    await waitFor(() => {
      expect(screen.getByTestId('kpi-cards-grid')).toBeInTheDocument();
    });

    // Check KPI Cards
    expect(screen.getByTestId('kpi-total-executions')).toHaveTextContent('1,420');
    expect(screen.getByTestId('kpi-unique-contacts')).toHaveTextContent('980');
    expect(screen.getByTestId('kpi-top-section')).toHaveTextContent('Petición de Oración');
    expect(screen.getByTestId('kpi-top-section')).toHaveTextContent('44.5%');
    expect(screen.getByTestId('kpi-resolution-rate')).toHaveTextContent('75%');
    expect(screen.getByTestId('kpi-resolution-rate')).toHaveTextContent('Abandono: 10%');

    // Check Menu Breakdown Section
    expect(screen.getByText('Menú Principal')).toBeInTheDocument();
    expect(screen.getByText('1,420 clics totales')).toBeInTheDocument();

    // Check Option Rows via specific testids
    const option1Row = screen.getByTestId('option-row-1');
    expect(within(option1Row).getByText('Petición de Oración')).toBeInTheDocument();
    expect(within(option1Row).getByText('632 clics')).toBeInTheDocument();
    expect(within(option1Row).getByText('44.5%')).toBeInTheDocument();

    const option2Row = screen.getByTestId('option-row-2');
    expect(within(option2Row).getByText('Ofrendar')).toBeInTheDocument();
    expect(within(option2Row).getByText('426 clics')).toBeInTheDocument();
    expect(within(option2Row).getByText('30%')).toBeInTheDocument();

    const option3Row = screen.getByTestId('option-row-3');
    expect(within(option3Row).getByText('Horarios de Culto')).toBeInTheDocument();
    expect(within(option3Row).getByText('362 clics')).toBeInTheDocument();
    expect(within(option3Row).getByText('25.5%')).toBeInTheDocument();
  });

  it('triggers analytics re-fetch when quick date range button is clicked', async () => {
    render(<FlowAnalyticsView initialFlowId="flow-1" />);

    await waitFor(() => {
      expect(api.getFlowAnalytics).toHaveBeenCalledTimes(1);
    });

    // Click on '7 días' quick range
    const btn7d = screen.getByTestId('quick-range-7d');
    fireEvent.click(btn7d);

    await waitFor(() => {
      expect(api.getFlowAnalytics).toHaveBeenCalledTimes(2);
    });

    // Click on 'Hoy'
    const btnToday = screen.getByTestId('quick-range-today');
    fireEvent.click(btnToday);

    await waitFor(() => {
      expect(api.getFlowAnalytics).toHaveBeenCalledTimes(3);
    });
  });

  it('triggers analytics re-fetch when changing channel and flow dropdowns', async () => {
    render(<FlowAnalyticsView initialFlowId="flow-1" />);

    await waitFor(() => {
      expect(api.getFlowAnalytics).toHaveBeenCalledTimes(1);
    });

    // Change channel
    const channelSelect = screen.getByTestId('channel-selector');
    fireEvent.change(channelSelect, { target: { value: 'chan-2' } });

    await waitFor(() => {
      expect(api.getFlowAnalytics).toHaveBeenCalledWith('flow-1', expect.objectContaining({
        channel_id: 'chan-2',
      }));
    });

    // Change flow
    const flowSelect = screen.getByTestId('flow-selector');
    fireEvent.change(flowSelect, { target: { value: 'flow-2' } });

    await waitFor(() => {
      expect(api.getFlowAnalytics).toHaveBeenCalledWith('flow-2', expect.objectContaining({
        channel_id: 'chan-2',
      }));
    });
  });

  it('renders friendly empty state when there are 0 interactions', async () => {
    const emptyAnalytics: FlowAnalyticsData = {
      flow_id: 'flow-1',
      flow_name: 'Flujo de Bienvenida Iglesia',
      date_range: {
        from: '2026-10-08T00:00:00Z',
        to: '2026-11-07T23:59:59Z',
      },
      kpis: {
        total_executions: 0,
        unique_contacts: 0,
        completed_executions: 0,
        completion_rate: 0.0,
        dropoff_count: 0,
        dropoff_rate: 0.0,
        top_section: null,
      },
      menu_breakdown: [],
      time_series: [],
    };

    vi.mocked(api.getFlowAnalytics).mockResolvedValue({ data: emptyAnalytics } as any);

    render(<FlowAnalyticsView initialFlowId="flow-1" />);

    await waitFor(() => {
      expect(screen.getByTestId('analytics-empty-state')).toBeInTheDocument();
    });

    expect(screen.getByText('Sin actividad registrada en este periodo')).toBeInTheDocument();
    expect(screen.getByTestId('kpi-total-executions')).toHaveTextContent('0');
  });

  it('renders back button and calls onBack callback when clicked', async () => {
    const mockBack = vi.fn();
    render(<FlowAnalyticsView initialFlowId="flow-1" onBack={mockBack} />);

    await waitFor(() => {
      expect(screen.getByTestId('back-button')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByTestId('back-button'));
    expect(mockBack).toHaveBeenCalledTimes(1);
  });
});
