import { describe, it, expect } from 'vitest';
import { NAV_ITEMS } from './navigation';

describe('NAV_ITEMS', () => {
  it('has 5 navigation items', () => {
    expect(NAV_ITEMS).toHaveLength(5);
  });

  it('has correct translation keys', () => {
    const keys = NAV_ITEMS.map((item) => item.translationKey);
    expect(keys).toEqual(['home', 'play', 'leaderboard', 'profile', 'admin']);
  });

  it('has correct paths', () => {
    const paths = NAV_ITEMS.map((item) => item.href);
    expect(paths).toEqual(['/', '/play', '/leaderboard', '/me', '/admin']);
  });

  it('each item has translationKey and href properties', () => {
    for (const item of NAV_ITEMS) {
      expect(item).toHaveProperty('translationKey');
      expect(item).toHaveProperty('href');
      expect(typeof item.translationKey).toBe('string');
      expect(typeof item.href).toBe('string');
    }
  });
});
