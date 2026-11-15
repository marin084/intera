import React, { useState } from 'react';
import { FunnelsTab } from './FunnelsTab';
import { UsersAndSessionsTab } from './UsersAndSessionsTab';
import { RealtimeTab } from './RealtimeTab';
import { AgentMetricsTab } from './AgentMetricsTab';
import { useAuth } from '../../context/useAuth';
import {
  BarChart3,
  Clock,
  Users,
  UserCheck,
  Layers,
  ChevronRight,
} from 'lucide-react';

export type DashboardTabId =
  | 'realtime'
  | 'users_and_sessions'
  | 'agent_metrics'
  | 'funnels';

interface DashboardsViewProps {
  initialTab?: DashboardTabId;
  initialFlowId?: string;
  onOpenConversation?: (conversationId: string) => void;
}

const TAB_LABELS: Record<DashboardTabId, string> = {
  realtime: 'Tiempo Real',
  users_and_sessions: 'Usuarios y Sesiones',
  agent_metrics: 'Métricas de Agentes',
  funnels: 'Funnels',
};

export const DashboardsView: React.FC<DashboardsViewProps> = ({
  initialTab,
  initialFlowId,
  onOpenConversation,
}) => {
  const { hasRole, hasPermission } = useAuth();
  const isSupervisorOrAdmin = hasRole('super_admin') || hasRole('admin') || hasRole('supervisor') || hasPermission('conversations.view_all');
  const isAgentOnly = !isSupervisorOrAdmin;

  const defaultTab = isAgentOnly ? 'agent_metrics' : (initialTab || 'funnels');
  const [activeTab, setActiveTab] = useState<DashboardTabId>(defaultTab);

  const currentTab = isAgentOnly ? 'agent_metrics' : activeTab;

  const allTabs: Array<{ id: DashboardTabId; label: string; icon: React.FC<{ size: number; style?: React.CSSProperties }> }> = [
    { id: 'realtime', label: 'TIEMPO REAL', icon: Clock },
    { id: 'users_and_sessions', label: 'USUARIOS Y SESIONES', icon: Users },
    { id: 'agent_metrics', label: 'MÉTRICAS DE AGENTES', icon: UserCheck },
    { id: 'funnels', label: 'FUNNELS', icon: Layers },
  ];

  const visibleTabs = isAgentOnly
    ? allTabs.filter((t) => t.id === 'agent_metrics')
    : allTabs;

  const renderTabContent = () => {
    switch (currentTab) {
      case 'funnels':
        return <FunnelsTab initialFlowId={initialFlowId} onOpenConversation={onOpenConversation} />;

      case 'users_and_sessions':
        return <UsersAndSessionsTab onOpenConversation={onOpenConversation} />;

      case 'realtime':
        return <RealtimeTab onOpenConversation={onOpenConversation} />;

      case 'agent_metrics':
        return <AgentMetricsTab onOpenConversation={onOpenConversation} />;

      default:
        return <FunnelsTab initialFlowId={initialFlowId} onOpenConversation={onOpenConversation} />;
    }
  };

  return (
    <div style={{ width: '100%', minWidth: 0, display: 'flex', flexDirection: 'column', gap: '1.25rem' }} data-testid="dashboards-view">
      {/* Dynamic Breadcrumb Header */}
      <div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.8rem', color: 'var(--text-muted)', marginBottom: '0.35rem' }} data-testid="dashboards-breadcrumb">
          {isAgentOnly ? (
            <>
              <span>Métricas</span>
              <ChevronRight size={13} />
              <span style={{ color: 'var(--primary)', fontWeight: 600 }}>Mis Métricas de Atención</span>
            </>
          ) : (
            <>
              <span>Dashboards</span>
              <ChevronRight size={13} />
              <span style={{ color: 'var(--primary)', fontWeight: 600 }}>{TAB_LABELS[currentTab]}</span>
            </>
          )}
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '0.65rem' }}>
          <BarChart3 size={24} style={{ color: 'var(--primary)' }} />
          <h1 style={{ fontSize: '1.5rem', fontWeight: 800, margin: 0, color: 'var(--text-main)', letterSpacing: '-0.3px' }}>
            {isAgentOnly ? 'Mis Métricas de Atención' : 'Analítica y Dashboards Operativos'}
          </h1>
        </div>
      </div>

      {/* Botmaker-Style Horizontal Tabs Bar */}
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: '0.5rem',
          borderBottom: '2px solid var(--border)',
          overflowX: 'auto',
          paddingBottom: '2px',
        }}
        data-testid="dashboards-tabs-bar"
      >
        {visibleTabs.map((tab) => {
          const isActive = currentTab === tab.id;
          const Icon = tab.icon;
          return (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              data-testid={`dashboard-tab-${tab.id}`}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '0.5rem',
                padding: '0.75rem 1rem',
                border: 'none',
                background: 'transparent',
                fontSize: '0.8rem',
                fontWeight: isActive ? 700 : 600,
                color: isActive ? 'var(--primary)' : 'var(--text-muted)',
                borderBottom: isActive ? '3px solid var(--primary)' : '3px solid transparent',
                marginBottom: '-2px',
                cursor: 'pointer',
                whiteSpace: 'nowrap',
                transition: 'all 0.2s ease',
              }}
            >
              <Icon size={15} style={{ color: isActive ? 'var(--primary)' : 'inherit' }} />
              {tab.label}
            </button>
          );
        })}
      </div>

      {/* Tab Content */}
      <div style={{ marginTop: '0.5rem' }}>
        {renderTabContent()}
      </div>
    </div>
  );
};

export default DashboardsView;
