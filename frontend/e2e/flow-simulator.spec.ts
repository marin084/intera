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

test.describe('Flow Simulator V1 - End-to-End Execution', () => {
  test.beforeEach(async () => {
    execSync('docker compose exec -T backend php artisan test:setup-e2e', { stdio: 'pipe' });
  });

  test('open flow editor, launch simulator, interact with flow, inspect state & reset', async ({ page }) => {
    // 1. Log in as Super Admin
    await loginSPA(page, 'admin@conexa.test', 'TestAdminPass@2026');

    // 2. Navigate to Flows view
    await page.click('button:has-text("Flujos")');
    await page.waitForSelector('text=Webchat E2E Flow', { timeout: 15000 });

    // 3. Open Flow in Editor
    await page.locator('tr', { hasText: 'Webchat E2E Flow' }).locator('button', { hasText: /flujo/i }).click();
    await page.waitForSelector('[data-testid="open-simulator-btn"]', { timeout: 15000 });

    // 4. Open Simulator Drawer
    await page.click('[data-testid="open-simulator-btn"]');
    const simulator = page.locator('.flow-simulator-drawer');
    await expect(simulator).toBeVisible({ timeout: 15000 });
    await expect(simulator.locator('text=Simulador de Flujo')).toBeVisible({ timeout: 15000 });

    // 5. Verify initial bot messages in the simulator chat
    await expect(simulator.locator('text=¡Hola! Bienvenido al asistente virtual de Conexa.')).toBeVisible({ timeout: 10000 });
    await expect(simulator.locator('text=¿Cuál es tu nombre?')).toBeVisible({ timeout: 10000 });

    // 6. Respond to question (input_type: text, variable: visitor_name)
    const chatInput = simulator.locator('input[type="text"]');
    await expect(chatInput).toBeVisible();
    await chatInput.fill('Mariin Simulador');
    await simulator.locator('button[type="submit"]').click();

    // 7. Verify bot response with variable interpolation and choice buttons
    await expect(simulator.locator('text=Mucho gusto, Mariin Simulador.')).toBeVisible({ timeout: 15000 });
    await expect(simulator.locator('text=¿Deseas hablar con un agente humano?')).toBeVisible({ timeout: 10000 });
    await expect(simulator.locator('button:has-text("Sí quiero agente")')).toBeVisible({ timeout: 10000 });

    // 8. Click interactive choice button: "Sí quiero agente"
    await simulator.locator('button:has-text("Sí quiero agente")').click();

    // 9. Verify transfer path executed: notice & simulated transfer banner
    await expect(simulator.locator('text=Perfecto, te transferimos de inmediato.')).toBeVisible({ timeout: 15000 });
    await expect(simulator.locator('text=Transferencia Simulada a Agente')).toBeVisible({ timeout: 10000 });
    await expect(simulator.locator('text=El bot transferiría esta conversación a un agente.')).toBeVisible({ timeout: 10000 });

    // 10. Switch to Inspector tab and verify variables & node info
    await simulator.locator('button:has-text("Inspector")').click();
    await expect(simulator.locator('text=A. Nodo Actual en Ejecución')).toBeVisible({ timeout: 5000 });
    await expect(simulator.locator('text=B. Variables en Memoria')).toBeVisible({ timeout: 5000 });
    await expect(simulator.locator('text={{visitor_name}}')).toBeVisible({ timeout: 5000 });
    await expect(simulator.locator('text=Mariin Simulador')).toBeVisible({ timeout: 5000 });
    await expect(simulator.locator('text={{want_agent}}')).toBeVisible({ timeout: 5000 });

    // 11. Switch to Events tab and verify chronological timeline
    await simulator.locator('button:has-text("Eventos")').click();
    await expect(simulator.locator('text=Simulación iniciada')).toBeVisible({ timeout: 5000 });
    await expect(simulator.locator('text=Nodo inicial ejecutado: Inicio')).toBeVisible({ timeout: 5000 });

    // 12. Reset simulation
    await simulator.locator('button:has-text("Reiniciar")').click();
    await simulator.locator('button:has-text("Conversación")').click();
    await expect(simulator.locator('text=¡Hola! Bienvenido al asistente virtual de Conexa.')).toBeVisible({ timeout: 15000 });

    // 13. Close simulator drawer
    await simulator.locator('button[title="Cerrar simulador"]').click();
    await expect(simulator).not.toBeVisible({ timeout: 5000 });
  });
});
