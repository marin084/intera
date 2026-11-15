import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

window.Pusher = Pusher;

export interface EchoOptions {
  authEndpoint: string;
  bearerToken?: string | null;
  extraHeaders?: Record<string, string>;
}

function getXsrfToken(): string | null {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : null;
}

export function createEcho(options: EchoOptions): Echo<any> {
  const isHttps = window.location.protocol === 'https:';
  const host = window.location.hostname;
  const port = window.location.port
    ? Number(window.location.port)
    : (isHttps ? 443 : 80);

  const key = import.meta.env.VITE_REVERB_APP_KEY || 'intera_key';

  return new Echo({
    broadcaster: 'reverb',
    key: key,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: isHttps,
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: any) => ({
      authorize: async (socketId: string, callback: (error: any, data: any) => void) => {
        try {
          const headers: Record<string, string> = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.extraHeaders || {}),
          };

          if (options.bearerToken) {
            headers['Authorization'] = `Bearer ${options.bearerToken}`;
          }

          const xsrf = getXsrfToken();
          if (xsrf) {
            headers['X-XSRF-TOKEN'] = xsrf;
          }

          const response = await fetch(options.authEndpoint, {
            method: 'POST',
            headers,
            credentials: 'include',
            body: JSON.stringify({
              socket_id: socketId,
              channel_name: channel.name,
            }),
          });

          const data = await response.json();
          if (!response.ok) {
            callback(new Error(data.message || 'Error en autenticación realtime'), null);
          } else {
            callback(null, data);
          }
        } catch (err) {
          callback(err, null);
        }
      },
    }),
  });
}
