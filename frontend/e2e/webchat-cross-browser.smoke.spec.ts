import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';

const DEMO_URL = 'http://localhost:3000/examples/webchat-demo.html';

async function openChatWidget(page: Page) {
  await page.waitForSelector('#conexa-widget-container', { state: 'attached', timeout: 20000 });
  await page.locator('.conexa-launcher').click();
  await page.waitForSelector('#conexa-chat-iframe', { state: 'visible', timeout: 20000 });
  return page.frameLocator('#conexa-chat-iframe');
}

test.describe('Cross-Browser Smoke: Webchat Critical Lifecycle', () => {
  test.beforeEach(async () => {
    execSync('docker compose exec -T backend php artisan test:setup-e2e', { stdio: 'pipe' });
  });

  test('load embed, open launcher, receive bot message, send response, and reload history', async ({ page }) => {
    // 1. Load embed demo page
    await page.goto(DEMO_URL);
    await page.waitForSelector('#conexa-widget-container', { state: 'attached', timeout: 20000 });

    // 2. Open chat widget
    const chatFrame = await openChatWidget(page);

    // 3. Receive initial bot welcome message
    await expect(chatFrame.locator('text=Bienvenido al asistente virtual')).toBeVisible({ timeout: 15000 });
    await expect(chatFrame.locator('text=¿Cuál es tu nombre?')).toBeVisible({ timeout: 10000 });

    // 4. Send visitor message
    const inputArea = chatFrame.locator('textarea');
    await expect(inputArea).toBeVisible();
    await inputArea.fill('Carlos CrossBrowser');
    await chatFrame.locator('button[type="submit"]').click();

    // Verify response from bot
    await expect(chatFrame.locator('text=Mucho gusto, Carlos CrossBrowser.')).toBeVisible({ timeout: 10000 });

    // 5. Reload page and re-open widget to verify resumed session & history
    await page.reload();
    const reopenedFrame = await openChatWidget(page);

    // Verify history preserved on resume
    await expect(reopenedFrame.locator('text=Mucho gusto, Carlos CrossBrowser.')).toBeVisible({ timeout: 15000 });
  });
});
