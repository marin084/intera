import { test, expect, Page, APIRequestContext } from '@playwright/test';
import { execSync } from 'child_process';
import { randomUUID } from 'crypto';

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:3100';
const WORKSPACE_A_ID = '11111111-1111-4111-8111-111111111111';
const WORKSPACE_B_ID = '22222222-2222-4222-8222-222222222222';
const CHANNEL_A_KEY = 'wc_pub_e2e_workspace_a_20261120';

const USERS = {
  platform: { email: 'e2e.platform@intera.test', password: 'E2EPlatformPass@2026' },
  adminA: { email: 'e2e.admin.a@intera.test', password: 'E2EAdminAPass@2026' },
  adminB: { email: 'e2e.admin.b@intera.test', password: 'E2EAdminBPass@2026' },
};

async function loginSPA(page: Page, email: string, password: string) {
  await page.goto(`${BASE_URL}/login`);
  await page.waitForSelector('input[type="email"]');
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', password);
  await page.click('button[type="submit"]');
  await page.waitForSelector('text=Bandeja de Entrada', { timeout: 15000 });
}

async function apiJson(page: Page, path: string, init: RequestInit = {}) {
  return page.evaluate(async ({ path, init }) => {
    const xsrf = decodeURIComponent(
      document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1] || ''
    );
    const response = await fetch(path, {
      credentials: 'include',
      ...init,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrf,
        ...(init.headers || {}),
      },
    });
    return {
      status: response.status,
      body: await response.json().catch(() => ({})),
    };
  }, { path, init: { ...init, headers: { ...(init.headers || {}) } } });
}

async function startPublicConversation(request: APIRequestContext, publicKey: string) {
  const headers = { Accept: 'application/json', Origin: BASE_URL };
  const embedResponse = await request.post(`${BASE_URL}/api/v1/public/webchat/${publicKey}/embed`, { headers });
  expect(embedResponse.status()).toBe(200);
  const embed = await embedResponse.json();

  const sessionResponse = await request.post(`${BASE_URL}/api/v1/public/webchat/${publicKey}/sessions`, {
    headers,
    data: { embed_token: embed.data.embed_token },
  });
  expect(sessionResponse.status()).toBe(201);
  const session = await sessionResponse.json();
  const visitorToken = session.data.visitor_token;
  const visitorHeaders = {
    ...headers,
    Authorization: `Bearer ${visitorToken}`,
  };

  const startResponse = await request.post(`${BASE_URL}/api/v1/public/webchat/conversations/start`, {
    headers: visitorHeaders,
  });
  expect(startResponse.status()).toBe(201);

  return {
    visitorToken,
    displayName: session.data.contact.display_name as string,
    conversationId: (await startResponse.json()).data.conversation.id as string,
    visitorHeaders,
  };
}

