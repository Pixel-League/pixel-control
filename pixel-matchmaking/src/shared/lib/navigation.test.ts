import { describe, it, expect } from 'vitest';
import { NAV_ITEMS, DEV_NAV_ITEMS } from './navigation';

describe('NAV_ITEMS', () => {
  it('has 1 production navigation item', () => {
    expect(NAV_ITEMS).toHaveLength(1);
  });

  it('contains only the play/home link', () => {
    expect(NAV_ITEMS[0].translationKey).toBe('play');
    expect(NAV_ITEMS[0].href).toBe('/');
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

describe('DEV_NAV_ITEMS', () => {
  it('has 3 developer navigation items', () => {
    expect(DEV_NAV_ITEMS).toHaveLength(3);
  });

  it('has correct translation keys', () => {
    const keys = DEV_NAV_ITEMS.map((item) => item.translationKey);
    expect(keys).toEqual(['leaderboard', 'profile', 'admin']);
  });

  it('has correct paths', () => {
    const paths = DEV_NAV_ITEMS.map((item) => item.href);
    expect(paths).toEqual(['/leaderboard', '/me', '/admin']);
  });

  it('each item has translationKey and href properties', () => {
    for (const item of DEV_NAV_ITEMS) {
      expect(item).toHaveProperty('translationKey');
      expect(item).toHaveProperty('href');
      expect(typeof item.translationKey).toBe('string');
      expect(typeof item.href).toBe('string');
    }
  });
});
