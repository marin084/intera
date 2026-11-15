export interface WebchatSettings {
  display_name: string;
  accent_color: string | null;
  launcher_position: string;
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

export interface WebchatMessage {
  id: string;
  client_message_id?: string | null;
  sender_type: 'contact' | 'bot' | 'agent' | 'system';
  direction: 'inbound' | 'outbound';
  message_type: 'text' | 'system' | 'image' | 'file' | 'audio' | 'interactive';
  content: string;
  payload?: {
    attachment?: AttachmentMetadata;
    options?: Array<{ id?: string; title?: string; text?: string; [key: string]: any } | string>;
    buttons?: Array<{ id?: string; title?: string; text?: string; [key: string]: any } | string>;
    validation_error?: boolean;
    [key: string]: any;
  } | null;
  metadata?: Record<string, any> | null;
  sent_at: string;
  created_at: string;
}

export interface WebchatConversation {
  id: string;
  status: 'bot' | 'waiting_agent' | 'assigned' | 'closed';
  started_at: string;
  last_message_at: string;
  closed_at?: string | null;
}

export interface WebchatSessionData {
  session_id: string;
  visitor_token: string;
  contact: {
    display_name: string;
  };
  active_conversation: WebchatConversation | null;
  expires_at: string;
}

export interface InitMessagePayload {
  type: 'conexa:init';
  publicKey: string;
  embedToken: string;
  hostOrigin: string;
  settings: WebchatSettings;
}
