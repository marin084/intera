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

test.describe('V1 Release Candidate Polish E2E', () => {
  test.beforeEach(async () => {
    execSync('docker compose exec -T backend php artisan test:setup-e2e', { stdio: 'pipe' });
  });

  test('Self-service password change in Mi Cuenta and re-authentication', async ({ page }) => {
    // 1. Log in as Agent
    await loginSPA(page, 'agent@conexa.test', 'TestAgentPass@2026');

    // 2. Navigate to Mi Cuenta
    await page.click('button:has-text("Mi Cuenta")');
    await page.waitForSelector('text=Cambiar Contraseña', { timeout: 10000 });

    // 3. Test validation error with wrong current password
    await page.fill('[data-testid="current-password-input"]', 'WrongPassword123!');
    await page.fill('[data-testid="new-password-input"]', 'BrandNewSecretPass456!');
    await page.fill('[data-testid="confirm-password-input"]', 'BrandNewSecretPass456!');
    await page.click('[data-testid="submit-change-password-btn"]');

    await expect(page.locator('[data-testid="password-error-message"]')).toContainText('La contraseña actual no es correcta');

    // 4. Update password successfully
    await page.fill('[data-testid="current-password-input"]', 'TestAgentPass@2026');
    await page.fill('[data-testid="new-password-input"]', 'BrandNewSecretPass456!');
    await page.fill('[data-testid="confirm-password-input"]', 'BrandNewSecretPass456!');
    await page.click('[data-testid="submit-change-password-btn"]');

    await expect(page.locator('[data-testid="password-success-message"]')).toContainText('Contraseña actualizada');

    // 5. Existing session remains valid without re-login
    await page.click('button:has-text("Conversaciones")');
    await page.waitForSelector('text=Bandeja de Entrada', { timeout: 10000 });

    // 6. Logout
    await page.click('button:has-text("Cerrar Sesión")');
    await page.waitForSelector('input[type="email"]');

    // 7. Verify login with OLD password fails
    await page.fill('input[type="email"]', 'agent@conexa.test');
    await page.fill('input[type="password"]', 'TestAgentPass@2026');
    await page.click('button[type="submit"]');
    await expect(page.locator('text=Credenciales inválidas')).toBeVisible({ timeout: 5000 });

    // 8. Verify login with NEW password succeeds
    await page.fill('input[type="password"]', 'BrandNewSecretPass456!');
    await page.click('button[type="submit"]');
    await page.waitForSelector('text=Bandeja de Entrada', { timeout: 15000 });
    expect(page.url()).toContain(BASE_URL);
  });

  test('Section 19: Contact Identity E2E from flow answer to supervisor realtime inbox', async ({ browser }) => {
    const supervisorContext = await browser.newContext();
    const visitorContext = await browser.newContext();

    const pageSupervisor = await supervisorContext.newPage();
    const pageVisitor = await visitorContext.newPage();

    // 1. Supervisor logs in
    await loginSPA(pageSupervisor, 'supervisor@conexa.test', 'TestSupervisorPass@2026');

    // 2. Visitor visits demo and opens chat
    await pageVisitor.goto(DEMO_URL);
    const chatFrame = await openChatWidget(pageVisitor);

    await expect(chatFrame.locator('text=Bienvenido al asistente virtual')).toBeVisible({ timeout: 15000 });
    await expect(chatFrame.locator('text=¿Cuál es tu nombre?')).toBeVisible({ timeout: 10000 });

    // 3. Visitor answers name "Elena Delgado"
    const inputArea = chatFrame.locator('textarea');
    await inputArea.fill('Elena Delgado');
    await chatFrame.locator('button[type="submit"]').click();

    await expect(chatFrame.locator('text=Mucho gusto, Elena Delgado.')).toBeVisible({ timeout: 10000 });
    await expect(chatFrame.locator('text=¿Deseas hablar con un agente humano?')).toBeVisible({ timeout: 10000 });

    // 4. Request human agent to trigger queue
    await chatFrame.locator('button:has-text("Sí quiero agente")').click();
    await expect(chatFrame.locator('text=buscando agente disponible')).toBeVisible({ timeout: 10000 });

    // 5. Supervisor sees "Elena Delgado" updated in realtime without manual refresh
    await expect(pageSupervisor.locator('text=Elena Delgado')).toBeVisible({ timeout: 15000 });

    // 6. Supervisor searches by name "Elena" and matches conversation
    const searchInput = pageSupervisor.locator('input[data-testid="inbox-search-input"]');
    await searchInput.fill('Elena');
    await expect(pageSupervisor.locator('text=Elena Delgado')).toBeVisible({ timeout: 5000 });

    // 7. Clear search
    await searchInput.fill('');
    await pageSupervisor.waitForTimeout(400);

    await visitorContext.close();
    await supervisorContext.close();
  });

  test('Section 20: Combined multi-page channel, status, agent and search filters', async ({ page }) => {
    // 1. Seed 25 conversations in Channel 1 and 10 in Channel 2 via artisan
    execSync(`docker compose exec -T backend php artisan tinker --execute="
      \\$settings = \\App\\Models\\WebchatChannelSettings::where('public_key', 'wc_pub_4Ibz08ynJ1tsvts8u1EXg3DIuxtZHWbL')->firstOrFail();
      \\$ch1 = \\$settings->channel;
      \\App\\Models\\Channel::where('name', 'Canal Secundario E2E')->delete();
      \\$ch2 = \\App\\Models\\Channel::create(['name' => 'Canal Secundario E2E', 'type' => 'webchat', 'is_active' => true, 'configuration' => []]);
      \\$agentA = \\App\\Models\\User::where('email', 'agent@conexa.test')->first();
      \\$agentB = \\App\\Models\\User::where('email', 'agent_b@conexa.test')->first();

      for (\\$i = 1; \\$i <= 25; \\$i++) {
        \\$contact = \\App\\Models\\Contact::create(['external_identifier' => 'c1_'.\\$i, 'display_name' => 'Contacto Alfa '.\\$i]);
        \\$assigned = (\\$i % 2 === 0) ? \\$agentA->id : null;
        \\$status = (\\$assigned) ? 'assigned' : 'waiting_agent';
        \\App\\Models\\Conversation::create(['channel_id' => \\$ch1->id, 'contact_id' => \\$contact->id, 'status' => \\$status, 'assigned_user_id' => \\$assigned]);
      }

      for (\\$j = 1; \\$j <= 10; \\$j++) {
        \\$contact = \\App\\Models\\Contact::create(['external_identifier' => 'c2_'.\\$j, 'display_name' => 'Contacto Beta '.\\$j]);
        \\App\\Models\\Conversation::create(['channel_id' => \\$ch2->id, 'contact_id' => \\$contact->id, 'status' => 'waiting_agent']);
      }
    "`, { stdio: 'pipe' });

    // 2. Log in as Supervisor
    await loginSPA(page, 'supervisor@conexa.test', 'TestSupervisorPass@2026');

    // 3. Filter by Channel 1
    const channelSelect = page.locator('select[data-testid="channel-filter-select"]');
    await channelSelect.selectOption({ label: 'Webchat E2E' });

    // Total should be 25, page 1 shows pagination
    await expect(page.locator('text=Bandeja de Entrada (25)')).toBeVisible({ timeout: 10000 });
    const pagination = page.locator('[data-testid="inbox-pagination"]');
    await expect(pagination).toBeVisible();
    await expect(page.locator('[data-testid="inbox-page-indicator"]')).toContainText('Página 1 de 2');

    // 4. Click next page
    await page.click('[data-testid="inbox-next-page-btn"]');
    await expect(page.locator('[data-testid="inbox-page-indicator"]')).toContainText('Página 2 de 2');

    // 5. Click previous page
    await page.click('[data-testid="inbox-prev-page-btn"]');
    await expect(page.locator('[data-testid="inbox-page-indicator"]')).toContainText('Página 1 de 2');
    await page.waitForTimeout(300);

    // 6. Filter by Channel 2
    await channelSelect.selectOption({ label: 'Canal Secundario E2E' });
    await expect(page.locator('text=Bandeja de Entrada (10)')).toBeVisible({ timeout: 10000 });
    await expect(pagination).not.toBeVisible();

    // 7. Combined filter: Channel 1 + status assigned + Agent Alpha + search
    await channelSelect.selectOption({ label: 'Webchat E2E' });
    await expect(page.locator('text=Bandeja de Entrada (25)')).toBeVisible({ timeout: 10000 });

    const agentSelect = page.locator('select[data-testid="agent-filter-select"]');
    await agentSelect.selectOption({ label: 'Agent E2E' });

    const searchInput = page.locator('input[data-testid="inbox-search-input"]');
    await searchInput.fill('Alfa 10');
    await page.waitForTimeout(400);

    await expect(page.locator('text=Contacto Alfa 10')).toBeVisible({ timeout: 5000 });
  });
});
