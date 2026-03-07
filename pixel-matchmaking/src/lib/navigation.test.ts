import { describe, it, expect } from 'vitest';
import { NAV_ITEMS, buildTopNavLinks } from './navigation';

describe('NAV_ITEMS', () => {
  it('has 5 navigation items', () => {
    expect(NAV_ITEMS).toHaveLength(5);
  });

  it('has correct labels', () => {
    const labels = NAV_ITEMS.map((item) => item.label);
    expect(labels).toEqual(['Accueil', 'Jouer', 'Classement', 'Profil', 'Admin']);
  });

  it('has correct paths', () => {
    const paths = NAV_ITEMS.map((item) => item.href);
    expect(paths).toEqual(['/', '/play', '/leaderboard', '/me', '/admin']);
  });

  it('each item has label and href properties', () => {
    for (const item of NAV_ITEMS) {
      expect(item).toHaveProperty('label');
      expect(item).toHaveProperty('href');
      expect(typeof item.label).toBe('string');
      expect(typeof item.href).toBe('string');
    }
  });
});

describe('buildTopNavLinks', () => {
  it('marks the home link as active for pathname /', () => {
    const links = buildTopNavLinks('/');
    const activeLinks = links.filter((l) => l.active);
    expect(activeLinks).toHaveLength(1);
    expect(activeLinks[0].label).toBe('Accueil');
  });

  it('marks the play link as active for pathname /play', () => {
    const links = buildTopNavLinks('/play');
    const activeLinks = links.filter((l) => l.active);
    expect(activeLinks).toHaveLength(1);
    expect(activeLinks[0].label).toBe('Jouer');
  });

  it('marks no links as active for unknown pathname', () => {
    const links = buildTopNavLinks('/unknown');
    const activeLinks = links.filter((l) => l.active);
    expect(activeLinks).toHaveLength(0);
  });

  it('returns all 5 links with href set', () => {
    const links = buildTopNavLinks('/');
    expect(links).toHaveLength(5);
    for (const link of links) {
      expect(link.href).toBeDefined();
    }
  });
});
