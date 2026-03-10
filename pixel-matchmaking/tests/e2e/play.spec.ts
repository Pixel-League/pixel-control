import { test, expect } from '@playwright/test';

test.describe('Play route (removed)', () => {
  test('/play returns 404 — route no longer exists', async ({ page }) => {
    const response = await page.goto('/play');
    // Next.js returns 404 for unknown routes
    expect(response?.status()).toBe(404);
  });
});