test.describe('Multitenancy isolation E2E', () => {
  test.beforeEach(() => {
    execSync('docker compose exec -T backend php artisan test:setup-multitenancy-e2e --force', {
      stdio: 'pipe',
    });
  });

  test('workspace admins see and query only their own tenant', async ({ browser }) => {
    const contextA = await browser.newContext();
    const contextB = await browser.newContext();
    const pageA = await contextA.newPage();
    const pageB = await contextB.newPage();

    await loginSPA(pageA, USERS.adminA.email, USERS.adminA.password);
    await loginSPA(pageB, USERS.adminB.email, USERS.adminB.password);

    await expect(pageA.getByLabel('Workspace activo: E2E Workspace A')).toBeVisible();
    await expect(pageA.getByRole('button', { name: 'Auditoría' })).toHaveCount(0);
    await expect(pageA.getByRole('button', { name: 'Workspaces' })).toHaveCount(0);
    await expect(pageA.locator('select[aria-label="Workspace activo"]')).toHaveCount(0);

    await pageA.goto(`${BASE_URL}/users`);
    await expect(pageA.getByText('E2E Admin Workspace A', { exact: true })).toBeVisible();
    await expect(pageA.getByText('E2E Shared Agent', { exact: true })).toBeVisible();
    await expect(pageA.locator('body')).not.toContainText('E2E Admin Workspace B');

    await pageA.goto(`${BASE_URL}/flows`);
    await expect(pageA.getByText('E2E Flow A', { exact: true })).toBeVisible();
    await expect(pageA.locator('body')).not.toContainText('E2E Flow B');

    await pageB.goto(`${BASE_URL}/channels`);
    await expect(pageB.getByText('E2E Webchat B', { exact: true })).toBeVisible();
    await expect(pageB.locator('body')).not.toContainText('E2E Webchat A');

    const crossTenantFlows = await apiJson(pageA, '/api/v1/flows', {
      headers: { 'X-Workspace-Id': WORKSPACE_B_ID },
    });
    expect(crossTenantFlows.status).toBe(403);

    const workspaces = await apiJson(pageA, '/api/v1/workspaces');
    expect(workspaces.status).toBe(403);

    const audit = await apiJson(pageA, '/api/v1/audit-logs');
    expect(audit.status).toBe(403);

    const switchAttempt = await apiJson(pageA, '/api/v1/workspaces/switch', {
      method: 'POST',
      body: JSON.stringify({ workspace_id: WORKSPACE_B_ID }),
      headers: { 'Content-Type': 'application/json' },
    });
    expect(switchAttempt.status).toBe(403);

    const ownFlows = await apiJson(pageA, '/api/v1/flows');
    expect(ownFlows.status).toBe(200);
    expect(ownFlows.body.data.map((flow: { name: string }) => flow.name)).toEqual(['E2E Flow A']);

    await contextA.close();
    await contextB.close();
  });

  test('platform admin can see both tenants and switch the active workspace', async ({ page }) => {
    await loginSPA(page, USERS.platform.email, USERS.platform.password);

    await expect(page.getByLabel('Workspace activo')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Auditoría' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Workspaces' })).toBeVisible();

    const workspaces = await apiJson(page, '/api/v1/workspaces');
    expect(workspaces.status).toBe(200);
    expect(workspaces.body.data.map((workspace: { id: string }) => workspace.id)).toEqual(
      expect.arrayContaining([WORKSPACE_A_ID, WORKSPACE_B_ID])
    );

    await page.goto(`${BASE_URL}/flows`);
    await expect(page.getByText('E2E Flow A', { exact: true })).toBeVisible();
    await page.getByLabel('Workspace activo').selectOption(WORKSPACE_B_ID);
    await expect(page.getByLabel('Workspace activo')).toHaveValue(WORKSPACE_B_ID);
    await expect(page.getByText('E2E Flow B', { exact: true })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('E2E Flow A');
  });

  test('public Webchat creates a conversation in its tenant and broadcasts only there', async ({ browser, request }) => {
    const operatorAContext = await browser.newContext();
    const operatorBContext = await browser.newContext();
    const operatorA = await operatorAContext.newPage();
    const operatorB = await operatorBContext.newPage();

    await loginSPA(operatorA, USERS.adminA.email, USERS.adminA.password);
    await loginSPA(operatorB, USERS.adminB.email, USERS.adminB.password);
    await operatorA.goto(`${BASE_URL}/conversations`);
    await operatorB.goto(`${BASE_URL}/conversations`);
    await expect(operatorA.getByText('E2E Contact A')).toBeVisible();
    await expect(operatorB.getByText('E2E Contact B')).toBeVisible();

    const visitor = await startPublicConversation(request, CHANNEL_A_KEY);

    // The inbox receives ConversationCreated without a page refresh.
    await expect(operatorA.getByText(visitor.displayName, { exact: true })).toBeVisible({ timeout: 15000 });
    await expect(operatorB.getByText(visitor.displayName, { exact: true })).toHaveCount(0);
    await operatorA.getByText(visitor.displayName, { exact: true }).click();

    const inboundResponse = await request.post(`${BASE_URL}/api/v1/public/webchat/messages`, {
      headers: visitor.visitorHeaders,
      data: {
        content: 'Mensaje aislado del workspace A',
        client_message_id: randomUUID(),
      },
    });
    expect(inboundResponse.status()).toBe(201);

    // The message must be visible to the correct operator and absent from the
    // other tenant, proving the public ingress path carries workspace_id.
    await expect(operatorA.getByText('Mensaje aislado del workspace A', { exact: true })).toBeVisible({ timeout: 15000 });
    await expect(operatorB.locator('body')).not.toContainText('Mensaje aislado del workspace A');

    await operatorAContext.close();
    await operatorBContext.close();
  });
});
