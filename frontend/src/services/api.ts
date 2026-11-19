import type { CannedResponse, FlowAnalyticsData, UsersAndSessionsData, FunnelContact, RealtimeData, AgentMetricsData, SavedFunnel, SequentialStepDefinition, Tag } from '../types';

const API_URL = import.meta.env.VITE_API_URL || '/api/v1';
const BASE_URL = API_URL.startsWith('http') ? API_URL.replace(/\/api\/v1\/?$/, '') : '';

function getXsrfToken(): string | null {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : null;
}

class ApiService {
  public async initCsrf(): Promise<void> {
    await fetch(`${BASE_URL}/sanctum/csrf-cookie`, {
      method: 'GET',
      credentials: 'include',
      headers: {
        'Accept': 'application/json',
      },
    }).catch(() => {});
  }

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
    const headers: Record<string, string> = {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(options.headers as Record<string, string> || {}),
    };

    if (!isFormData && !headers['Content-Type']) {
      headers['Content-Type'] = 'application/json';
    }

    // Include CSRF token on state-changing requests
    const xsrf = getXsrfToken();
    if (xsrf && options.method && options.method !== 'GET') {
      headers['X-XSRF-TOKEN'] = xsrf;
    }

    const response = await fetch(`${API_URL}${endpoint}`, {
      ...options,
      credentials: 'include', // Sends HttpOnly session cookies
      headers,
    });

    if (response.status === 401 && !endpoint.includes('/auth/login')) {
      window.dispatchEvent(new Event('auth:unauthorized'));
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
      const message = data.message || `Error en la solicitud (${response.status})`;
      const error: any = new Error(message);
      error.status = response.status;
      error.data = data;
      throw error;
    }

