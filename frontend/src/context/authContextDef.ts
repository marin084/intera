import { createContext } from 'react';
import type { User } from '../types';
import type { WorkspaceMembership } from '../types';

export interface AuthContextType {
  user: User | null;
  roles: string[];
  permissions: string[];
  workspace?: WorkspaceMembership | null;
  workspaces?: WorkspaceMembership[];
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (credentials: { email: string; password: string }) => Promise<void>;
  logout: () => Promise<void>;
  hasPermission: (permission: string) => boolean;
  hasRole: (role: string) => boolean;
  switchWorkspace?: (workspaceId: string) => Promise<void>;
}

export const AuthContext = createContext<AuthContextType | undefined>(undefined);
