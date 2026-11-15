import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';

const BASE_URL = 'http://localhost:3000';
const DEMO_URL = 'http://localhost:3000/examples/webchat-demo.html';

async function loginSPA(page: Page, email: string, pass: string) {
  await page.goto(`${BASE_URL}/login`);
  await page.waitForSelector('input[type="email"]');
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', pass);
  await page.click('button[type="submit"]');
  await page.waitForSelector('text=Bandeja de Entrada', { timeout: 15000 });
}

async function openChatWidget(page: Page) {
  await page.waitForSelector('#conexa-widget-container', { state: 'attached', timeout: 15000 });
  await page.locator('.conexa-launcher').click();
  await page.waitForSelector('#conexa-chat-iframe', { state: 'visible', timeout: 15000 });
  return page.frameLocator('#conexa-chat-iframe');
}

test.describe('V1 Inbox Operability & Conversation Lifecycle E2E', () => {
  test.beforeEach(async () => {
    execSync('docker compose exec -T backend php artisan test:setup-e2e', { stdio: 'pipe' });
  });

  test('Section 41: Multi-Context Realtime Lifecycle (Visitor, Supervisor, Agent A, Agent B)', async ({ browser, request: _request }) => {
    // 1. Create 4 isolated browser contexts
    const visitorContext = await browser.newContext();
    const supervisorContext = await browser.newContext();
    const agentAContext = await browser.newContext();
    const agentBContext = await browser.newContext();

    const pageVisitor = await visitorContext.newPage();
    const pageSupervisor = await supervisorContext.newPage();
    const pageAgentA = await agentAContext.newPage();
    const pageAgentB = await agentBContext.newPage();

    // 2. Authenticate operators
    await loginSPA(pageSupervisor, 'supervisor@conexa.test', 'TestSupervisorPass@2026');
    await loginSPA(pageAgentA, 'agent@conexa.test', 'TestAgentPass@2026');
    await loginSPA(pageAgentB, 'agent_b@conexa.test', 'TestAgentPass@2026');

    // 3. Visitor navigates to demo and requests human agent
    await pageVisitor.goto(DEMO_URL);
    const chatFrame = await openChatWidget(pageVisitor);

    await expect(chatFrame.locator('text=Bienvenido al asistente virtual')).toBeVisible({ timeout: 15000 });
    await expect(chatFrame.locator('text=¿Cuál es tu nombre?')).toBeVisible({ timeout: 10000 });

    const inputArea = chatFrame.locator('textarea');
    await inputArea.fill('Carlos Operable');
    await chatFrame.locator('button[type="submit"]').click();

    await expect(chatFrame.locator('text=Mucho gusto, Carlos Operable.')).toBeVisible({ timeout: 10000 });
    await expect(chatFrame.locator('text=¿Deseas hablar con un agente humano?')).toBeVisible({ timeout: 10000 });

    const choiceBtn = chatFrame.locator('button:has-text("Sí quiero agente")');
    await expect(choiceBtn).toBeVisible({ timeout: 5000 });
    await choiceBtn.click();

    await expect(chatFrame.locator('text=buscando agente disponible')).toBeVisible({ timeout: 10000 });

    // 4. Realtime verification: Conversation appears in Supervisor, Agent A, and Agent B
    const supervisorItem = pageSupervisor.locator('[data-testid="conversation-item"]').first();
    await expect(supervisorItem).toBeVisible({ timeout: 15000 });
    await expect(supervisorItem.locator('.badge-warning')).toContainText('Esperando Agente');

    const agentAItem = pageAgentA.locator('[data-testid="conversation-item"]').first();
    await expect(agentAItem).toBeVisible({ timeout: 15000 });
    await expect(agentAItem.locator('.badge-warning')).toContainText('Esperando Agente');

    const agentBItem = pageAgentB.locator('[data-testid="conversation-item"]').first();
    await expect(agentBItem).toBeVisible({ timeout: 15000 });
    await expect(agentBItem.locator('.badge-warning')).toContainText('Esperando Agente');

    // 5. Agent A claims the conversation
    const claimBtnA = agentAItem.locator('[data-testid="claim-conversation-btn"]');
    await expect(claimBtnA).toBeVisible({ timeout: 5000 });
    await claimBtnA.click();

    // 6. Realtime verification: Agent B immediately loses the conversation from queue
    await expect(pageAgentB.locator('[data-testid="conversation-item"]')).toHaveCount(0, { timeout: 10000 });

    // 7. Supervisor sees conversation assigned to Agent E2E in real time
    await supervisorItem.click();
    await expect(pageSupervisor.locator('text=Agente: Agent E2E')).toBeVisible({ timeout: 10000 });

    // Switch Agent A to "Mis Conversaciones" tab to see claimed conversation without opening it yet
    await pageAgentA.locator('button:has-text("Mis Conversaciones")').click();
    const agentAAssignedItem = pageAgentA.locator('[data-testid="conversation-item"]').first();
    await expect(agentAAssignedItem).toBeVisible({ timeout: 10000 });

    // 8. Visitor sends a new message -> Agent A observes unread badge increment
    await inputArea.fill('Mensaje de prueba unread');
    await chatFrame.locator('button[type="submit"]').click();

    const unreadBadge = agentAAssignedItem.locator('[data-testid="unread-badge"]');
    await expect(unreadBadge).toBeVisible({ timeout: 10000 });
    await expect(unreadBadge).toContainText('3');

    // 9. Agent A opens the conversation -> unread badge clears
    await agentAAssignedItem.click();
    await expect(unreadBadge).not.toBeVisible({ timeout: 10000 });
    await expect(pageAgentA.locator('text=Mensaje de prueba unread')).toBeVisible({ timeout: 10000 });

    // 10. Agent A replies -> Visitor receives in real time
    const agentReplyInput = pageAgentA.locator('[data-testid="agent-reply-input"]');
    await expect(agentReplyInput).toBeVisible({ timeout: 5000 });
    await agentReplyInput.fill('Hola desde Agent A');
    await pageAgentA.locator('[data-testid="agent-reply-submit"]').click();

    await expect(chatFrame.locator('text=Hola desde Agent A')).toBeVisible({ timeout: 15000 });

    // 11. Visitor replies -> Agent A receives in real time
    await inputArea.fill('Muchas gracias');
    await chatFrame.locator('button[type="submit"]').click();

    await expect(pageAgentA.locator('text=Muchas gracias')).toBeVisible({ timeout: 15000 });

    // 12. Agent A closes the conversation
    const closeBtn = pageAgentA.locator('[data-testid="close-conversation-btn"]');
    await expect(closeBtn).toBeVisible({ timeout: 5000 });
    await closeBtn.click();

    // 13. Agent A composer disappears and closed banner appears
    await expect(pageAgentA.locator('[data-testid="conversation-closed-banner"]')).toBeVisible({ timeout: 10000 });
    await expect(pageAgentA.locator('[data-testid="agent-reply-input"]')).toHaveCount(0);

    // 14. Visitor widget observes conversation finalization in real time
    await expect(chatFrame.locator('text=Esta conversación ha finalizado.')).toBeVisible({ timeout: 15000 });
    await expect(inputArea).toBeDisabled();

    // 15. Supervisor observes closed status in real time
    await expect(pageSupervisor.locator('.badge-secondary:has-text("Cerrada")').first()).toBeVisible({ timeout: 10000 });

    // 16. Attempting to post message via API to closed conversation is blocked with HTTP 422
    const convId = execSync(
      `docker compose exec -T backend php artisan tinker --execute="echo App\\Models\\Message::where('content', 'Muchas gracias')->latest('created_at')->value('conversation_id');"`,
      { encoding: 'utf8' }
    ).trim();

    // Attempt from Agent via authenticated page session
    const agentPostRes = await pageAgentA.evaluate(async (cid) => {
      const xsrf = decodeURIComponent(
        document.cookie
          .split('; ')
          .find((row) => row.startsWith('XSRF-TOKEN='))
          ?.split('=')[1] || ''
      );
      const res = await fetch(`/api/v1/conversations/${cid}/messages`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-XSRF-TOKEN': xsrf,
        },
        body: JSON.stringify({ content: 'Intento de mensaje a conversación cerrada' }),
      });
      return { status: res.status, body: await res.json().catch(() => ({})) };
    }, convId);

    expect(agentPostRes.status).toBe(422);
    expect(agentPostRes.body.code || agentPostRes.body.message).toMatch(/conversation_closed/i);

    await visitorContext.close();
    await supervisorContext.close();
    await agentAContext.close();
    await agentBContext.close();
  });

  test('Section 42: Admin message pagination with 120 messages', async ({ page }) => {
    // 1. Seed conversation with 120 messages
    execSync(
      `docker compose exec -T backend php artisan tinker --execute='
        $channel = App\\Models\\Channel::first();
        $contact = App\\Models\\Contact::create([
            "channel_id" => $channel->id,
            "external_identifier" => "paginated_admin_visitor",
            "display_name" => "Paginated Admin Visitor",
        ]);
        $agent = App\\Models\\User::where("email", "agent@conexa.test")->first();
        $conv = App\\Models\\Conversation::create([
            "channel_id" => $channel->id,
            "contact_id" => $contact->id,
            "status" => App\\Enums\\ConversationStatus::ASSIGNED,
            "assigned_user_id" => $agent->id,
            "started_at" => now()->subHours(3),
            "last_message_at" => now(),
        ]);
        App\\Models\\ConversationAssignment::create([
            "conversation_id" => $conv->id,
            "assigned_user_id" => $agent->id,
            "assigned_by_user_id" => $agent->id,
            "assigned_at" => now()->subHours(3),
        ]);
        for ($i = 1; $i <= 120; $i++) {
            App\\Models\\Message::create([
                "conversation_id" => $conv->id,
                "sender_type" => App\\Enums\\MessageSenderType::CONTACT,
                "direction" => App\\Enums\\MessageDirection::INBOUND,
                "message_type" => App\\Enums\\MessageType::TEXT,
                "visibility" => "public",
                "content" => sprintf("ADMIN_PAGINATED_MSG_%03d", $i),
                "sent_at" => now()->subMinutes(130 - $i),
            ]);
        }
      '`,
      { encoding: 'utf8' }
    );

    // 2. Login Supervisor and open conversation
    await loginSPA(page, 'supervisor@conexa.test', 'TestSupervisorPass@2026');
    await page.locator(`[data-testid="conversation-item"]:has-text("Paginated Admin Visitor")`).click();

    // 3. Verify initial load has <= 50 messages
    await expect(page.locator('text=ADMIN_PAGINATED_MSG_120')).toBeVisible({ timeout: 15000 });
    // First message (1) should NOT be loaded initially (only messages 71 to 120)
    await expect(page.locator('text=ADMIN_PAGINATED_MSG_001')).not.toBeVisible();

    const loadMoreBtn = page.locator('[data-testid="load-older-messages-btn"]');
    await expect(loadMoreBtn).toBeVisible({ timeout: 5000 });

    // 4. Click load older messages -> loads previous batch
    await loadMoreBtn.click();
    await expect(page.locator('text=ADMIN_PAGINATED_MSG_070')).toBeVisible({ timeout: 10000 });

    // Still has more (messages 1-20 remain)
    await expect(loadMoreBtn).toBeVisible({ timeout: 5000 });

    // 5. Click load older messages again -> loads oldest batch (1-20)
    await loadMoreBtn.click();
    await expect(page.locator('text=ADMIN_PAGINATED_MSG_001')).toBeVisible({ timeout: 10000 });

    // All 120 loaded: button disappears
    await expect(loadMoreBtn).not.toBeVisible({ timeout: 5000 });

    // Verify no duplicates: check count of specific message
    const msg120Count = await page.locator('text=ADMIN_PAGINATED_MSG_120').count();
    expect(msg120Count).toBe(1);
    const msg001Count = await page.locator('text=ADMIN_PAGINATED_MSG_001').count();
    expect(msg001Count).toBe(1);
  });

  test('Section 43: Webchat widget pagination with 120 messages', async ({ page }) => {
    // 1. Visitor opens demo page and initializes conversation
    await page.goto(DEMO_URL);
    const chatFrame = await openChatWidget(page);
    await expect(chatFrame.locator('text=Bienvenido al asistente virtual')).toBeVisible({ timeout: 15000 });

    // 2. Fetch the conversation ID created for this visitor
    const convId = execSync(
      `docker compose exec -T backend php artisan tinker --execute='echo App\\Models\\Conversation::value("id");'`,
      { encoding: 'utf8' }
    ).trim();

    // 3. Seed 120 messages into this conversation using single quotes
    execSync(
      `docker compose exec -T backend php artisan tinker --execute='
        $conv = App\\Models\\Conversation::findOrFail("${convId}");
        for ($i = 1; $i <= 120; $i++) {
            App\\Models\\Message::create([
                "conversation_id" => $conv->id,
                "sender_type" => App\\Enums\\MessageSenderType::CONTACT,
                "direction" => App\\Enums\\MessageDirection::INBOUND,
                "message_type" => App\\Enums\\MessageType::TEXT,
                "visibility" => "public",
                "content" => sprintf("WEBCHAT_PAGINATED_MSG_%03d", $i),
                "sent_at" => now()->subMinutes(130 - $i),
            ]);
        }
      '`,
      { encoding: 'utf8' }
    );

    // 4. Reload page to let webchat load paginated messages from REST
    await page.reload();
    const reloadedChatFrame = await openChatWidget(page);

    // 5. Verify initial load has latest messages and "Cargar mensajes anteriores" button
    await expect(reloadedChatFrame.locator('text=WEBCHAT_PAGINATED_MSG_120')).toBeVisible({ timeout: 15000 });
    await expect(reloadedChatFrame.locator('text=WEBCHAT_PAGINATED_MSG_001')).not.toBeVisible();

    const loadMoreBtn = reloadedChatFrame.locator('[data-testid="webchat-load-older-btn"]');
    await expect(loadMoreBtn).toBeVisible({ timeout: 5000 });

    // 6. Click load older messages -> loads previous batch
    await loadMoreBtn.click();
    await expect(reloadedChatFrame.locator('text=WEBCHAT_PAGINATED_MSG_070')).toBeVisible({ timeout: 10000 });

    // 7. Click load older messages again -> loads oldest batch
    await expect(loadMoreBtn).toBeVisible({ timeout: 5000 });
    await loadMoreBtn.click();
    await expect(reloadedChatFrame.locator('text=WEBCHAT_PAGINATED_MSG_001')).toBeVisible({ timeout: 10000 });

    // All messages loaded: button disappears
    await expect(loadMoreBtn).not.toBeVisible({ timeout: 5000 });

    // Verify uniqueness
    const msg120Count = await reloadedChatFrame.locator('text=WEBCHAT_PAGINATED_MSG_120').count();
    expect(msg120Count).toBe(1);
  });
});
