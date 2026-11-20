export interface User {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  is_platform_admin?: boolean;
  last_login_at: string | null;
  created_at?: string;
  roles?: { id: number; name: string; display_name: string }[] | string[];
  permissions?: string[];
  channel_access_scope?: 'all' | 'selected' | 'assigned' | 'none';
  accessible_channels?: { id: string; name: string; type: string; is_active: boolean }[];
  team_ids?: string[];
}

export interface WorkspaceMembership {
  id: string;
  name: string;
  slug: string;
  status: string;
  role?: string | { id: number; name: string; display_name: string } | null;
  is_active?: boolean;
  initial_owner_id?: number | null;
  member_count?: number | null;
  can_switch?: boolean;
}

export interface WorkspaceMember {
  id: number;
  membership_id: number;
  name: string;
  email: string;
  role?: { id: number; name: string; display_name: string } | null;
  is_active: boolean;
  is_owner: boolean;
}

export interface WorkspaceMemberLookup {
  id: number;
  name: string;
  email: string;
  already_member: boolean;
  membership_active: boolean;
  current_role?: { id: number; name: string; display_name: string } | null;
  suggested_role?: { id: number; name: string; display_name: string } | null;
}

export interface Team {
  id: string;
  name: string;
  description: string | null;
  is_default: boolean;
  is_active: boolean;
  queue_wait_target_seconds?: number | null;
  first_response_target_seconds?: number | null;
  queue_wait_target_default_seconds?: number;
  first_response_target_default_seconds?: number;
  queue_wait_target_effective_seconds?: number;
  first_response_target_effective_seconds?: number;
  members: Pick<User, 'id' | 'name' | 'email'>[];
  channels: Pick<Channel, 'id' | 'name' | 'type' | 'is_active'>[];
  waiting_count: number;
  average_wait_seconds: number;
}

export interface Role {
  id: number;
  name: string;
  display_name: string;
  description: string | null;
  max_concurrent_conversations?: number | null;
  permissions: {
    id: number;
    name: string;
    display_name: string;
    module: string;
  }[];
}

export interface Permission {
  id: number;
  name: string;
  display_name: string;
  module: string;
  description: string | null;
}

export interface WebchatSettingsData {
  id: string;
  public_key: string;
  display_name: string;
  allowed_origins: string[];
  accent_color: string | null;
  launcher_position: string;
  updated_at?: string;
}

export interface Channel {
  id: string;
  name: string;
  type: string;
  is_active: boolean;
  entry_flow_id?: string | null;
  entry_flow?: {
    id: string;
    name: string;
    status: string;
    active_version_id: string | null;
    active_version_number?: number;
  } | null;
  configuration: Record<string, unknown>;
  webchat_settings?: WebchatSettingsData | null;
  created_at: string;
  updated_at: string;
}

export interface Flow {
  id: string;
  name: string;
  description: string | null;
  status: 'draft' | 'published' | 'archived';
  channel_applicability: string[];
  active_version_id: string | null;
  active_version_number?: number;
  created_by?: string;
  updated_by?: string;
  created_at: string;
  updated_at: string;
  versions?: FlowVersion[];
  publications?: FlowPublication[];
}

export interface FlowPublication {
  id: string;
  flow_id?: string;
  action: 'publish' | 'rollback';
  flow_version_id: string;
  version_number?: number;
  previous_version_id?: string | null;
  previous_version_number?: number | null;
  published_by?: string;
  notes?: string | null;
  created_at: string;
  version?: FlowVersion;
  user?: any;
}

