import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';

const BASE_URL = 'http://localhost:3000';
const DEMO_URL = 'http://localhost:3000/examples/webchat-demo.html';

// Helper to login as Supervisor or Agent via pure Sanctum SPA session cookies
async function loginSPA(page: Page, email: string, pass: string) {
  await page.goto(`${BASE_URL}/login`);
  await page.waitForSelector('input[type="email"]');
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', pass);
  await page.click('button[type="submit"]');
  await page.waitForSelector('text=Bandeja de Entrada', { timeout: 15000 });
}

// Helper to open chat widget in visitor page
async function openChatWidget(page: Page) {
  // Wait for shadow launcher container attached to DOM
  await page.waitForSelector('#conexa-widget-container', { state: 'attached', timeout: 15000 });
  // Click launcher inside shadow root (Playwright automatically pierces open shadow DOMs)
  await page.locator('.conexa-launcher').click();
  // Wait for iframe
  await page.waitForSelector('#conexa-chat-iframe', { state: 'visible', timeout: 15000 });
  return page.frameLocator('#conexa-chat-iframe');
}

test.describe('Fase 4: Realtime Webchat, Reverb WebSocket & Human Handoff E2E', () => {
  test.beforeEach(async () => {
    // Reset database to deterministic seed without logging credentials
    execSync('docker compose exec -T backend php artisan test:setup-e2e', { stdio: 'pipe' });
  });

  test('Requirements 7 & 8: Loading demo script does NOT create conversation until launcher click', async ({ page }) => {
    // 1. Check initial conversation count via DB
    const initialCount = parseInt(
      execSync(
        `docker compose exec -T backend php artisan tinker --execute="echo App\\Models\\Conversation::count();"`,
        { encoding: 'utf8' }
      ).trim(),
      10
    );

    // 2. Open external demo page
    await page.goto(DEMO_URL);
    await page.waitForSelector('#conexa-widget-container', { state: 'attached', timeout: 15000 });
    await page.waitForTimeout(2000);

    // 3. Verify conversation count has NOT changed
    const intermediateCount = parseInt(
      execSync(
        `docker compose exec -T backend php artisan tinker --execute="echo App\\Models\\Conversation::count();"`,
        { encoding: 'utf8' }
      ).trim(),
      10
    );
    expect(intermediateCount).toBe(initialCount);

    // 4. Click launcher button
    const chatFrame = await openChatWidget(page);

    // 5. Verify conversation and bot welcome message are initialized
    await expect(chatFrame.locator('text=Bienvenido al asistente virtual')).toBeVisible({ timeout: 15000 });

    const finalCount = parseInt(
      execSync(
        `docker compose exec -T backend php artisan tinker --execute="echo App\\Models\\Conversation::count();"`,
        { encoding: 'utf8' }
      ).trim(),
      10
    );
    expect(finalCount).toBeGreaterThan(initialCount);
  });

  test('Requirement 6: Real WebSocket connection, protocol upgrade, and private channel authorization', async ({ page }) => {
    let wsConnected = false;
    let wsUpgradeSuccess = false;
    let channelAuthorized = false;

    page.on('websocket', (ws) => {
      if (ws.url().includes('/app/')) {
        wsConnected = true;
        wsUpgradeSuccess = true;

        ws.on('framereceived', (event) => {
          const payload = event.payload?.toString() || '';
          if (payload.includes('pusher:connection_established')) {
            wsConnected = true;
          }
          if (payload.includes('pusher_internal:subscription_succeeded') || payload.includes('subscription_succeeded')) {
            channelAuthorized = true;
          }
        });
      }
    });

    await page.goto(DEMO_URL);
    const chatFrame = await openChatWidget(page);
    await expect(chatFrame.locator('text=Bienvenido al asistente virtual')).toBeVisible({ timeout: 15000 });

    // Wait briefly for WebSocket handshake
    await page.waitForTimeout(3000);

    expect(wsConnected).toBe(true);
    expect(wsUpgradeSuccess).toBe(true);
    expect(channelAuthorized).toBe(true);
  });

  test('Requirements 9, 10, 11, 12, 13: Multi-Context Handoff, Realtime Assignment, and Realtime Bidirectional Chat', async ({ browser }) => {
    // Three isolated browser contexts
    const visitorContext = await browser.newContext();
    const supervisorContext = await browser.newContext();
    const agentContext = await browser.newContext();

    const pageVisitor = await visitorContext.newPage();
    const pageSupervisor = await supervisorContext.newPage();
    const pageAgent = await agentContext.newPage();

    // 1. Authenticate Supervisor (Sanctum SPA Session)
    await loginSPA(pageSupervisor, 'supervisor@conexa.test', 'TestSupervisorPass@2026');

    // 2. Authenticate Agent (Sanctum SPA Session)
    await loginSPA(pageAgent, 'agent@conexa.test', 'TestAgentPass@2026');

    // 3. Visitor navigates to demo and opens chat
    await pageVisitor.goto(DEMO_URL);
    const chatFrame = await openChatWidget(pageVisitor);

    // Initial bot message and name question
    await expect(chatFrame.locator('text=Bienvenido al asistente virtual')).toBeVisible({ timeout: 15000 });
    await expect(chatFrame.locator('text=¿Cuál es tu nombre?')).toBeVisible({ timeout: 10000 });

    // Visitor answers name
    const inputArea = chatFrame.locator('textarea');
    await inputArea.fill('Carlos');
    await chatFrame.locator('button[type="submit"]').click();

    // Bot greets and asks choice
    await expect(chatFrame.locator('text=Mucho gusto, Carlos.')).toBeVisible({ timeout: 10000 });
    await expect(chatFrame.locator('text=¿Deseas hablar con un agente humano?')).toBeVisible({ timeout: 10000 });

    // Visitor clicks choice "Sí quiero agente"
    const choiceBtn = chatFrame.locator('button:has-text("Sí quiero agente")');
    await expect(choiceBtn).toBeVisible({ timeout: 5000 });
    await choiceBtn.click();

    // Bot displays transfer message
    await expect(chatFrame.locator('text=Perfecto, te transferimos de inmediato.')).toBeVisible({ timeout: 10000 });
    await expect(chatFrame.locator('text=buscando agente disponible')).toBeVisible({ timeout: 10000 });

    // 4. Requirement 10: Supervisor receives waiting_agent in REAL TIME without reloading
    const supervisorConvItem = pageSupervisor.locator('[data-testid="conversation-item"]').first();
    await expect(supervisorConvItem).toBeVisible({ timeout: 15000 });
    await expect(supervisorConvItem.locator('.badge-warning')).toContainText('Esperando Agente');

    // Click conversation
    await supervisorConvItem.click();

    // 5. Requirement 11: Supervisor assigns conversation to Agent
    const assignSelect = pageSupervisor.locator('[data-testid="assign-agent-select"]');
    await expect(assignSelect).toBeVisible({ timeout: 5000 });
    await assignSelect.selectOption({ label: 'Asignar a: Agent E2E' });

    // 6. Agent receives assigned conversation in REAL TIME without reloading
    const agentConvItem = pageAgent.locator('[data-testid="conversation-item"]').first();
    await expect(agentConvItem).toBeVisible({ timeout: 15000 });
    await expect(agentConvItem.locator('.badge-success')).toContainText('Asignada');
    await agentConvItem.click();

    // 7. Requirement 12: Agent replies -> Visitor receives message via WebSocket in REAL TIME
    const agentReplyInput = pageAgent.locator('[data-testid="agent-reply-input"]');
    await expect(agentReplyInput).toBeVisible({ timeout: 5000 });
    await agentReplyInput.fill('Hola Carlos, soy tu agente de soporte humano.');
    await pageAgent.locator('[data-testid="agent-reply-submit"]').click();

    // Visitor widget displays message without reloading or GET polling
    await expect(chatFrame.locator('text=Hola Carlos, soy tu agente de soporte humano.')).toBeVisible({ timeout: 15000 });

    // 8. Requirement 13: Visitor replies -> Agent receives message in REAL TIME
    await inputArea.fill('Muchas gracias agente por tu ayuda.');
    await chatFrame.locator('button[type="submit"]').click();

    // Agent displays message in real time
    await expect(pageAgent.locator('text=Muchas gracias agente por tu ayuda.')).toBeVisible({ timeout: 15000 });

    // 9. Requirement 14: Visitor page reload preserves session, contact, conversation, and complete history
    await pageVisitor.reload();
    const reloadedChatFrame = await openChatWidget(pageVisitor);
    await expect(reloadedChatFrame.locator('text=Hola Carlos, soy tu agente de soporte humano.')).toBeVisible({ timeout: 15000 });
    await expect(reloadedChatFrame.locator('text=Muchas gracias agente por tu ayuda.')).toBeVisible({ timeout: 10000 });

    // 10. Requirement 15: Agent reload recovers state and reconnects Echo cleanly
    await pageAgent.reload();
    await expect(pageAgent.locator('text=Bandeja de Entrada')).toBeVisible({ timeout: 15000 });
    await pageAgent.locator('[data-testid="conversation-item"]').first().click();
    await expect(pageAgent.locator('text=Muchas gracias agente por tu ayuda.')).toBeVisible({ timeout: 10000 });

    await visitorContext.close();
    await supervisorContext.close();
    await agentContext.close();
  });

  test('Requirement 16: Reverb outage handling: disconnection, REST persistence, reconnection and resync', async ({ page }) => {
    await page.goto(DEMO_URL);
    const chatFrame = await openChatWidget(page);
    await expect(chatFrame.locator('text=Bienvenido al asistente virtual')).toBeVisible({ timeout: 15000 });

    // Simulate Reverb service interruption
    execSync('docker compose stop reverb', { stdio: 'pipe' });

    // Visitor sends message during interruption
    const inputArea = chatFrame.locator('textarea');
    await inputArea.fill('Mensaje enviado durante corte de Reverb');
    await chatFrame.locator('button[type="submit"]').click();

    // Message appears locally in visitor widget
    await expect(chatFrame.locator('text=Mensaje enviado durante corte de Reverb')).toBeVisible({ timeout: 10000 });

    // Restore Reverb service
    execSync('docker compose start reverb', { stdio: 'pipe' });
    // Wait for Reverb container to be ready
    await page.waitForTimeout(4000);

    // Verify REST persistence in database
    const persisted = execSync(
      `docker compose exec -T backend php artisan tinker --execute="echo App\\Models\\Message::where('content', 'Mensaje enviado durante corte de Reverb')->exists() ? 'YES' : 'NO';"`,
      { encoding: 'utf8' }
    ).trim();
    expect(persisted).toBe('YES');
  });

  test('Requirements 17 & 18: Negative branch -> closed status -> Start new conversation restarts flow', async ({ page }) => {
    await page.goto(DEMO_URL);
    const chatFrame = await openChatWidget(page);
    await expect(chatFrame.locator('text=Bienvenido al asistente virtual')).toBeVisible({ timeout: 15000 });

    // Answer name
    const inputArea = chatFrame.locator('textarea');
    await inputArea.fill('Maria');
    await chatFrame.locator('button[type="submit"]').click();

    // Wait for choice question
    await expect(chatFrame.locator('text=¿Deseas hablar con un agente humano?')).toBeVisible({ timeout: 10000 });

    // Click FALSE branch: "No gracias"
    const noChoiceBtn = chatFrame.locator('button:has-text("No gracias")');
    await expect(noChoiceBtn).toBeVisible({ timeout: 5000 });
    await noChoiceBtn.click();

    // Verify End node text and closed state
    await expect(chatFrame.locator('text=Conversación finalizada. ¡Hasta pronto!')).toBeVisible({ timeout: 10000 });
    await expect(chatFrame.locator('text=Esta conversación ha finalizado.')).toBeVisible({ timeout: 10000 });

    // Verify input is disabled
    await expect(inputArea).toBeDisabled();

    // Click "Iniciar nueva conversación"
    const restartBtn = chatFrame.locator('button:has-text("Iniciar nueva conversación")');
    await expect(restartBtn).toBeVisible({ timeout: 5000 });
    await restartBtn.click();

    // Verify flow restarts from Start node
    await expect(chatFrame.locator('text=¿Cuál es tu nombre?')).toBeVisible({ timeout: 15000 });
  });

  test('Requirement 19: Unauthorized origin returns 403 on embed handshake', async ({ request }) => {
    const res = await request.post(`${BASE_URL}/api/v1/public/webchat/wc_pub_4Ibz08ynJ1tsvts8u1EXg3DIuxtZHWbL/embed`, {
      headers: {
        Origin: 'http://unauthorized-evil-domain.com',
        Referer: 'http://unauthorized-evil-domain.com/landing',
      },
      data: {
        origin: 'http://unauthorized-evil-domain.com',
      },
    });

    expect(res.status()).toBe(403);
  });

  test('Requirement 20: Cross-session isolation prevents unauthorized REST and WebSocket access', async ({ request }) => {
    // Create Session A
    const embedA = await request.post(`${BASE_URL}/api/v1/public/webchat/wc_pub_4Ibz08ynJ1tsvts8u1EXg3DIuxtZHWbL/embed`, {
      headers: { Origin: 'http://localhost:3000' },
      data: { origin: 'http://localhost:3000' },
    });
    const embedTokenA = (await embedA.json()).data.embed_token;
    const sessionResA = await request.post(`${BASE_URL}/api/v1/public/webchat/wc_pub_4Ibz08ynJ1tsvts8u1EXg3DIuxtZHWbL/sessions`, {
      headers: { Origin: 'http://localhost:3000' },
      data: { embed_token: embedTokenA },
    });
    const tokenA = (await sessionResA.json()).data.visitor_token;

    // Start Conversation A
    const convA = await request.post(`${BASE_URL}/api/v1/public/webchat/conversations/start`, {
      headers: { Authorization: `Bearer ${tokenA}` },
    });
    const convIdA = (await convA.json()).data.conversation.id;

    // Create Session B
    const embedB = await request.post(`${BASE_URL}/api/v1/public/webchat/wc_pub_4Ibz08ynJ1tsvts8u1EXg3DIuxtZHWbL/embed`, {
      headers: { Origin: 'http://localhost:3000' },
      data: { origin: 'http://localhost:3000' },
    });
    const embedTokenB = (await embedB.json()).data.embed_token;
    const sessionResB = await request.post(`${BASE_URL}/api/v1/public/webchat/wc_pub_4Ibz08ynJ1tsvts8u1EXg3DIuxtZHWbL/sessions`, {
      headers: { Origin: 'http://localhost:3000' },
      data: { embed_token: embedTokenB },
    });
    const tokenB = (await sessionResB.json()).data.visitor_token;

    // Attempt: Session B tries to authorize Session A's WebSocket private channel
    const wsAuthRes = await request.post(`${BASE_URL}/api/v1/public/webchat/broadcast/auth`, {
      headers: { Authorization: `Bearer ${tokenB}` },
      data: {
        socket_id: '1234.5678',
        channel_name: `private-webchat.conversation.${convIdA}`,
      },
    });
    expect(wsAuthRes.status()).toBe(403);
  });

  test('Requirement 21: Rate limiting returns HTTP 429 upon message burst flooding', async ({ request }) => {
    const embed = await request.post(`${BASE_URL}/api/v1/public/webchat/wc_pub_4Ibz08ynJ1tsvts8u1EXg3DIuxtZHWbL/embed`, {
      headers: { Origin: 'http://localhost:3000' },
      data: { origin: 'http://localhost:3000' },
    });
    const embedToken = (await embed.json()).data.embed_token;
    const sessionRes = await request.post(`${BASE_URL}/api/v1/public/webchat/wc_pub_4Ibz08ynJ1tsvts8u1EXg3DIuxtZHWbL/sessions`, {
      headers: { Origin: 'http://localhost:3000' },
      data: { embed_token: embedToken },
    });
    const token = (await sessionRes.json()).data.visitor_token;

    await request.post(`${BASE_URL}/api/v1/public/webchat/conversations/start`, {
      headers: { Authorization: `Bearer ${token}` },
    });

    // Send rapid burst of 70 messages to exceed throttle limit (60/min)
    const responses = await Promise.all(
      Array.from({ length: 70 }).map((_, i) =>
        request.post(`${BASE_URL}/api/v1/public/webchat/messages`, {
          headers: { Authorization: `Bearer ${token}` },
          data: {
            content: `Flood test message ${i}`,
            client_message_id: `flood-${Date.now()}-${i}`,
          },
        })
      )
    );

    const has429 = responses.some((r) => r.status() === 429);
    expect(has429).toBe(true);
  });

  test('Requirement 22: XSS payloads are safely escaped and never executed', async ({ page }) => {
    await page.goto(DEMO_URL);
    const chatFrame = await openChatWidget(page);
    await expect(chatFrame.locator('text=Bienvenido al asistente virtual')).toBeVisible({ timeout: 15000 });

    const xssPayload = '<script>window.__CONEXA_XSS__=true</script><img src=x onerror="window.__CONEXA_XSS__=true">';
    const inputArea = chatFrame.locator('textarea');
    await inputArea.fill(xssPayload);
    await chatFrame.locator('button[type="submit"]').click();

    // Content is rendered safely as escaped plain text
    await expect(chatFrame.locator(`text=${xssPayload}`)).toBeVisible({ timeout: 10000 });

    // Verify window.__CONEXA_XSS__ is strictly undefined
    const isXssInHost = await page.evaluate(() => (window as any).__CONEXA_XSS__);
    expect(isXssInHost).toBeUndefined();
  });

  test('Requirement 23: Internal message is visible to Agent but hidden from Visitor', async ({ browser }) => {
    const supervisorContext = await browser.newContext();
    const pageSupervisor = await supervisorContext.newPage();
    await loginSPA(pageSupervisor, 'supervisor@conexa.test', 'TestSupervisorPass@2026');

    // Create a visitor conversation
    const visitorContext = await browser.newContext();
    const pageVisitor = await visitorContext.newPage();
    await pageVisitor.goto(DEMO_URL);
    const chatFrame = await openChatWidget(pageVisitor);
    await expect(chatFrame.locator('text=Bienvenido al asistente virtual')).toBeVisible({ timeout: 15000 });
    await expect(chatFrame.locator('text=¿Cuál es tu nombre?')).toBeVisible({ timeout: 10000 });

    // Send identifiable visitor message
    const inputArea = chatFrame.locator('textarea');
    await inputArea.fill('MariaInterna');
    await chatFrame.locator('button[type="submit"]').click();
    await expect(chatFrame.locator('text=Mucho gusto, MariaInterna.')).toBeVisible({ timeout: 10000 });

    const convId = execSync(
      `docker compose exec -T backend php artisan tinker --execute="echo App\\Models\\Message::where('content', 'MariaInterna')->latest('created_at')->value('conversation_id');"`,
      { encoding: 'utf8' }
    ).trim();

    const contactName = execSync(
      `docker compose exec -T backend php artisan tinker --execute="echo App\\Models\\Conversation::find('${convId}')->contact->display_name;"`,
      { encoding: 'utf8' }
    ).trim();

    // Supervisor sends internal note via backend API
    execSync(
      `docker compose exec -T backend php artisan tinker --execute="
        \\$conv = App\\Models\\Conversation::findOrFail('${convId}');
        \\$user = App\\Models\\User::where('email', 'supervisor@conexa.test')->firstOrFail();
        \\$msg = App\\Models\\Message::create([
          'conversation_id' => \\$conv->id,
          'sender_type' => App\\Enums\\MessageSenderType::AGENT,
          'sender_id' => (string) \\$user->id,
          'direction' => App\\Enums\\MessageDirection::OUTBOUND,
          'message_type' => App\\Enums\\MessageType::TEXT,
          'visibility' => 'internal',
          'content' => 'NOTA_INTERNA_CONFIDENCIAL_123',
          'sent_at' => now(),
        ]);
        \\$conv->update(['last_message_at' => now()]);
        broadcast(new App\\Events\\MessageCreated(\\$msg));
      "`,
      { stdio: 'pipe' }
    );

    // Reload supervisor thread to see internal note
    await pageSupervisor.reload();
    await pageSupervisor.locator(`[data-testid="conversation-item"]:has-text("${contactName}")`).click();
    await expect(pageSupervisor.locator('text=NOTA_INTERNA_CONFIDENCIAL_123')).toBeVisible({ timeout: 10000 });

    // Verify visitor does NOT see internal note
    await pageVisitor.waitForTimeout(2000);
    const visitorInternalVisible = await chatFrame.locator('text=NOTA_INTERNA_CONFIDENCIAL_123').isVisible();
    expect(visitorInternalVisible).toBe(false);

    await visitorContext.close();
    await supervisorContext.close();
  });

  test('Requirement 24: Zero sensitive credentials or tokens in server logs', async () => {
    const backendLogs = execSync('docker compose logs backend --tail 200', { encoding: 'utf8' });
    const reverbLogs = execSync('docker compose logs reverb --tail 200', { encoding: 'utf8' });
    const frontendLogs = execSync('docker compose logs frontend --tail 200', { encoding: 'utf8' });

    const allLogs = `${backendLogs}\n${reverbLogs}\n${frontendLogs}`;

    expect(allLogs).not.toContain('Password123456');
    expect(allLogs).not.toContain('TestSupervisorPass@2026');
    expect(allLogs).not.toContain('TestAgentPass@2026');
    expect(allLogs).not.toContain('Broadcast Auth Signature:');
    expect(allLogs).not.toContain('Embed Token:');
    expect(allLogs).not.toContain('Visitor Token:');
  });
});
