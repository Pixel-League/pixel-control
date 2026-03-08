import { test, expect } from '@playwright/test';

test.describe('Play page', () => {
  test('redirects unauthenticated user to signin', async ({ page }) => {
    await page.goto('/play');
    await expect(page).toHaveURL(/\/auth\/signin/);
    await expect(page.getByText('Se connecter avec ManiaPlanet')).toBeVisible();
  });

  test('signin page has callbackUrl pointing to /play', async ({ page }) => {
    await page.goto('/play');
    await expect(page).toHaveURL(/callbackUrl/);
  });
});
