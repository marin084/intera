import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import type { User } from '../types';
import type { WorkspaceMembership } from '../types';
import { AuthContext } from './authContextDef';

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  const [roles, setRoles] = useState<string[]>([]);
  const [permissions, setPermissions] = useState<string[]>([]);
  const [workspace, setWorkspace] = useState<WorkspaceMembership | null>(null);
  const [workspaces, setWorkspaces] = useState<WorkspaceMembership[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(true);

  useEffect(() => {
    let isMounted = true;

    const checkAuth = async () => {
      try {
        const res = await api.me();
        if (isMounted) {
          setUser(res.user);
          setRoles(res.roles || []);
          setPermissions(res.permissions || []);
          setWorkspace(res.workspace || null);
          setWorkspaces(res.workspaces || []);
        }
      } catch {
        if (isMounted) {
          setUser(null);
          setRoles([]);
          setPermissions([]);
          setWorkspace(null);
          setWorkspaces([]);
        }
      } finally {
        if (isMounted) {
          setIsLoading(false);
        }
      }
    };

    checkAuth();

    return () => {
      isMounted = false;
    };
  }, []);

  const login = async (credentials: { email: string; password: string }) => {
    const res = await api.login(credentials);
    setUser(res.user);
    setRoles(res.roles || []);
    setPermissions(res.permissions || []);
    setWorkspace(res.workspace || null);
    setWorkspaces(res.workspaces || []);
  };

  const switchWorkspace = async (workspaceId: string) => {
    await api.switchWorkspace(workspaceId);
    const res = await api.me();
    setUser(res.user);
    setRoles(res.roles || []);
    setPermissions(res.permissions || []);
    setWorkspace(res.workspace || null);
    setWorkspaces(res.workspaces || []);
  };

  const logout = async () => {
    try {
      await api.logout();
    } finally {
      setUser(null);
      setRoles([]);
      setPermissions([]);
      setWorkspace(null);
      setWorkspaces([]);
    }
  };

  const hasPermission = (permission: string): boolean => {
    if (roles.includes('admin')) {
      return true;
    }
    return permissions.includes(permission);
  };

  const hasRole = (role: string): boolean => {
    return roles.includes(role);
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        roles,
        permissions,
        workspace,
        workspaces,
        isAuthenticated: !!user,
        isLoading,
        login,
        logout,
        hasPermission,
        hasRole,
        switchWorkspace,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};
