import { test, expect } from '@playwright/test';

test.describe('Home page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('shows the main heading', async ({ page }) => {
    await expect(page.getByRole('heading', { name: 'Pixel MatchMaking' })).toBeVisible();
  });

  test('shows the Jouer (play) button', async ({ page }) => {
    await expect(page.getByRole('button', { name: 'Jouer' })).toBeVisible();
  });

  test('shows the leaderboard button', async ({ page }) => {
    await expect(page.getByRole('button', { name: 'Voir le classement' })).toBeVisible();
  });

  test('Jouer button navigates toward /play (redirect to signin if unauthenticated)', async ({ page }) => {
    await page.getByRole('button', { name: 'Jouer' }).click();
    // Unauthenticated: redirects to signin with callbackUrl
    await expect(page).toHaveURL(/\/(play|auth\/signin)/);
  });

  test('Voir le classement button navigates to /leaderboard', async ({ page }) => {
    await page.getByRole('button', { name: 'Voir le classement' }).click();
    await expect(page).toHaveURL(/\/leaderboard/);
  });
});
