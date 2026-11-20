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
    settings: {
      display_name: string;
      accent_color: string | null;
      launcher_position: string;
      launcher_style?: 'circle' | 'expanded' | string;
      availability_threshold?: number;
      availability_high_message?: string;
      availability_low_message?: string;
      availability_zero_message?: string;
      launcher_cta_label?: string;
      launcher_description?: string;
      launcher_icon_url?: string | null;
      available_agent_count?: number;
    }
  ) {
    const position = settings.launcher_position === 'bottom-left' ? 'left' : 'right';
    const accentColor = settings.accent_color || '#2563EB';
    const launcherStyle = settings.launcher_style === 'expanded' ? 'expanded' : 'circle';
    const availabilityThreshold = Math.max(1, Number(settings.availability_threshold) || 3);
    const availableAgentCount = Math.max(0, Number(settings.available_agent_count) || 0);
    const formatAvailabilityMessage = (template: string, includeCount = false) => {
      const message = includeCount ? template.replaceAll('{count}', String(availableAgentCount)) : template;
      return availableAgentCount === 1
        ? message.replace(/\bagentes\b/gi, 'agente').replace(/\bdisponibles\b/gi, 'disponible')
        : message;
    };
    const availabilityMessage = availableAgentCount === 0
      ? (settings.availability_zero_message || 'No hay agentes disponibles en este momento')
      : availableAgentCount >= availabilityThreshold
        ? formatAvailabilityMessage(settings.availability_high_message || '{count} agentes disponibles', true)
        : formatAvailabilityMessage(settings.availability_low_message || 'Agentes disponibles');
    const ctaLabel = settings.launcher_cta_label || 'Chatear ahora';
    const launcherDescription = settings.launcher_description || 'Estamos aquí para ayudarte.';
    const launcherIconUrl = settings.launcher_icon_url || null;

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
        font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      }
      .conexa-launcher {
        position: fixed;
        bottom: 24px;
        ${position}: 16px;
        width: 60px;
        height: 60px;
        border-radius: 30px;
        background-color: ${accentColor};
        color: #ffffff;
        border: none;
        overflow: hidden;
        padding: 0;
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
      .conexa-launcher.is-open .conexa-launcher-image {
        display: none;
      }
      .conexa-launcher-image {
        width: 100%;
        height: 100%;
        display: block;
        object-fit: cover;
        border-radius: 50%;
      }
      .conexa-launcher.is-open .conexa-icon-close {
        display: block;
      }
      .conexa-expanded-launcher {
        position: fixed;
        bottom: 24px;
        ${position}: 16px;
        width: min(340px, calc(100vw - 32px));
        z-index: 2147483646;
        font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      }
      .conexa-expanded-card {
        width: 100%;
        box-sizing: border-box;
        padding: 15px 14px;
        border-radius: 18px;
        background: #ffffff;
        color: #18332e;
        border: 1px solid rgba(93, 143, 130, 0.24);
        box-shadow: 0 12px 32px rgba(19, 44, 39, 0.2);
        font-family: inherit;
      }
      .conexa-expanded-close {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 30px;
        height: 30px;
        border: 0;
        border-radius: 50%;
        background: transparent;
        color: #61726d;
        cursor: pointer;
        font-size: 22px;
        line-height: 1;
      }
      .conexa-expanded-close:hover {
        background: #eef5f2;
      }
      .conexa-expanded-status {
        display: flex;
        align-items: center;
        gap: 8px;
        padding-right: 24px;
        font-size: 17px;
        font-weight: 700;
        line-height: 1.25;
      }
      .conexa-expanded-status-dot {
        flex: 0 0 auto;
        width: 11px;
        height: 11px;
        border-radius: 50%;
        background: #2eaa68;
      }
      .conexa-expanded-description {
        margin-top: 6px;
        color: #61726d;
        font-size: 14px;
        line-height: 1.45;
      }
      .conexa-expanded-cta {
        display: block;
        width: 46%;
        min-width: 150px;
        max-width: 180px;
        margin: 14px 0 0 auto;
        padding: 10px 14px;
        border: 0;
        border-radius: 10px;
        background: ${accentColor};
        color: #ffffff;
        font: inherit;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 8px 20px rgba(19, 44, 39, 0.16);
        transition: transform 0.2s ease, filter 0.2s ease;
      }
      .conexa-expanded-cta:hover {
        filter: brightness(0.94);
        transform: translateY(-1px);
      }
      .conexa-expanded-cta:focus-visible,
      .conexa-expanded-close:focus-visible {
        outline: 3px solid #93C5FD;
        outline-offset: 2px;
      }
      @media (max-width: 480px) {
        /* En móvil el launcher expandido se reemplaza por el botón circular. */
        .conexa-launcher {
          display: flex !important;
        }
        .conexa-expanded-launcher {
          display: none !important;
          bottom: 16px;
          ${position}: 12px;
          width: calc(100vw - 32px);
        }
        .conexa-expanded-card {
          padding: 14px;
        }
        .conexa-expanded-status {
          font-size: 16px;
        }
      }
    `;
    shadow.appendChild(style);

    // Launcher button
    const launcher = document.createElement('button');
    launcher.className = 'conexa-launcher';
    launcher.setAttribute('aria-label', `Abrir chat de ${settings.display_name || 'soporte'}`);
    launcher.setAttribute('type', 'button');
    const defaultChatIcon = '<svg class="conexa-icon conexa-icon-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
    const closeChatIcon = '<svg class="conexa-icon conexa-icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
    if (launcherIconUrl) {
      const launcherImage = document.createElement('img');
      launcherImage.className = 'conexa-icon conexa-launcher-image';
      launcherImage.src = launcherIconUrl;
      launcherImage.alt = '';
      launcherImage.addEventListener('error', () => {
        launcherImage.remove();
        launcher.insertAdjacentHTML('afterbegin', defaultChatIcon);
      });
      launcher.append(launcherImage);
      launcher.insertAdjacentHTML('beforeend', closeChatIcon);
    } else {
      launcher.innerHTML = defaultChatIcon + closeChatIcon;
    }
    shadow.appendChild(launcher);

    // Optional expanded launcher. It intentionally uses no agent photos or personal data.
    const expandedLauncher = document.createElement('div');
    expandedLauncher.className = 'conexa-expanded-launcher';
    expandedLauncher.setAttribute('aria-label', `Disponibilidad de ${settings.display_name || 'soporte'}`);

    const expandedCard = document.createElement('section');
    expandedCard.className = 'conexa-expanded-card';

    const expandedClose = document.createElement('button');
    expandedClose.className = 'conexa-expanded-close';
    expandedClose.type = 'button';
    expandedClose.setAttribute('aria-label', 'Mostrar launcher circular');
    expandedClose.textContent = '×';

    const expandedStatus = document.createElement('div');
    expandedStatus.className = 'conexa-expanded-status';
    const statusDot = document.createElement('span');
    statusDot.className = 'conexa-expanded-status-dot';
    statusDot.setAttribute('aria-hidden', 'true');
    const statusText = document.createElement('span');
    statusText.textContent = availabilityMessage;
    expandedStatus.append(statusDot, statusText);

    const expandedDescription = document.createElement('div');
    expandedDescription.className = 'conexa-expanded-description';
    expandedDescription.textContent = launcherDescription;

    const expandedCta = document.createElement('button');
    expandedCta.className = 'conexa-expanded-cta';
    expandedCta.type = 'button';
    expandedCta.textContent = ctaLabel;

    expandedCard.append(expandedClose, expandedStatus, expandedDescription);
    expandedLauncher.append(expandedCard, expandedCta);
    shadow.appendChild(expandedLauncher);
    expandedLauncher.style.display = launcherStyle === 'expanded' ? 'block' : 'none';
    launcher.style.display = launcherStyle === 'expanded' ? 'none' : 'flex';

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
        expandedLauncher.style.display = 'none';
        launcher.style.display = 'flex';
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
        launcher.style.display = 'flex';
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
    expandedCta.addEventListener('click', () => toggleChat(true));
    expandedClose.addEventListener('click', () => {
      expandedLauncher.style.display = 'none';
      launcher.style.display = 'flex';
    });

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