export interface FlowComparisonResult {
  source_version: { id: string; version_number: number; status: string };
  target_version: { id: string; version_number: number; status: string };
  has_changes?: boolean;
  is_identical?: boolean;
  stats: {
    nodes_added: number;
    nodes_removed: number;
    nodes_modified: number;
    nodes_moved?: number;
    edges_added: number;
    edges_removed: number;
    variables_changed?: number;
    variables_added?: number;
    variables_removed?: number;
  };
  summary: string;
  nodes: {
    added: Array<{ node_key: string; name: string; type: string; configuration?: any }>;
    removed: Array<{ node_key: string; name: string; type: string; configuration?: any }>;
    modified: Array<{ node_key: string; name: string; type: string; previous_name?: string; previous_type?: string; changes: string[] }>;
    moved?: Array<{ node_key: string; name: string; from: { x: number; y: number }; to: { x: number; y: number } }>;
  };
  edges: {
    added: Array<{ source_node_key: string; target_node_key: string; condition_handle?: string | null }>;
    removed: Array<{ source_node_key: string; target_node_key: string; condition_handle?: string | null }>;
  };
  variables: {
    added: any[];
    removed: any[];
    modified: any[];
  };
  highlights: string[];
}

export interface FlowVersion {
  id: string;
  flow_id: string;
  version_number: number;
  status: 'draft' | 'published' | 'archived';
  is_active?: boolean;
  description: string | null;
  published_at: string | null;
  published_by?: string;
  created_at?: string;
  updated_at?: string;
  nodes_count?: number;
  edges_count?: number;
  metadata?: Record<string, any>;
  nodes?: FlowNode[];
  edges?: FlowEdge[];
}

export type FlowNodeType =
  | 'start'
  | 'message'
  | 'image'
  | 'file'
  | 'question'
  | 'condition'
  | 'menu'
  | 'http_request'
  | 'goto'
  | 'set_variable'
  | 'transfer_to_agent'
  | 'end';

export interface FlowVariable {
  name: string;
  type: 'text' | 'number' | 'boolean' | 'date' | 'json' | 'list';
  scope: 'conversation' | 'contact' | 'temporary' | 'system';
  initial_value?: any;
  description?: string;
}

export interface SimulationStep {
  id: string;
  step_number?: number;
  flow_node_id?: string | null;
  node_key?: string | null;
  node_name?: string | null;
  node_type: string;
  status: string;
  entered_at?: string;
  completed_at?: string;
  input?: Record<string, any>;
  output?: Record<string, any>;
  error_code?: string;
  metadata?: Record<string, any>;
}

export interface SimulationEvent {
  order: number;
  type: string;
  label: string;
  node_key?: string | null;
  node_name?: string | null;
  status: 'success' | 'waiting' | 'error';
  details?: string | null;
  timestamp?: string;
}

export interface SimulationState {
  simulation_id?: string;
  execution_id: string;
  conversation_id: string;
  flow_id?: string;
  flow_version_id?: string;
  version_number?: number;
  version_status?: string;
  status: 'running' | 'waiting_input' | 'waiting_agent' | 'completed' | 'failed' | 'cancelled';
  failure_code?: string | null;
  waiting_since?: string;
  current_node?: {
    id: string;
    node_key: string;
    name: string;
    type: FlowNodeType;
    configuration?: Record<string, any>;
  } | null;
  context: Record<string, any>;
  messages: Array<{
    id: string;
    sender_type: string;
    direction: string;
    message_type?: string;
    content: string;
    payload?: Record<string, any>;
    metadata?: Record<string, any>;
    sent_at?: string;
  }>;
  steps: SimulationStep[];
  events?: SimulationEvent[];
  taken_edge?: {
    id: string;
    source_node_key: string;
    target_node_key: string;
    condition_handle?: string | null;
  } | null;
}

export interface FlowNode {
  id?: string;
  node_key: string;
  type: FlowNodeType;
  name: string;
  configuration: Record<string, any>;
  position_x: number;
  position_y: number;
}

export interface FlowEdge {
  id?: string;
  source_node_key: string;
  target_node_key: string;
  condition_handle?: string | null;
  source_handle?: string | null;
  sourceHandle?: string | null;
  metadata?: Record<string, any>;
}

export interface FlowValidationError {
  code: string;
  node_key: string | null;
  message: string;
}

export interface Contact {
  id: string;
  name?: string | null;
  display_name: string | null;
  email: string | null;
  phone: string | null;
  country?: string | null;
  external_identifier: string | null;
  metadata?: Record<string, any> | null;
  created_at?: string;
  updated_at?: string;
}

