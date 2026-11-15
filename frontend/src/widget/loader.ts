/**
 * Intera Embeddable Webchat Loader
 * Zero-dependency, isolated launcher and iframe manager.
 */

(function () {
  if ((window as any).__CONEXA_LOADED__) {
    return;
  }
  (window as any).__CONEXA_LOADED__ = true;

  // 1. Locate current script element and read configuration
  const currentScript =
    document.currentScript ||
    document.querySelector('script[data-channel]');

  if (!currentScript) {
    console.warn('[Intera] No se encontró el elemento script con el atributo data-channel.');
    return;
  }

  const publicKey = currentScript.getAttribute('data-channel');
  if (!publicKey) {
    console.warn('[Intera] Atributo data-channel vacío.');
    return;
  }

  const scriptSrc = (currentScript as HTMLScriptElement).src;
  const widgetOrigin = scriptSrc ? new URL(scriptSrc).origin : window.location.origin;

  // 2. Perform initial embed handshake with backend
  const hostOrigin = window.location.origin;

  fetch(`${widgetOrigin}/api/v1/public/webchat/${encodeURIComponent(publicKey)}/embed`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({ origin: hostOrigin }),
  })
    .then((res) => {
      if (!res.ok) {
        throw new Error(`Embed rejected: status ${res.status}`);
      }
      return res.json();
    })
    .then((payload) => {
      const { embed_token, settings } = payload.data;
      initWidget(publicKey, widgetOrigin, hostOrigin, embed_token, settings);
    })
    .catch((err) => {
      console.warn('[Intera] No se pudo inicializar el Webchat:', err.message);
    });

  function initWidget(
    key: string,
    wOrigin: string,
    hOrigin: string,
    embedToken: string,
    settings: { display_name: string; accent_color: string | null; launcher_position: string }
  ) {
    const position = settings.launcher_position === 'bottom-left' ? 'left' : 'right';
    const accentColor = settings.accent_color || '#2563EB';

    // 3. Create Shadow DOM host container for the launcher to avoid host CSS pollution
    const container = document.createElement('div');
    container.id = 'conexa-widget-container';
    container.style.all = 'initial';
    document.body.appendChild(container);

    const shadow = container.attachShadow({ mode: 'open' });

    // Launcher styles
    const style = document.createElement('style');
    style.textContent = `
      :host {
        all: initial;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      }
      .conexa-launcher {
        position: fixed;
        bottom: 24px;
        ${position}: 24px;
        width: 60px;
        height: 60px;
        border-radius: 30px;
        background-color: ${accentColor};
        color: #ffffff;
        border: none;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2147483646;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        outline: none;
      }
      .conexa-launcher:hover {
        transform: scale(1.05);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
      }
      .conexa-launcher:focus-visible {
        outline: 3px solid #93C5FD;
        outline-offset: 2px;
      }
      .conexa-icon {
        width: 28px;
        height: 28px;
        fill: currentColor;
        transition: transform 0.2s ease, opacity 0.2s ease;
      }
      .conexa-icon-close {
        display: none;
      }
      .conexa-launcher.is-open .conexa-icon-chat {
        display: none;
      }
      .conexa-launcher.is-open .conexa-icon-close {
        display: block;
      }
    `;
    shadow.appendChild(style);

    // Launcher button
    const launcher = document.createElement('button');
    launcher.className = 'conexa-launcher';
    launcher.setAttribute('aria-label', `Abrir chat de ${settings.display_name || 'soporte'}`);
    launcher.setAttribute('type', 'button');
    launcher.innerHTML = `
      <svg class="conexa-icon conexa-icon-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
      </svg>
      <svg class="conexa-icon conexa-icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="18" y1="6" x2="6" y2="18"></line>
        <line x1="6" y1="6" x2="18" y2="18"></line>
      </svg>
    `;
    shadow.appendChild(launcher);

    // 4. Floating iframe (instantiated lazily on first open)
    let iframe: HTMLIFrameElement | null = null;
    let isOpen = false;

    function applyResponsiveDimensions(
      frame: HTMLIFrameElement,
      pos: string,
      mobile: boolean,
      open: boolean
    ) {
      if (mobile) {
        if (open) {
          Object.assign(frame.style, {
            width: '100%',
            height: '100%',
            top: '0',
            left: '0',
            right: '0',
            bottom: '0',
            borderRadius: '0',
            maxHeight: '100%',
            maxWidth: '100%',
          });
        }
      } else {
        Object.assign(frame.style, {
          width: '380px',
          height: '620px',
          maxHeight: 'calc(100vh - 110px)',
          maxWidth: 'calc(100vw - 40px)',
          bottom: '96px',
          top: 'auto',
          left: pos === 'left' ? '24px' : 'auto',
          right: pos === 'right' ? '24px' : 'auto',
          borderRadius: '16px',
        });
      }
    }

    function getOrCreateIframe(): HTMLIFrameElement {
      if (iframe) return iframe;

      iframe = document.createElement('iframe');
      iframe.id = 'conexa-chat-iframe';
      iframe.title = settings.display_name || 'Webchat';
      iframe.src = `${wOrigin}/widget.html?channel=${encodeURIComponent(key)}`;
      iframe.setAttribute('allow', 'clipboard-write');

      const isMobile = window.innerWidth <= 480;
      Object.assign(iframe.style, {
        position: 'fixed',
        border: 'none',
        zIndex: '2147483647',
        display: 'none',
        opacity: '0',
        transition: 'opacity 0.2s ease, transform 0.2s ease',
        transform: 'translateY(12px)',
        boxShadow: '0 8px 30px rgba(0, 0, 0, 0.25)',
        borderRadius: '16px',
        overflow: 'hidden',
      });

      applyResponsiveDimensions(iframe, position, isMobile, false);
      document.body.appendChild(iframe);
      return iframe;
    }

    function toggleChat(state?: boolean) {
      isOpen = state !== undefined ? state : !isOpen;
      const currentMobile = window.innerWidth <= 480;

      if (isOpen) {
        const frame = getOrCreateIframe();
        applyResponsiveDimensions(frame, position, currentMobile, true);
        frame.style.display = 'block';
        // Request animation frame for smooth opacity transition
        requestAnimationFrame(() => {
          frame.style.opacity = '1';
          frame.style.transform = 'translateY(0)';
        });
        launcher.classList.add('is-open');
        launcher.setAttribute('aria-label', 'Cerrar chat de soporte');
        frame.contentWindow?.postMessage(
          { type: 'conexa:view_state', isOpen: true },
          wOrigin
        );
      } else {
        if (!iframe) return;
        iframe.style.opacity = '0';
        iframe.style.transform = 'translateY(12px)';
        launcher.classList.remove('is-open');
        launcher.setAttribute('aria-label', `Abrir chat de ${settings.display_name || 'soporte'}`);
        iframe.contentWindow?.postMessage(
          { type: 'conexa:view_state', isOpen: false },
          wOrigin
        );
        setTimeout(() => {
          if (!isOpen && iframe) {
            iframe.style.display = 'none';
          }
        }, 200);
      }
    }

    launcher.addEventListener('click', () => toggleChat());

    // Window resize handler
    window.addEventListener('resize', () => {
      if (iframe) {
        const mob = window.innerWidth <= 480;
        applyResponsiveDimensions(iframe, position, mob, isOpen);
      }
    });

    // Escape key closes the widget
    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && isOpen) {
        toggleChat(false);
      }
    });

    // 5. PostMessage listener with strict origin validation
    window.addEventListener('message', (event) => {
      if (event.origin !== wOrigin) {
        return;
      }

      const data = event.data;
      if (!data || typeof data !== 'object') {
        return;
      }

      if (data.type === 'conexa:ready' && iframe) {
        // Send initial handshake and tokens back to iframe
        iframe.contentWindow?.postMessage(
          {
            type: 'conexa:init',
            publicKey: key,
            embedToken: embedToken,
            hostOrigin: hOrigin,
            settings: settings,
          },
          wOrigin
        );
      } else if (data.type === 'conexa:close') {
        toggleChat(false);
      } else if (data.type === 'conexa:open') {
        toggleChat(true);
      }
    });
  }
})();
