import React, { useState, useEffect, useLayoutEffect, Suspense, lazy } from 'react';
import { AuthProvider } from './context/AuthContext';
import { useAuth } from './context/useAuth';
import { ToastProvider } from './context/ToastContext';
import { api } from './services/api';
import { LoginView } from './views/LoginView';
import { Layout } from './components/Layout';

// Dynamic lazy imports for code-splitting heavy views and the flow canvas
const UsersView = lazy(() => import('./views/UsersView').then((m) => ({ default: m.UsersView })));
const RolesView = lazy(() => import('./views/RolesView').then((m) => ({ default: m.RolesView })));
const ChannelsView = lazy(() => import('./views/ChannelsView').then((m) => ({ default: m.ChannelsView })));
const FlowsView = lazy(() => import('./views/FlowsView').then((m) => ({ default: m.FlowsView })));
const ConversationsView = lazy(() => import('./views/ConversationsView').then((m) => ({ default: m.ConversationsView })));
const AuditLogsView = lazy(() => import('./views/AuditLogsView').then((m) => ({ default: m.AuditLogsView })));
const AccountView = lazy(() => import('./views/AccountView').then((m) => ({ default: m.AccountView })));
const AdminSettingsView = lazy(() => import('./views/AdminSettingsView').then((m) => ({ default: m.AdminSettingsView })));
const DashboardsView = lazy(() => import('./views/Analytics/DashboardsView').then((m) => ({ default: m.DashboardsView })));
const FlowEditor = lazy(() => import('./components/flow-editor/FlowEditor').then((m) => ({ default: m.FlowEditor })));

const ViewSuspenseFallback: React.FC = () => (
  <div
    style={{
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      padding: '4rem 1rem',
      height: '100%',
      minHeight: '260px',
      color: '#61726d',
      gap: '0.75rem',
    }}
  >
    <div
      style={{
        width: '32px',
        height: '32px',
        border: '3px solid #d9e3de',
        borderTopColor: '#5d8f82',
        borderRadius: '50%',
        animation: 'spin 0.8s linear infinite',
      }}
    />
    <span style={{ fontSize: '0.875rem', fontWeight: 500 }}>Cargando módulo...</span>
  </div>
);

const EditorSuspenseFallback: React.FC = () => (
  <div
    style={{
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      height: '100vh',
      background: '#263c38',
      color: '#ffffff',
      gap: '1rem',
    }}
  >
    <div
      style={{
        width: '40px',
        height: '40px',
        border: '3px solid rgba(255, 255, 255, 0.2)',
        borderTopColor: '#5d8f82',
        borderRadius: '50%',
        animation: 'spin 0.8s linear infinite',
      }}
    />
    <div style={{ textAlign: 'center' }}>
      <p style={{ margin: 0, fontWeight: 600, fontSize: '1rem' }}>Cargando Constructor de Flujos...</p>
      <p style={{ margin: '0.25rem 0 0', fontSize: '0.8rem', color: '#80918c' }}>Inicializando lienzo y dependencias gráficas</p>
    </div>
  </div>
);

interface EditorRouteState {
  flowId: string;
  versionId?: string;
}

function parseEditorPath(pathname: string): EditorRouteState | null {
  const match = pathname.match(/^\/flows\/([a-zA-Z0-9_-]+)\/editor(?:\/([a-zA-Z0-9_-]+))?/);
  if (match) {
    return {
      flowId: match[1],
      versionId: match[2] || undefined,
    };
  }
  return null;
}

function parseAnalyticsPath(pathname: string): { flowId?: string } | null {
  if (pathname === '/analytics' || pathname === '/dashboards') {
    return {};
  }
  const match = pathname.match(/^\/flows\/([a-zA-Z0-9_-]+)\/analytics/);
  if (match) {
    return { flowId: match[1] };
  }
  return null;
}

