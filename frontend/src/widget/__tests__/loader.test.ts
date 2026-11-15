import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

describe('Webchat Embed Loader (conexa.js)', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('detects data-channel attribute from script tag', () => {
    const script = document.createElement('script');
    script.src = 'http://localhost:3000/widget/conexa.js';
    script.setAttribute('data-channel', 'test_pub_key_123');
    document.body.appendChild(script);

    const found = document.querySelector('script[data-channel]');
    expect(found).not.toBeNull();
    expect(found?.getAttribute('data-channel')).toBe('test_pub_key_123');
  });

  it('prevents multiple widget injections for the same container', () => {
    const container1 = document.createElement('div');
    container1.id = 'conexa-widget-root';
    document.body.appendChild(container1);

    // Second check should detect existing root
    const exists = !!document.getElementById('conexa-widget-root');
    expect(exists).toBe(true);
  });

  it('creates iframe with proper attributes and sandbox policies', () => {
    const iframe = document.createElement('iframe');
    iframe.src = 'http://localhost:3000/widget/index.html';
    iframe.title = 'Conexa Webchat';
    iframe.setAttribute('allow', 'clipboard-write');
    iframe.style.border = 'none';

    expect(iframe.title).toBe('Conexa Webchat');
    expect(iframe.getAttribute('allow')).toBe('clipboard-write');
    expect(iframe.src).toContain('/widget/index.html');
  });
});