export interface ConversationAttachment {
  id: string;
  message_id: string;
  type: 'image' | 'file';
  url: string;
  file_name: string;
  file_size?: number | null;
  mime_type?: string | null;
  sent_at?: string;
  sender_type?: string;
}

export interface InternalNote {
  id: string;
  content: string;
  sender_id?: string | null;
  sender_name?: string;
  author_name?: string;
  sender_type?: string;
  sent_at?: string;
  created_at?: string;
}

export interface AttachmentMetadata {
  url: string;
  file_name: string;
  mime_type: string;
  size_bytes: number;
  disk_path?: string;
  media_id?: string;
  is_voice_note?: boolean;
}

export interface Message {
  id: string;
  conversation_id: string;
  sender_type: 'contact' | 'bot' | 'agent' | 'system';
  sender_id: string | null;
  sender_name?: string | null;
  direction: 'inbound' | 'outbound';
  message_type: 'text' | 'system' | 'image' | 'file' | 'audio';
  visibility?: 'public' | 'internal';
  status?: 'pending' | 'sent' | 'delivered' | 'read' | 'failed';
  external_message_id?: string | null;
  content: string;
  payload?: {
    attachment?: AttachmentMetadata;
    [key: string]: any;
  };
  metadata?: Record<string, any>;
  sent_at: string;
  created_at?: string;
}

export interface ConversationAssignment {
  id: string;
  conversation_id: string;
  assigned_user_id: number;
  assigned_by_user_id: number | null;
  assigned_at: string;
  unassigned_at: string | null;
  reason: string | null;
  assigned_user?: { id: number; name: string };
  assigned_by_user?: { id: number; name: string };
}

export interface WhatsAppWindow {
  is_applicable: boolean;
  is_open: boolean;
  channel_type?: string;
  last_inbound_at: string | null;
  expires_at: string | null;
  remaining_seconds: number | null;
}

export type MetaWindow = WhatsAppWindow;

export interface Tag {
  id: string;
  name: string;
  slug?: string;
  color: string;
  created_at?: string;
  updated_at?: string;
}

export interface Conversation {
  id: string;
  channel_id: string;
  contact_id: string;
  team_id?: string | null;
  queued_at?: string | null;
  status: 'bot' | 'waiting_agent' | 'assigned' | 'closed';
  bot_enabled?: boolean;
  whatsapp_window?: WhatsAppWindow;
  meta_window?: MetaWindow;
  assigned_user_id: number | null;
  closing_assigned_user_id?: number | null;
  active_flow_id: string | null;
  started_at: string;
  last_message_at: string | null;
  closed_at: string | null;
  channel?: { id: string; name: string; type: string };
  team?: {
    id: string;
    name: string;
    queue_wait_target_effective_seconds?: number;
    first_response_target_effective_seconds?: number;
  } | null;
  current_assignment_at?: string | null;
  first_agent_response_at?: string | null;
  contact?: Contact;
  assigned_user?: { id: number; name: string; email: string };
  messages?: Message[];
  assignments?: ConversationAssignment[];
  unread_count?: number;
  tags?: Array<Tag | string>;
  metadata?: Record<string, any> | null;
  variables?: Record<string, any>;
  attachments?: ConversationAttachment[];
  internal_notes?: InternalNote[];
  created_at?: string;
  updated_at?: string;
}

export interface PaginatedMessages {
  data: Message[];
  has_more: boolean;
  oldest_message_id: string | null;
}

