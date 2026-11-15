import React, { useState, useEffect } from 'react';
import { useAuth } from '../context/useAuth';
import { InteraLogo } from './InteraLogo';
import { useIsMobile } from '../hooks/useMediaQuery';
import {
  MessageSquare,
  GitBranch,
  BarChart3,
  Radio,
  Users,
  Shield,
  FileText,
  LogOut,
  User,
  Settings,
  PanelLeftClose,
  PanelLeftOpen,
  Menu,
  X,
} from 'lucide-react';

interface LayoutProps {
  currentTab: string;
  onSelectTab: (tab: string) => void;
  children: React.ReactNode;
}

export const Layout: React.FC<LayoutProps> = ({ currentTab, onSelectTab, children }) => {
  const { user, roles, logout, hasPermission, hasRole } = useAuth();
  const isMobile = useIsMobile();

  const [collapsed, setCollapsed] = useState<boolean>(() => {
    try {
      return localStorage.getItem('conexa_sidebar_collapsed') === 'true';
    } catch {
      return false;
    }
  });

  const [mobileOpen, setMobileOpen] = useState(false);

  const toggleCollapsed = () => {
    setCollapsed((prev) => {
      const next = !prev;
      try {
        localStorage.setItem('conexa_sidebar_collapsed', String(next));
      } catch {
        // ignore localStorage errors
      }
      return next;
    });
  };

  const handleSelectTab = (tabId: string) => {
    onSelectTab(tabId);
    if (isMobile) {
      setMobileOpen(false);
    }
  };

  const handleLogout = () => {
    if (isMobile) {
      setMobileOpen(false);
    }
    logout();
  };

  // Close mobile drawer on Escape key
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && mobileOpen) {
        setMobileOpen(false);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [mobileOpen]);

  // Give each table cell a mobile label so tables can reflow as readable cards.
  useEffect(() => {
    const content = document.querySelector('.content-body');
    if (!content) return;

    const addMobileLabels = () => {
      content.querySelectorAll<HTMLTableElement>('.table-wrapper > table').forEach((table) => {
        const headerCells = Array.from(table.tHead?.rows[0]?.cells || []);
        if (!headerCells.length) return;

        Array.from(table.tBodies).forEach((tbody) => {
          Array.from(tbody.rows).forEach((row) => {
            Array.from(row.cells).forEach((cell, index) => {
              if (cell.tagName !== 'TD' || cell.colSpan > 1) return;
              const label = headerCells[index]?.textContent?.trim();
              if (label) cell.dataset.mobileLabel = label;
            });
          });
        });
      });
    };

    addMobileLabels();
    const observer = new MutationObserver(addMobileLabels);
    observer.observe(content, { childList: true, subtree: true });
    return () => observer.disconnect();
  }, [currentTab]);

  const isDrawerOpen = isMobile && mobileOpen;

  const navItems = [
    {
      id: 'conversations',
      label: 'Conversaciones',
      icon: MessageSquare,
      visible: hasPermission('conversations.view_assigned') || hasPermission('conversations.view_all'),
    },
    {
      id: 'flows',
      label: 'Flujos Conversacionales',
      icon: GitBranch,
      visible: hasPermission('flows.view'),
    },
    {
      id: 'analytics',
      label: 'Dashboards',
      icon: BarChart3,
      visible: hasPermission('flows.view') || hasPermission('conversations.view_all'),
    },
    {
      id: 'channels',
      label: 'Canales',
      icon: Radio,
      visible: hasPermission('channels.view') || hasPermission('channels.manage'),
    },
    {
      id: 'users',
      label: 'Usuarios',
      icon: Users,
      visible: hasPermission('users.view') || hasPermission('users.manage'),
    },
    {
      id: 'roles',
      label: 'Roles y Permisos',
      icon: Shield,
      visible: hasPermission('roles.view') || hasPermission('roles.manage'),
    },
    {
      id: 'audit',
      label: 'Auditoría',
      icon: FileText,
      visible: hasPermission('audit_logs.view'),
    },
    {
      id: 'admin-settings',
      label: 'Ajustes del sistema',
      icon: Settings,
      visible: (typeof hasRole === 'function' && hasRole('admin')) || (typeof hasRole === 'function' && hasRole('super_admin')),
    },
    {
      id: 'account',
      label: 'Mi Cuenta',
      icon: User,
      visible: true,
    },
  ];

  const visibleNavItems = navItems.filter((item) => item.visible);
  const isSidebarCollapsed = !isMobile && collapsed;

  return (
    <div className="app-container">
      {/* Mobile Drawer Backdrop */}
      {isDrawerOpen && (
        <div
          className="sidebar-backdrop"
          data-testid="sidebar-backdrop"
          onClick={() => setMobileOpen(false)}
          aria-hidden="true"
        />
      )}

      {/* Sidebar */}
      <aside
        className={`sidebar ${isSidebarCollapsed ? 'collapsed' : ''} ${isDrawerOpen ? 'mobile-open' : ''}`}
        data-testid="sidebar"
        aria-label="Menú de Navegación"
      >
        <div className="sidebar-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', minWidth: 0, overflow: 'hidden' }}>
            <InteraLogo variant="light" showWordmark={!isSidebarCollapsed} />
          </div>

          {/* Desktop Toggle Button */}
          {!isMobile && (
            <button
              type="button"
              className="sidebar-toggle-btn"
              data-testid="sidebar-toggle-btn"
              onClick={toggleCollapsed}
              title={isSidebarCollapsed ? 'Expandir menú lateral' : 'Colapsar menú lateral'}
              aria-label={isSidebarCollapsed ? 'Expandir menú lateral' : 'Colapsar menú lateral'}
            >
              {isSidebarCollapsed ? <PanelLeftOpen size={18} /> : <PanelLeftClose size={18} />}
            </button>
          )}

          {/* Mobile Close Button */}
          {isMobile && (
            <button
              type="button"
              className="sidebar-toggle-btn"
              data-testid="sidebar-close-btn"
              onClick={() => setMobileOpen(false)}
              title="Cerrar menú lateral"
              aria-label="Cerrar menú lateral"
            >
              <X size={20} />
            </button>
          )}
        </div>

        <nav className="sidebar-nav">
          {visibleNavItems.map((item) => {
            const Icon = item.icon;
            const isActive = currentTab === item.id;
            return (
              <button
                key={item.id}
                className={`nav-item ${isActive ? 'active' : ''}`}
                onClick={() => handleSelectTab(item.id)}
                title={isSidebarCollapsed ? item.label : undefined}
                aria-label={item.label}
              >
                <Icon size={18} style={{ flexShrink: 0 }} />
                <span>{item.label}</span>
              </button>
            );
          })}
        </nav>

        <div className="sidebar-footer">
          <div
            className="user-badge"
            style={{ marginBottom: '0.75rem', cursor: 'pointer' }}
            onClick={() => handleSelectTab('account')}
            title={isSidebarCollapsed ? `Mi Cuenta (${user?.name})` : 'Ir a Mi Cuenta'}
          >
            <div className="user-avatar">
              {user?.name.charAt(0).toUpperCase() || 'U'}
            </div>
            <div style={{ overflow: 'hidden' }}>
              <div style={{ fontSize: '0.85rem', fontWeight: 600, color: '#263c38', whiteSpace: 'nowrap', textOverflow: 'ellipsis', overflow: 'hidden' }}>
                {user?.name}
              </div>
              <div style={{ fontSize: '0.75rem', color: '#61726d' }}>
                {roles[0] || 'Usuario'}
              </div>
            </div>
          </div>

          <button
            onClick={handleLogout}
            title="Cerrar sesión"
            className="btn btn-secondary btn-sm btn-logout"
            style={{
              width: '100%',
              justifyContent: 'center',
              background: 'transparent',
              color: '#61726d',
              borderColor: '#d9e3de',
              padding: isSidebarCollapsed ? '0.5rem' : '0.45rem 0.75rem',
            }}
          >
            <LogOut size={14} style={{ flexShrink: 0 }} />
            <span>Cerrar Sesión</span>
          </button>
        </div>
      </aside>

      {/* Main Content Area */}
      <main className="main-content">
        <header className="topbar">
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.65rem' }}>
            {/* Mobile Menu Hamburger */}
            {isMobile && (
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                data-testid="mobile-menu-btn"
                onClick={() => setMobileOpen(true)}
                title="Abrir menú de navegación"
                aria-label="Abrir menú de navegación"
                style={{
                  padding: '0.35rem 0.5rem',
                  display: 'inline-flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  background: 'white',
                  borderColor: 'var(--border)',
                }}
              >
                <Menu size={18} />
              </button>
            )}

            {/* Desktop Topbar Quick Toggle (when collapsed) */}
            {!isMobile && isSidebarCollapsed && (
              <button
                type="button"
                className="sidebar-toggle-btn"
                data-testid="topbar-sidebar-expand-btn"
                onClick={toggleCollapsed}
                title="Expandir menú lateral"
                aria-label="Expandir menú lateral"
                style={{ marginRight: '0.25rem' }}
              >
                <PanelLeftOpen size={18} />
              </button>
            )}

            <div className="topbar-heading">
              <span className="topbar-eyebrow">Espacio de trabajo</span>
              <span className="topbar-title">
                {navItems.find((i) => i.id === currentTab)?.label || 'Panel de Control'}
              </span>
            </div>
          </div>
        </header>

        <div className={`content-body content-body--${currentTab}`}>
          {children}
        </div>
      </main>
    </div>
  );
};