const TAB_PATHS: Record<string, string> = {
  conversations: '/conversations',
  flows: '/flows',
  analytics: '/analytics',
  channels: '/channels',
  users: '/users',
  roles: '/roles',
  audit: '/audit',
  'admin-settings': '/settings',
  account: '/account',
};

function parseTabPath(pathname: string): string {
  const match = Object.entries(TAB_PATHS).find(([, path]) => path === pathname);
  return match?.[0] || 'conversations';
}

const MainApp: React.FC = () => {
  const { isAuthenticated, isLoading, hasPermission, hasRole } = useAuth();

  // Keep staff presence current while the authenticated Intera app is open.
  useEffect(() => {
    if (isLoading || !isAuthenticated) return;

    const heartbeat = () => {
      if (document.visibilityState === 'visible') {
        void api.recordPresenceHeartbeat().catch(() => {});
      }
    };

    heartbeat();
    const interval = window.setInterval(heartbeat, 60_000);
    document.addEventListener('visibilitychange', heartbeat);

    return () => {
      window.clearInterval(interval);
      document.removeEventListener('visibilitychange', heartbeat);
    };
  }, [isAuthenticated, isLoading]);
  const [currentTab, setCurrentTab] = useState<string>(() => {
    return parseAnalyticsPath(window.location.pathname)
      ? 'analytics'
      : parseTabPath(window.location.pathname);
  });
  const [editorRoute, setEditorRoute] = useState<EditorRouteState | null>(() =>
    parseEditorPath(window.location.pathname)
  );
  const [analyticsFlowId, setAnalyticsFlowId] = useState<string | undefined>(() => {
    const parsed = parseAnalyticsPath(window.location.pathname);
    return parsed?.flowId;
  });

  // Keep the login URL clean and discard any protected route requested without a session.
  useLayoutEffect(() => {
    if (isLoading || isAuthenticated) return;
    if (window.location.pathname === '/' && !window.location.search && !window.location.hash) return;

    window.history.replaceState(null, '', '/');
    setCurrentTab('conversations');
    setEditorRoute(null);
    setAnalyticsFlowId(undefined);
  }, [isAuthenticated, isLoading]);

  // Sync route on popstate (browser back/forward)
  useEffect(() => {
    const handlePopState = () => {
      if (!isLoading && !isAuthenticated) {
        if (window.location.pathname !== '/' || window.location.search || window.location.hash) {
          window.history.replaceState(null, '', '/');
        }
        setCurrentTab('conversations');
        setEditorRoute(null);
        setAnalyticsFlowId(undefined);
        return;
      }

      const parsed = parseEditorPath(window.location.pathname);
      setEditorRoute(parsed);
      const parsedAnalytics = parseAnalyticsPath(window.location.pathname);
      if (parsedAnalytics) {
        setAnalyticsFlowId(parsedAnalytics.flowId);
        setCurrentTab('analytics');
      } else {
        setAnalyticsFlowId(undefined);
        setCurrentTab(parseTabPath(window.location.pathname));
      }
    };

    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, [isAuthenticated, isLoading]);

  const canAccessTab = (tab: string) => {
    if (tab === 'account') return true;
    if (tab === 'conversations') return hasPermission('conversations.view_assigned') || hasPermission('conversations.view_all');
    if (tab === 'flows') return hasPermission('flows.view');
    if (tab === 'analytics') return hasPermission('flows.view');
    if (tab === 'channels') return hasPermission('channels.view') || hasPermission('channels.manage');
    if (tab === 'users') return hasPermission('users.view') || hasPermission('users.manage');
    if (tab === 'roles') return hasPermission('roles.view') || hasPermission('roles.manage');
    if (tab === 'audit') return hasPermission('audit_logs.view');
    if (tab === 'admin-settings') return (typeof hasRole === 'function' && hasRole('admin')) || (typeof hasRole === 'function' && hasRole('super_admin'));
    return false;
  };

  const activeTab = canAccessTab(currentTab)
    ? currentTab
    : (hasPermission('conversations.view_assigned') || hasPermission('conversations.view_all'))
      ? 'conversations'
      : hasPermission('flows.view')
        ? 'flows'
        : hasPermission('users.view')
          ? 'users'
          : 'account';

  if (isLoading) {
    return (
      <div style={{ display: 'flex', height: '100vh', alignItems: 'center', justifyContent: 'center', background: '#263c38', color: 'white' }}>
        <p>Iniciando Intera...</p>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <LoginView />;
  }

  // Handle flow editor route
  if (editorRoute) {
    // RBAC check: user must have flows.view or flows.edit
    const canViewFlows = hasPermission('flows.view') || hasPermission('flows.edit');
    if (!canViewFlows) {
      return (
        <div style={{ padding: '2rem', textAlign: 'center' }}>
          <h2 style={{ color: '#dc2626' }}>Acceso no autorizado</h2>
          <p style={{ color: '#61726d', marginTop: '0.5rem' }}>
            No tienes los permisos necesarios para acceder al constructor de flujos.
          </p>
          <button
            className="btn btn-secondary"
            style={{ marginTop: '1rem' }}
            onClick={() => {
              window.history.pushState(null, '', '/conversations');
              setEditorRoute(null);
              setCurrentTab('conversations');
            }}
          >
            Volver al inicio
          </button>
        </div>
      );
    }

    return (
      <Suspense fallback={<EditorSuspenseFallback />}>
        <FlowEditor
          flowId={editorRoute.flowId}
          initialVersionId={editorRoute.versionId}
          onBack={() => {
            window.history.pushState(null, '', '/flows');
            setEditorRoute(null);
            setCurrentTab('flows');
          }}
        />
      </Suspense>
    );
  }

  const handleOpenEditor = (flowId: string, versionId?: string) => {
    const url = `/flows/${flowId}/editor${versionId ? `/${versionId}` : ''}`;
    window.history.pushState(null, '', url);
    setEditorRoute({ flowId, versionId });
  };

  const handleOpenAnalytics = (flowId?: string) => {
    setAnalyticsFlowId(flowId);
    setCurrentTab('analytics');
    const url = flowId ? `/flows/${flowId}/analytics` : '/analytics';
    window.history.pushState(null, '', url);
  };

  const handleSelectTab = (tab: string) => {
    setCurrentTab(tab);
    setEditorRoute(null);
    setAnalyticsFlowId(undefined);
    const path = TAB_PATHS[tab] || '/conversations';
    if (window.location.pathname !== path) {
      window.history.pushState(null, '', path);
    }
  };

  const renderContent = () => {
    switch (activeTab) {
      case 'conversations':
        return <ConversationsView />;
      case 'flows':
        return <FlowsView onOpenEditor={handleOpenEditor} onViewAnalytics={handleOpenAnalytics} />;
      case 'analytics':
        return (
          <DashboardsView
            initialFlowId={analyticsFlowId}
            onOpenConversation={(convId) => {
              setCurrentTab('conversations');
              window.history.pushState(null, '', `/conversations?id=${convId}`);
            }}
          />
        );
      case 'channels':
        return <ChannelsView />;
      case 'users':
        return <UsersView />;
      case 'roles':
        return <RolesView />;
      case 'audit':
        return <AuditLogsView />;
      case 'account':
        return <AccountView />;
      case 'admin-settings':
        return <AdminSettingsView />;
      default:
        return <ConversationsView />;
    }
  };

  return (
    <Layout currentTab={activeTab} onSelectTab={handleSelectTab}>
      <Suspense fallback={<ViewSuspenseFallback />}>
        {renderContent()}
      </Suspense>
    </Layout>
  );
};

export const App: React.FC = () => {
  return (
    <AuthProvider>
      <ToastProvider>
        <MainApp />
      </ToastProvider>
    </AuthProvider>
  );
};

export default App;