export interface AuditLog {
  id: string;
  action: string;
  entity_type: string | null;
  entity_id: string | null;
  user: { id: number; name: string; email: string } | null;
  ip_address: string | null;
  user_agent: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

export interface FlowExecutionStep {
  id: string;
  node_id: string | null;
  node_key: string | null;
  node_name: string | null;
  node_type: string;
  status: 'entered' | 'completed' | 'waiting' | 'failed' | 'skipped';
  entered_at: string;
  completed_at: string | null;
  input: Record<string, unknown> | null;
  output: Record<string, unknown> | null;
  error_code: string | null;
  metadata: Record<string, unknown> | null;
}

export interface FlowExecution {
  id: string;
  flow: { id: string; name: string } | null;
  version: { id: string; version_number: number } | null;
  status: 'running' | 'waiting_input' | 'waiting_agent' | 'completed' | 'failed' | 'cancelled';
  current_node: { id: string; node_key: string; name: string; type: string } | null;
  context: Record<string, unknown>;
  started_at: string;
  waiting_since: string | null;
  completed_at: string | null;
  failed_at: string | null;
  failure_code: string | null;
  steps: FlowExecutionStep[];
}

export interface CannedResponse {
  id: string;
  title: string;
  shortcut: string | null;
  formatted_shortcut: string | null;
  content: string;
  category?: string | null;
  is_active: boolean;
  created_by?: number | null;
  creator?: { id: number; name: string; email: string };
  created_at?: string;
  updated_at?: string;
}

export interface MenuOptionBreakdown {
  option_id: string;
  title: string;
  target_node_id: string | null;
  target_node_key: string | null;
  clicks_count: number;
  percentage: number;
}

export interface MenuBreakdown {
  node_id: string;
  node_key: string;
  node_title: string;
  total_selections: number;
  options: MenuOptionBreakdown[];
}

export interface FlowAnalyticsKPIs {
  total_executions: number;
  unique_contacts: number;
  completed_executions: number;
  completion_rate: number;
  dropoff_count: number;
  dropoff_rate: number;
  top_section: {
    title: string;
    clicks_count: number;
    percentage: number;
    menu_title?: string;
  } | null;
}

export interface FlowTimeSeriesPoint {
  date: string;
  executions: number;
  completed: number;
}

export interface FunnelAvailableStep {
  id: string;
  node_id: string;
  node_key?: string;
  node_type: string;
  label: string;
  option_id?: string | null;
  option_title?: string | null;
  target_node_key?: string | null;
}

export interface SequentialStepDefinition {
  id: string;
  node_id: string;
  node_key?: string;
  node_type?: string;
  label: string;
  option_id?: string | null;
  option_title?: string | null;
  target_node_key?: string | null;
  is_active: boolean;
}

export interface SavedFunnel {
  id: string;
  name: string;
  flow_id: string;
  steps: SequentialStepDefinition[];
  created_by?: number | null;
  creator?: {
    id: number;
    name: string;
    email: string;
  } | null;
  created_at?: string;
  updated_at?: string;
}

export interface SequentialFunnelStep {
  step_number: number;
  label: string;
  node_id?: string | null;
  node_key?: string | null;
  node_type?: string | null;
  option_id?: string | null;
  option_title?: string | null;
  users_count: number;
  conversion_from_previous: number;
  dropoff_from_previous: number;
  conversion_from_first: number;
}

export interface FlowAnalyticsData {
  flow_id: string;
  flow_name: string;
  date_range: {
    from: string;
    to: string;
    from_formatted?: string;
    to_formatted?: string;
  };
  kpis: FlowAnalyticsKPIs;
  menu_breakdown: MenuBreakdown[];
  time_series: FlowTimeSeriesPoint[];
  available_steps?: FunnelAvailableStep[];
  default_steps?: SequentialStepDefinition[];
  sequential_funnel?: SequentialFunnelStep[];
  configured_steps?: SequentialStepDefinition[];
}

export interface FunnelContact {
  contact_id: string | null;
  contact_name: string;
  phone_number: string | null;
  email: string | null;
  channel_type: string;
  channel_name: string;
  conversation_id: string | null;
  execution_id?: string;
  stepped_at: string;
  stepped_at_formatted?: string;
}

export interface UsersAndSessionsKPIs {
  total_sessions: number;
  unique_contacts: number;
  agent_sessions: number;
  bot_only_sessions: number;
  closed_by_agents: number;
  agent_session_percentage: number;
  bot_only_percentage: number;
  message_counts: {
    user_messages: number;
    bot_messages: number;
    agent_messages: number;
    total_messages: number;
  };
}

export interface ChannelDistributionItem {
  channel_id: string;
  channel_name: string;
  channel_type: string;
  count: number;
  percentage: number;
}

export interface TopicDistributionItem {
  tag_id: string;
  name: string;
  color: string;
  count: number;
  percentage: number;
}

export interface UsersAndSessionsData {
  date_range: {
    from: string;
    to: string;
    from_formatted?: string;
    to_formatted?: string;
  };
  kpis: UsersAndSessionsKPIs;
  channel_distribution: ChannelDistributionItem[];
  topics_distribution?: TopicDistributionItem[];
  untagged_count?: number;
  untagged_percentage?: number;
  time_series: Array<{
    date: string;
    total_sessions: number;
    agent_sessions: number;
    bot_sessions: number;
  }>;
}

export interface RealtimeLastHourKPIs {
  active_webchat_visitors: number;
  unread_chats: number;
  chats_last_hour: number;
  chats_previous_hour: number;
  chats_trend_percentage: number;
  messages_last_hour: {
    sent: number;
    received: number;
    total: number;
  };
}

export interface RealtimeOperatingTimes {
  avg_queue_wait_seconds: number;
  avg_queue_wait_formatted: string;
  avg_first_response_seconds: number;
  avg_first_response_formatted: string;
  avg_resolution_seconds: number;
  avg_resolution_formatted: string;
}

export interface RealtimeTargetAlertCounts {
  tracked: number;
  on_time: number;
  at_risk: number;
  overdue: number;
}

export interface RealtimeAgentConversations {
  ongoing: number;
  closed_today: number;
  active_agents_count: number;
}

export interface RealtimeChannelSummary {
  channel_id: string;
  channel_name: string;
  channel_type: string;
  is_active: boolean;
  ongoing_conversations: number;
  closed_conversations: number;
  waiting_response_count: number;
  avg_response_time_seconds: number;
  avg_response_time_formatted: string;
}

export interface RealtimeData {
  last_hour_kpis: RealtimeLastHourKPIs;
  operating_times: RealtimeOperatingTimes;
  service_target_alerts?: {
    queue: RealtimeTargetAlertCounts;
    first_response: RealtimeTargetAlertCounts;
  };
  agent_conversations: RealtimeAgentConversations;
  channels_summary: RealtimeChannelSummary[];
  updated_at: string;
}

export interface AgentMetricRow {
  agent_id: number;
  name: string;
  email: string;
  avatar_url: string | null;
  roles: string[];
  presence_status: 'online' | 'busy' | 'offline';
  last_seen_at: string | null;
  last_seen_formatted: string;
  connected_seconds?: number | null;
  connected_formatted?: string | null;
  channels: Array<{ id: string; name: string; type: string }>;
  assigned_conversations: number;
  active_conversations: number;
  closed_conversations: number;
  closed_percentage: number;
  waiting_response_count: number;
  avg_first_response_seconds: number;
  avg_first_response_formatted: string;
  avg_response_time_seconds: number;
  avg_response_time_formatted: string;
  avg_attention_duration_seconds: number;
  avg_attention_duration_formatted: string;
}

export interface AgentMetricsKPIs {
  total_assigned: number;
  total_active: number;
  total_closed: number;
  avg_first_response_seconds: number;
  avg_first_response_formatted: string;
  avg_response_time_seconds: number;
  avg_response_time_formatted: string;
  avg_attention_duration_seconds: number;
  avg_attention_duration_formatted: string;
  total_connected_seconds?: number | null;
  total_connected_formatted?: string | null;
}

export interface AgentMetricsData {
  date_range: {
    from: string;
    to: string;
    from_formatted?: string;
    to_formatted?: string;
  };
  kpis: AgentMetricsKPIs;
  presence_tracking_started_at?: string | null;
  agents: AgentMetricRow[];
}



