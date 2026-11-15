import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { Layout } from '../Layout';

vi.mock('../../context/useAuth', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Admin Conexa', email: 'admin@conexa.ai' },
    roles: ['super_admin'],
    logout: vi.fn(),
    hasPermission: () => true,
  }),
}));

// Mock localStorage for test environment
let store: Record<string, string> = {};
const localStorageMock = {
  getItem: (key: string) => store[key] || null,
  setItem: (key: string, value: string) => {
    store[key] = value.toString();
  },
  removeItem: (key: string) => {
    delete store[key];
  },
  clear: () => {
    store = {};
  },
};
Object.defineProperty(window, 'localStorage', {
  value: localStorageMock,
  writable: true,
});

describe('Layout Component', () => {
  beforeEach(() => {
    store = {};
    vi.restoreAllMocks();
  });

  const setupMatchMedia = (matches: boolean) => {
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: vi.fn().mockImplementation((query: string) => ({
        matches,
        media: query,
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
      })),
    });
  };

  describe('Desktop Mode (> 768px)', () => {
    beforeEach(() => {
      setupMatchMedia(false); // Not mobile
    });

    it('renders expanded sidebar by default and toggles collapsed mode', () => {
      render(
        <Layout currentTab="conversations" onSelectTab={vi.fn()}>
          <div>Content</div>
        </Layout>
      );

      const sidebar = screen.getByTestId('sidebar');
      expect(sidebar).not.toHaveClass('collapsed');
      expect(within(sidebar).getByText('Conversaciones')).toBeInTheDocument();

      const toggleBtn = screen.getByTestId('sidebar-toggle-btn');
      fireEvent.click(toggleBtn);

      expect(sidebar).toHaveClass('collapsed');
      expect(localStorage.getItem('conexa_sidebar_collapsed')).toBe('true');

      // Toggling again should restore expanded state
      fireEvent.click(toggleBtn);
      expect(sidebar).not.toHaveClass('collapsed');
      expect(localStorage.getItem('conexa_sidebar_collapsed')).toBe('false');
    });

    it('honors initial collapsed state from localStorage', () => {
      localStorage.setItem('conexa_sidebar_collapsed', 'true');

      render(
        <Layout currentTab="conversations" onSelectTab={vi.fn()}>
          <div>Content</div>
        </Layout>
      );

      const sidebar = screen.getByTestId('sidebar');
      expect(sidebar).toHaveClass('collapsed');

      // Topbar quick expand button should also be visible in collapsed desktop mode
      const topbarExpandBtn = screen.getByTestId('topbar-sidebar-expand-btn');
      expect(topbarExpandBtn).toBeInTheDocument();

      fireEvent.click(topbarExpandBtn);
      expect(sidebar).not.toHaveClass('collapsed');
      expect(localStorage.getItem('conexa_sidebar_collapsed')).toBe('false');
    });
  });

  describe('Mobile Mode (<= 768px)', () => {
    beforeEach(() => {
      setupMatchMedia(true); // Is mobile
    });

    it('hides mobile drawer by default and opens via mobile menu button', () => {
      const onSelectTab = vi.fn();
      render(
        <Layout currentTab="conversations" onSelectTab={onSelectTab}>
          <div>Content</div>
        </Layout>
      );

      const sidebar = screen.getByTestId('sidebar');
      expect(sidebar).not.toHaveClass('mobile-open');
      expect(screen.queryByTestId('sidebar-backdrop')).not.toBeInTheDocument();

      // Open drawer using mobile topbar hamburger button
      const menuBtn = screen.getByTestId('mobile-menu-btn');
      fireEvent.click(menuBtn);

      expect(sidebar).toHaveClass('mobile-open');
      const backdrop = screen.getByTestId('sidebar-backdrop');
      expect(backdrop).toBeInTheDocument();

      // Clicking backdrop closes drawer
      fireEvent.click(backdrop);
      expect(sidebar).not.toHaveClass('mobile-open');
      expect(screen.queryByTestId('sidebar-backdrop')).not.toBeInTheDocument();
    });

    it('closes mobile drawer when clicking close button', () => {
      render(
        <Layout currentTab="conversations" onSelectTab={vi.fn()}>
          <div>Content</div>
        </Layout>
      );

      const menuBtn = screen.getByTestId('mobile-menu-btn');
      fireEvent.click(menuBtn);

      const sidebar = screen.getByTestId('sidebar');
      expect(sidebar).toHaveClass('mobile-open');

      const closeBtn = screen.getByTestId('sidebar-close-btn');
      fireEvent.click(closeBtn);

      expect(sidebar).not.toHaveClass('mobile-open');
    });

    it('auto-closes drawer when selecting a navigation item on mobile', () => {
      const onSelectTab = vi.fn();
      render(
        <Layout currentTab="conversations" onSelectTab={onSelectTab}>
          <div>Content</div>
        </Layout>
      );

      // Open drawer
      fireEvent.click(screen.getByTestId('mobile-menu-btn'));
      const sidebar = screen.getByTestId('sidebar');
      expect(sidebar).toHaveClass('mobile-open');

      // Click nav item
      const channelsBtn = screen.getByRole('button', { name: /Canales/i });
      fireEvent.click(channelsBtn);

      expect(onSelectTab).toHaveBeenCalledWith('channels');
      expect(sidebar).not.toHaveClass('mobile-open');
    });

    it('closes drawer on Escape key press', () => {
      render(
        <Layout currentTab="conversations" onSelectTab={vi.fn()}>
          <div>Content</div>
        </Layout>
      );

      fireEvent.click(screen.getByTestId('mobile-menu-btn'));
      const sidebar = screen.getByTestId('sidebar');
      expect(sidebar).toHaveClass('mobile-open');

      fireEvent.keyDown(window, { key: 'Escape' });
      expect(sidebar).not.toHaveClass('mobile-open');
    });
  });
});