    return data as T;
  }

  // Auth - Pure Sanctum SPA Cookie Session & CSRF
  async login(credentials: { email: string; password: string }) {
    await this.initCsrf();
    const data = await this.request<{
      user: any;
      roles: string[];
      permissions: string[];
      workspace?: import('../types').WorkspaceMembership | null;
      workspaces?: import('../types').WorkspaceMembership[];
      message?: string;
    }>('/auth/login', {
      method: 'POST',
      body: JSON.stringify(credentials),
    });

    return data;
  }

  async logout() {
    return this.request<{ message: string }>('/auth/logout', {
      method: 'POST',
    });
  }

  async me() {
    return this.request<{
      user: any;
      roles: string[];
      permissions: string[];
      workspace?: import('../types').WorkspaceMembership | null;
      workspaces?: import('../types').WorkspaceMembership[];
    }>('/auth/me');
  }

  async getWorkspaces() {
    return this.request<{
      data: import('../types').WorkspaceMembership[];
      active_workspace_id: string | null;
    }>('/workspaces');
  }

  async switchWorkspace(workspace_id: string) {
    return this.request<{
      message: string;
      data: import('../types').WorkspaceMembership;
      active_workspace_id: string;
    }>('/workspaces/switch', {
      method: 'POST',
      body: JSON.stringify({ workspace_id }),
    });
  }

  async recordPresenceHeartbeat() {
    return this.request<{ status: string; online_agents: number; has_agents: boolean; last_seen_at: string | null }>(
      '/presence/heartbeat',
      { method: 'POST' },
    );
  }

  async changePassword(payload: { current_password: string; password: string; password_confirmation: string }) {
    return this.request<{ message: string }>('/auth/password', {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  // Administrator-only integrations
  async getDiscordWebhookSettings() {
    return this.request<{ data: { discord_webhook_configured: boolean } }>('/admin/settings/discord-webhook');
  }

  async saveDiscordWebhook(webhook_url: string) {
    return this.request<{ message: string; data: { discord_webhook_configured: boolean } }>('/admin/settings/discord-webhook', {
      method: 'PUT',
      body: JSON.stringify({ webhook_url }),
    });
  }

  async clearDiscordWebhook() {
    return this.request<{ message: string; data: { discord_webhook_configured: boolean } }>('/admin/settings/discord-webhook', {
      method: 'DELETE',
    });
  }

  async sendDiscordWebhookTest() {
    return this.request<{ message: string }>('/admin/settings/discord-webhook/test', {
      method: 'POST',
    });
  }

  // Users
  async getUsers() {
    return this.request<{ data: any[] }>('/users');
  }

  async getTeams() {
    return this.request<{ data: import('../types').Team[] }>('/teams');
  }

  async createTeam(payload: { name: string; description?: string; member_ids: number[]; channel_ids: string[]; queue_wait_target_seconds?: number | null; first_response_target_seconds?: number | null }) {
    return this.request<{ message: string; data: import('../types').Team }>('/teams', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async updateTeam(id: string, payload: { name?: string; description?: string; member_ids?: number[]; channel_ids?: string[]; queue_wait_target_seconds?: number | null; first_response_target_seconds?: number | null }) {
    return this.request<{ message: string; data: import('../types').Team }>(`/teams/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  async createUser(payload: any) {
    return this.request<{ message: string; data: any }>('/users', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async updateUser(id: number, payload: any) {
    return this.request<{ message: string; data: any }>(`/users/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  async updateUserStatus(id: number, is_active: boolean) {
    return this.request<{ message: string; data: any }>(`/users/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ is_active }),
    });
  }

  async updateUserChannels(id: number, channel_ids: string[]) {
    return this.request<{ message: string; data: { id: string; name: string; type: string; is_active: boolean }[] }>(`/users/${id}/channels`, {
      method: 'PUT',
      body: JSON.stringify({ channel_ids }),
    });
  }

  // Roles & Permissions
  async getRoles() {
    return this.request<{ data: any[] }>('/roles');
  }

  async updateRole(id: number, payload: { max_concurrent_conversations?: number | null; display_name?: string; description?: string }) {
    return this.request<{ message: string; data: any }>(`/roles/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
    });
  }

  async getPermissions() {
    return this.request<{ data: any[] }>('/permissions');
  }

  // Channels
  async getChannels() {
    return this.request<{ data: any[] }>('/channels');
  }

  async getChannelAccessOptions() {
    return this.request<{ data: { id: string; name: string; type: string; is_active: boolean }[] }>('/channels/access-options');
  }

  async createChannel(payload: {
    name: string;
    type: string;
    is_active?: boolean;
    entry_flow_id?: string | null;
    configuration?: any;
    webchat_settings?: any;
  }) {
    return this.request<{ message: string; data: any }>('/channels', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async updateChannel(
    id: string,
    payload: {
      name?: string;
      is_active?: boolean;
      entry_flow_id?: string | null;
      configuration?: any;
      webchat_settings?: any;
    }
  ) {
    return this.request<{ message: string; data: any }>(`/channels/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  async updateChannelStatus(id: string, is_active: boolean) {
    return this.request<{ message: string; data: any }>(`/channels/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ is_active }),
    });
  }

  async regenerateWebchatKey(id: string) {
    return this.request<{ message: string; data: any }>(`/channels/${id}/webchat/regenerate-key`, {
      method: 'POST',
    });
  }

  async getWhatsAppTemplates(channelId: string) {
    return this.request<{ data: any[] }>(`/channels/${channelId}/whatsapp-templates`);
  }

  async setupTelegramWebhook(channelId: string, url?: string) {
    return this.request<{ message: string; data: any }>(`/channels/${channelId}/telegram/setup-webhook`, {
      method: 'POST',
      body: JSON.stringify(url ? { url } : {}),
    });
  }

  async getTelegramWebhookInfo(channelId: string) {
    return this.request<{ data: any }>(`/channels/${channelId}/telegram/webhook-info`);
  }

  async deleteChannel(id: string, force?: boolean) {
    return this.request<{ message: string; requires_force?: boolean; conversations_count?: number }>(
      `/channels/${id}${force ? '?force=true' : ''}`,
      {
        method: 'DELETE',
      }
    );
  }

  // Flows
  async getFlows() {
    return this.request<{ data: any[] }>('/flows');
  }

  async createFlow(payload: { name: string; description?: string; channel_applicability?: string[] }) {
    return this.request<{ message: string; data: any }>('/flows', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async updateFlow(flowId: string, payload: { name?: string; description?: string; channel_applicability?: string[] }) {
    return this.request<{ message: string; data: any }>(`/flows/${flowId}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  async deleteFlow(id: string, force?: boolean) {
    return this.request<{
      message: string;
      requires_force?: boolean;
      conversations_count?: number;
      blocking_channels?: string[];
    }>(
      `/flows/${id}${force ? '?force=true' : ''}`,
      {
        method: 'DELETE',
      }
    );
  }

  async getFlow(id: string) {
    return this.request<{ data: any }>(`/flows/${id}`);
  }

  async getFlowAnalytics(
    flowId: string,
    params?: { from?: string; to?: string; channel_id?: string; version_id?: string }
  ) {
    const query = new URLSearchParams();
    if (params?.from) query.append('from', params.from);
    if (params?.to) query.append('to', params.to);
    if (params?.channel_id) query.append('channel_id', params.channel_id);
    if (params?.version_id) query.append('version_id', params.version_id);
    const qs = query.toString();
    return this.request<{ data: FlowAnalyticsData }>(`/flows/${flowId}/analytics${qs ? `?${qs}` : ''}`);
  }

  // Dashboards & Analytics
  async getFunnels(params?: {
    flow_id?: string;
    from?: string;
    to?: string;
    channel_id?: string;
    steps?: any[] | string;
  }) {
    const query = new URLSearchParams();
    if (params?.flow_id) query.append('flow_id', params.flow_id);
    if (params?.from) query.append('from', params.from);
    if (params?.to) query.append('to', params.to);
    if (params?.channel_id) query.append('channel_id', params.channel_id);
    if (params?.steps) {
      query.append('steps', typeof params.steps === 'string' ? params.steps : JSON.stringify(params.steps));
    }
    const qs = query.toString();
    return this.request<{ data: FlowAnalyticsData }>(`/analytics/funnels${qs ? `?${qs}` : ''}`);
  }

  async getFunnelContacts(params: {
    flow_id: string;
    node_id?: string;
    node_key?: string;
    option_id?: string;
    option_title?: string;
    from?: string;
    to?: string;
    channel_id?: string;
    page?: number;
    per_page?: number;
  }) {
    const query = new URLSearchParams();
    query.append('flow_id', params.flow_id);
    if (params.node_id) query.append('node_id', params.node_id);
    if (params.node_key) query.append('node_key', params.node_key);
    if (params.option_id) query.append('option_id', params.option_id);
    if (params.option_title) query.append('option_title', params.option_title);
    if (params.from) query.append('from', params.from);
    if (params.to) query.append('to', params.to);
    if (params.channel_id) query.append('channel_id', params.channel_id);
    if (params.page) query.append('page', String(params.page));
    if (params.per_page) query.append('per_page', String(params.per_page));
    const qs = query.toString();
    return this.request<{
      data: FunnelContact[];
      total: number;
      per_page: number;
      current_page: number;
      last_page: number;
    }>(`/analytics/funnels/contacts${qs ? `?${qs}` : ''}`);
  }

  getFunnelContactsExportUrl(params: {
    flow_id: string;
    node_id?: string;
    node_key?: string;
    option_id?: string;
    option_title?: string;
    from?: string;
    to?: string;
    channel_id?: string;
  }): string {
    const query = new URLSearchParams();
    query.append('flow_id', params.flow_id);
    if (params.node_id) query.append('node_id', params.node_id);
    if (params.node_key) query.append('node_key', params.node_key);
    if (params.option_id) query.append('option_id', params.option_id);
    if (params.option_title) query.append('option_title', params.option_title);
    if (params.from) query.append('from', params.from);
    if (params.to) query.append('to', params.to);
    if (params.channel_id) query.append('channel_id', params.channel_id);
    query.append('export', 'csv');
    return `/api/v1/analytics/funnels/contacts?${query.toString()}`;
  }

  async getStepContacts(params: {
    flow_id: string;
    step_number: number;
    steps?: any[] | string;
    from?: string;
    to?: string;
    channel_id?: string;
    page?: number;
    per_page?: number;
  }) {
    const query = new URLSearchParams();
    query.append('flow_id', params.flow_id);
    query.append('step_number', String(params.step_number));
    if (params.steps) {
      query.append('steps', typeof params.steps === 'string' ? params.steps : JSON.stringify(params.steps));
    }
    if (params.from) query.append('from', params.from);
    if (params.to) query.append('to', params.to);
    if (params.channel_id) query.append('channel_id', params.channel_id);
    if (params.page) query.append('page', String(params.page));
    if (params.per_page) query.append('per_page', String(params.per_page));
    const qs = query.toString();
    return this.request<{
      data: FunnelContact[];
      total: number;
      per_page: number;
      current_page: number;
      last_page: number;
    }>(`/analytics/funnels/step-contacts${qs ? `?${qs}` : ''}`);
  }

  getStepContactsExportUrl(params: {
    flow_id: string;
    step_number: number;
    steps?: any[] | string;
    from?: string;
    to?: string;
    channel_id?: string;
  }): string {
    const query = new URLSearchParams();
    query.append('flow_id', params.flow_id);
    query.append('step_number', String(params.step_number));
    if (params.steps) {
      query.append('steps', typeof params.steps === 'string' ? params.steps : JSON.stringify(params.steps));
    }
    if (params.from) query.append('from', params.from);
    if (params.to) query.append('to', params.to);
    if (params.channel_id) query.append('channel_id', params.channel_id);
    query.append('export', 'csv');
    return `/api/v1/analytics/funnels/step-contacts?${query.toString()}`;
  }

  async getSavedFunnels(flowId?: string) {
    const query = new URLSearchParams();
    if (flowId) query.append('flow_id', flowId);
    const qs = query.toString();
    return this.request<{ data: SavedFunnel[] }>(`/analytics/funnels/saved${qs ? `?${qs}` : ''}`);
  }

  async createSavedFunnel(data: { name: string; flow_id: string; steps: SequentialStepDefinition[] }) {
    return this.request<{ data: SavedFunnel; message: string }>('/analytics/funnels/saved', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  async updateSavedFunnel(id: string, data: { name?: string; flow_id?: string; steps?: SequentialStepDefinition[] }) {
    return this.request<{ data: SavedFunnel; message: string }>(`/analytics/funnels/saved/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  }

  async deleteSavedFunnel(id: string) {
    return this.request<{ message: string }>(`/analytics/funnels/saved/${id}`, {
      method: 'DELETE',
    });
  }

  async getUsersAndSessions(params?: { from?: string; to?: string; channel_id?: string; tag_id?: string }) {
    const query = new URLSearchParams();
    if (params?.from) query.append('from', params.from);
    if (params?.to) query.append('to', params.to);
    if (params?.channel_id) query.append('channel_id', params.channel_id);
    if (params?.tag_id && params.tag_id !== 'all') query.append('tag_id', params.tag_id);
    const qs = query.toString();
    return this.request<{ data: UsersAndSessionsData }>(`/analytics/users-and-sessions${qs ? `?${qs}` : ''}`);
  }

  getUsersAndSessionsExportUrl(params?: { from?: string; to?: string; channel_id?: string; tag_id?: string }): string {
    const query = new URLSearchParams();
    if (params?.from) query.append('from', params.from);
    if (params?.to) query.append('to', params.to);
    if (params?.channel_id) query.append('channel_id', params.channel_id);
    if (params?.tag_id && params.tag_id !== 'all') query.append('tag_id', params.tag_id);
    const qs = query.toString();
    return `${API_URL}/analytics/users-and-sessions/export${qs ? `?${qs}` : ''}`;
  }

  // Realtime Analytics
  async getRealtimeAnalytics(params?: { channel_id?: string }) {
    const query = new URLSearchParams();
    if (params?.channel_id) query.append('channel_id', params.channel_id);
    const qs = query.toString();
    return this.request<{ data: RealtimeData }>(`/analytics/realtime${qs ? `?${qs}` : ''}`);
  }

  // Agent Performance & Productivity Metrics
  async getAgentMetrics(params?: {
    from?: string;
    to?: string;
    channel_id?: string;
    agent_id?: number | string;
    online_only?: boolean;
  }) {
    const query = new URLSearchParams();
    if (params?.from) query.append('from', params.from);
    if (params?.to) query.append('to', params.to);
    if (params?.channel_id) query.append('channel_id', params.channel_id);
    if (params?.agent_id) query.append('agent_id', String(params.agent_id));
    if (params?.online_only !== undefined) query.append('online_only', params.online_only ? '1' : '0');
    const qs = query.toString();
    return this.request<{ data: AgentMetricsData }>(`/analytics/agents${qs ? `?${qs}` : ''}`);
  }

  getAgentsExportSummaryUrl(params?: {
    from?: string;
    to?: string;
    channel_id?: string;
    agent_id?: number | string;
    online_only?: boolean;
  }): string {
    const query = new URLSearchParams();
    if (params?.from) query.append('from', params.from);
    if (params?.to) query.append('to', params.to);
    if (params?.channel_id) query.append('channel_id', params.channel_id);
    if (params?.agent_id) query.append('agent_id', String(params.agent_id));
    if (params?.online_only !== undefined) query.append('online_only', params.online_only ? '1' : '0');
    const qs = query.toString();
    return `${API_URL}/analytics/agents/export-summary${qs ? `?${qs}` : ''}`;
  }

  getAgentsExportConversationsUrl(params?: {
    from?: string;
    to?: string;
    channel_id?: string;
    agent_id?: number | string;
  }): string {
    const query = new URLSearchParams();
    if (params?.from) query.append('from', params.from);
    if (params?.to) query.append('to', params.to);
    if (params?.channel_id) query.append('channel_id', params.channel_id);
    if (params?.agent_id) query.append('agent_id', String(params.agent_id));
    const qs = query.toString();
    return `${API_URL}/analytics/agents/export-conversations${qs ? `?${qs}` : ''}`;
  }

  async uploadFlowAttachment(flowId: string, file: File) {
    const formData = new FormData();
    formData.append('file', file);

    return this.request<{
      url: string;
      file_name: string;
      mime_type: string;
      size_bytes: number;
      data?: {
        url: string;
        file_name: string;
        mime_type: string;
        size_bytes: number;
      };
    }>(`/flows/${flowId}/attachments`, {
      method: 'POST',
      body: formData,
    });
  }

  async getFlowVersion(flowId: string, versionId: string) {
    return this.request<{ data: any }>(`/flows/${flowId}/versions/${versionId}`);
  }

  async createFlowVersion(flowId: string, payload?: { from_version_id?: string; description?: string }) {
    return this.request<{ message: string; data: any }>(`/flows/${flowId}/versions`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  }

  async saveFlowVersionGraph(flowId: string, versionId: string, payload: {
    expected_updated_at?: string | null;
    nodes: any[];
    edges: any[];
    metadata?: any;
  }) {
    return this.request<{ message: string; data?: { updated_at?: string; metadata?: any } }>(`/flows/${flowId}/versions/${versionId}/graph`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  async validateFlowVersion(flowId: string, versionId: string) {
    return this.request<{
      valid: boolean;
      is_valid: boolean;
      errors: string[];
      structured_errors: Array<{ code: string; node_key: string | null; message: string }>;
    }>(`/flows/${flowId}/versions/${versionId}/validate`, {
      method: 'POST',
    });
  }

  async getFlowVersions(flowId: string) {
    return this.request<{ data: any[]; active_version_id: string | null; publications?: any[] }>(`/flows/${flowId}/versions`);
  }

  async deleteFlowVersion(flowId: string, versionId: string) {
    return this.request<{ message: string }>(`/flows/${flowId}/versions/${versionId}`, {
      method: 'DELETE',
    });
  }

  async publishFlowVersion(flowId: string, versionId: string, notes?: string) {
    return this.request<{ message: string; data: any }>(`/flows/${flowId}/versions/${versionId}/publish`, {
      method: 'POST',
      body: JSON.stringify({ notes: notes || '' }),
    });
  }

  async rollbackFlowVersion(flowId: string, versionId: string, notes?: string) {
    return this.request<{ message: string; data: any }>(`/flows/${flowId}/versions/${versionId}/rollback`, {
      method: 'POST',
      body: JSON.stringify({ notes: notes || '' }),
    });
  }

  async compareFlowVersions(flowId: string, versionId: string, targetVersionId: string) {
    return this.request<{ data: any }>(`/flows/${flowId}/versions/${versionId}/compare/${targetVersionId}`);
  }

  async startSimulation(flowId: string, versionId: string, context?: Record<string, any>, saveDraftGraph?: { nodes: any[]; edges: any[] }) {
    return this.request<{ message: string; data: any }>(`/flows/${flowId}/versions/${versionId}/simulation/start`, {
      method: 'POST',
      body: JSON.stringify({
        context: context || {},
        ...(saveDraftGraph ? { save_draft_graph: saveDraftGraph } : {}),
      }),
    });
  }

  async stepSimulation(flowId: string, versionId: string, executionId: string, message: string) {
    return this.request<{ message: string; data: any }>(`/flows/${flowId}/versions/${versionId}/simulation/step`, {
      method: 'POST',
      body: JSON.stringify({ execution_id: executionId, message }),
    });
  }

  async resetSimulation(flowId: string, versionId: string, executionId?: string, context?: Record<string, any>) {
    return this.request<{ message: string; data: any }>(`/flows/${flowId}/versions/${versionId}/simulation/reset`, {
      method: 'POST',
      body: JSON.stringify({
        execution_id: executionId,
        context: context || {},
      }),
    });
  }

  async stopSimulation(flowId: string, versionId: string, executionId: string) {
    return this.request<{ message: string; data: any }>(`/flows/${flowId}/versions/${versionId}/simulation/stop`, {
      method: 'POST',
      body: JSON.stringify({ execution_id: executionId }),
    });
  }

  async getSimulationState(flowId: string, versionId: string, executionId: string) {
    return this.request<{ data: any }>(`/flows/${flowId}/versions/${versionId}/simulation/${executionId}`);
  }

  // Conversations
  async getConversations(params?: {
    status?: string;
    scope?: string;
    channel_id?: string;
    tag_id?: string | null;
    assigned_user_id?: string | number | null;
    search?: string;
    page?: number;
    team_id?: string;
  }) {
    const searchParams = new URLSearchParams();
    if (params?.status && params.status !== 'all') searchParams.set('status', params.status);
    if (params?.scope) searchParams.set('scope', params.scope);
    if (params?.team_id) searchParams.set('team_id', params.team_id);
    if (params?.channel_id) searchParams.set('channel_id', params.channel_id);
    if (params?.tag_id && params.tag_id !== 'all') searchParams.set('tag_id', params.tag_id);
    if (params?.assigned_user_id !== undefined && params?.assigned_user_id !== null && params?.assigned_user_id !== 'all') {
      searchParams.set('assigned_user_id', String(params.assigned_user_id));
    }
    if (params?.search && params.search.trim()) searchParams.set('search', params.search.trim());
    if (params?.page) searchParams.set('page', String(params.page));
    const queryString = searchParams.toString() ? `?${searchParams.toString()}` : '';
    return this.request<{
      data: any[];
      total: number;
      current_page?: number;
      last_page?: number;
      counts?: Record<string, number>;
    }>(`/conversations${queryString}`);
  }

  async getConversation(id: string) {
    return this.request<{ data: any; messages?: { data: any[]; has_more: boolean; oldest_message_id: string | null } }>(`/conversations/${id}`);
  }

  async getConversationMessages(id: string, params?: { before?: string; limit?: number }) {
    const searchParams = new URLSearchParams();
    if (params?.before) searchParams.set('before', params.before);
    if (params?.limit) searchParams.set('limit', String(params.limit));
    const queryString = searchParams.toString() ? `?${searchParams.toString()}` : '';
    return this.request<{ data: any[]; has_more: boolean; oldest_message_id: string | null }>(`/conversations/${id}/messages${queryString}`);
  }

  async claimConversation(id: string) {
    return this.request<{ message: string; data: any }>(`/conversations/${id}/claim`, {
      method: 'POST',
    });
  }

  async closeConversation(id: string) {
    return this.request<{ message: string; data: any }>(`/conversations/${id}/close`, {
      method: 'POST',
    });
  }

  async markConversationAsRead(id: string) {
    return this.request<{ message: string; data: any; unread_count: number }>(`/conversations/${id}/read`, {
      method: 'POST',
    });
  }

  async assignConversation(id: string, payload: { assigned_user_id: number | null; reason?: string }) {
    return this.request<{ message: string; data: any }>(`/conversations/${id}/assign`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async sendMessage(
    conversationId: string,
    content: string,
    options?: { visibility?: 'public' | 'internal'; attachment?: File | null }
  ) {
    if (options?.attachment) {
      const formData = new FormData();
      if (content.trim()) {
        formData.append('content', content.trim());
      }
      formData.append('visibility', options.visibility || 'public');
      formData.append('attachment', options.attachment);

      return this.request<{ message: string; data: any }>(`/conversations/${conversationId}/messages`, {
        method: 'POST',
        body: formData,
      });
    }

    return this.request<{ message: string; data: any }>(`/conversations/${conversationId}/messages`, {
      method: 'POST',
      body: JSON.stringify({ content, visibility: options?.visibility || 'public' }),
    });
  }

  async sendWhatsAppTemplate(
    conversationId: string,
    template: {
      name: string;
      language?: { code: string };
      components?: any[];
    }
  ) {
    return this.request<{ message: string; data: any }>(`/conversations/${conversationId}/messages`, {
      method: 'POST',
      body: JSON.stringify({
        message_type: 'template',
        template,
        visibility: 'public',
      }),
    });
  }

  async getConversationExecution(conversationId: string) {
    return this.request<{ data: any }>(`/conversations/${conversationId}/execution`);
  }

  async toggleConversationBot(id: string) {
    return this.request<{ message: string; data: any }>(`/conversations/${id}/toggle-bot`, {
      method: 'POST',
    });
  }

  async restartConversationFlow(id: string) {
    return this.request<{ message: string; data: any }>(`/conversations/${id}/restart-flow`, {
      method: 'POST',
    });
  }

  async updateConversationContact(conversationId: string, name: string) {
    return this.request<{ message: string; contact: any }>(`/conversations/${conversationId}/contact`, {
      method: 'PATCH',
      body: JSON.stringify({ name }),
    });
  }

  async updateContact(contactId: string, name: string) {
    return this.request<{ message: string; contact: any }>(`/contacts/${contactId}`, {
      method: 'PATCH',
      body: JSON.stringify({ name }),
    });
  }

  async addConversationTag(conversationId: string, tag: string) {
    return this.request<{ message: string; tags: string[] }>(`/conversations/${conversationId}/tags`, {
      method: 'POST',
      body: JSON.stringify({ tag }),
    });
  }

  async removeConversationTag(conversationId: string, tag: string) {
    return this.request<{ message: string; tags: string[] }>(`/conversations/${conversationId}/tags/${encodeURIComponent(tag)}`, {
      method: 'DELETE',
    });
  }

  async syncConversationTags(conversationId: string, payload: { tag_ids?: string[]; tag_id?: string; tag?: string }) {
    return this.request<{ message?: string; data?: any; tags: any[] }>(`/conversations/${conversationId}/tags`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async transferConversation(conversationId: string, payload: { target_user_id: number; note?: string }) {
    return this.request<{ message: string; data: any }>(`/conversations/${conversationId}/transfer`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  // Tags
  async getTags() {
    return this.request<{ data: Tag[] }>('/tags');
  }

  async createTag(data: { name: string; color?: string }) {
    return this.request<{ data: Tag }>('/tags', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  async updateTag(id: string, data: { name?: string; color?: string }) {
    return this.request<{ data: Tag }>(`/tags/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  }

  async deleteTag(id: string) {
    return this.request<{ message: string }>(`/tags/${id}`, {
      method: 'DELETE',
    });
  }

  // Canned Responses
  async getCannedResponses(params?: { search?: string; category?: string; include_inactive?: boolean }) {
    const query = new URLSearchParams();
    if (params?.search) query.append('search', params.search);
    if (params?.category) query.append('category', params.category);
    if (params?.include_inactive) query.append('include_inactive', '1');
    const qs = query.toString();
    return this.request<{ data: CannedResponse[] }>(`/canned-responses${qs ? `?${qs}` : ''}`);
  }

  async createCannedResponse(data: {
    title: string;
    shortcut?: string | null;
    content: string;
    category?: string | null;
    is_active?: boolean;
  }) {
    return this.request<{ data: CannedResponse; message?: string }>('/canned-responses', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  async updateCannedResponse(
    id: string,
    data: Partial<{
      title: string;
      shortcut?: string | null;
      content: string;
      category?: string | null;
      is_active?: boolean;
    }>
  ) {
    return this.request<{ data: CannedResponse; message?: string }>(`/canned-responses/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  }

  async deleteCannedResponse(id: string) {
    return this.request<{ message: string }>(`/canned-responses/${id}`, {
      method: 'DELETE',
    });
  }

  // Audit Logs
  async getAuditLogs() {
    return this.request<{ data: any[]; total: number }>('/audit-logs');
  }

  // Health
  async getHealth() {
    const response = await fetch(`${BASE_URL}/health`);
    return response.json();
  }
}

export const api = new ApiService();
