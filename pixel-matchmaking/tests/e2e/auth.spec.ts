import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
  test('signin page is accessible and shows ManiaPlanet button', async ({ page }) => {
    await page.goto('/auth/signin');
    await expect(page).toHaveURL(/\/auth\/signin/);
    await expect(page.getByText('Se connecter avec ManiaPlanet')).toBeVisible();
  });

  test('/ is publicly accessible without authentication', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveURL(/\/$/);
    await expect(page).not.toHaveURL(/\/auth\/signin/);
  });

  test('unauthenticated: clicking search button on homepage redirects to signin', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: /rechercher un match/i }).click();
    await expect(page).toHaveURL(/\/auth\/signin/);
  });

  test('/me redirects to signin when unauthenticated', async ({ page }) => {
    await page.goto('/me');
    await expect(page).toHaveURL(/\/auth\/signin/);
  });

  test('/admin redirects to signin when unauthenticated', async ({ page }) => {
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/auth\/signin/);
  });

  test('signin page shows SSO card title', async ({ page }) => {
    await page.goto('/auth/signin');
    await expect(page.getByText('ManiaPlanet SSO')).toBeVisible();
  });
});
