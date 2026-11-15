import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';

const BASE_URL = 'http://localhost:3000';

async function loginSPA(page: Page, email: string, pass: string) {
  await page.goto(`${BASE_URL}/login`);
  await page.waitForSelector('input[type="email"]');
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', pass);
  await page.click('button[type="submit"]');
  await page.waitForSelector('text=Bandeja de Entrada', { timeout: 15000 });
}

test.describe('Flow Publication, Versioning & Rollback - End-to-End', () => {
  test.beforeEach(async () => {
    try {
      execSync('docker compose exec -T backend php artisan test:setup-e2e', { stdio: 'pipe' });
    } catch {
      // ignore if docker environment is not running
    }
  });

  test('full lifecycle: inspect toolbar, history drawer, comparison modal and simulator', async ({ page }) => {
    // Handle dialog prompts (confirmations) automatically
    page.on('dialog', async (dialog) => {
      await dialog.accept();
    });

    // 1. Log in as Super Admin
    await loginSPA(page, 'admin@conexa.test', 'TestAdminPass@2026');

    // 2. Navigate to Flows view
    await page.click('button:has-text("Flujos")');
    await page.waitForSelector('text=Webchat E2E Flow', { timeout: 15000 });

    // 3. Open Flow in Editor
    await page.locator('tr', { hasText: 'Webchat E2E Flow' }).locator('button', { hasText: /flujo/i }).click();
    await page.waitForSelector('.flow-toolbar', { timeout: 15000 });

    // 4. Verify version status info in toolbar
    await expect(page.locator('.flow-toolbar')).toBeVisible();
    await expect(page.locator('text=Historial')).toBeVisible();
    await expect(page.locator('text=Comparar')).toBeVisible();

    // 5. Open Version History Drawer
    await page.locator('button', { hasText: 'Historial' }).click();
    await expect(page.locator('text=Historial de Versiones')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('text=Producción Activa')).toBeVisible({ timeout: 10000 });

    // 6. Switch to Audit tab in history drawer
    const auditTabBtn = page.locator('button', { hasText: /Auditoría/i });
    if (await auditTabBtn.isVisible()) {
      await auditTabBtn.click();
    }

    // 7. Switch back to Versiones tab
    const versionsTabBtn = page.locator('button', { hasText: /Versiones/i });
    if (await versionsTabBtn.isVisible()) {
      await versionsTabBtn.click();
    }

    // 8. Close History Drawer
    await page.locator('button', { hasText: 'Cerrar Historial' }).click();
    await expect(page.locator('text=Historial de Versiones')).not.toBeVisible({ timeout: 5000 });

    // 9. Open Semantic Compare Modal
    await page.locator('button', { hasText: 'Comparar' }).click();
    await expect(page.locator('text=Comparador Semántico de Versiones')).toBeVisible({ timeout: 10000 });

    // 10. Close Compare Modal
    await page.locator('button', { hasText: 'Cerrar Comparación' }).click();
    await expect(page.locator('text=Comparador Semántico de Versiones')).not.toBeVisible({ timeout: 5000 });

    // 11. Verify simulator integration has no regressions
    await page.click('[data-testid="open-simulator-btn"]');
    const simulator = page.locator('.flow-simulator-drawer');
    await expect(simulator).toBeVisible({ timeout: 15000 });
    await expect(simulator.locator('text=Simulador de Flujo')).toBeVisible({ timeout: 15000 });

    // Close simulator drawer
    await simulator.locator('button[title="Cerrar simulador"]').click();
    await expect(simulator).not.toBeVisible({ timeout: 5000 });
  });
});
