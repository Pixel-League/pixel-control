import { describe, it, expect } from 'vitest';
import { locales, defaultLocale } from './config';
import frMessages from '../../messages/fr.json';
import enMessages from '../../messages/en.json';

describe('i18n config', () => {
  it('has fr and en locales', () => {
    expect(locales).toEqual(['fr', 'en']);
  });

  it('defaults to French', () => {
    expect(defaultLocale).toBe('fr');
  });
});

describe('translation files', () => {
  it('FR and EN have the same top-level keys', () => {
    const frKeys = Object.keys(frMessages).sort();
    const enKeys = Object.keys(enMessages).sort();
    expect(frKeys).toEqual(enKeys);
  });

  it('all FR nested keys exist in EN', () => {
    const missingKeys = findMissingKeys(frMessages, enMessages);
    expect(missingKeys).toEqual([]);
  });

  it('all EN nested keys exist in FR', () => {
    const missingKeys = findMissingKeys(enMessages, frMessages);
    expect(missingKeys).toEqual([]);
  });

  it('FR messages contain correct accents', () => {
    expect(frMessages.home.subtitle).toContain('compétitif');
    expect(frMessages.play.subtitle).toContain('compétitive');
    expect(frMessages.play.customLobby.description).toContain('Créez');
    expect(frMessages.profile.subtitle).toContain('Gérez');
    expect(frMessages.profile.stats.description).toContain('défaites');
    expect(frMessages.profile.settings.title).toBe('Paramètres');
    expect(frMessages.leaderboard.general.badge).toBe('Bientôt');
    expect(frMessages.common.logout).toBe('Déconnexion');
  });
});

/**
 * Recursively find keys present in source but missing in target.
 * Returns dotted key paths like "home.title".
 */
function findMissingKeys(
  source: Record<string, unknown>,
  target: Record<string, unknown>,
  prefix = '',
): string[] {
  const missing: string[] = [];
  for (const key of Object.keys(source)) {
    const fullKey = prefix ? `${prefix}.${key}` : key;
    if (!(key in target)) {
      missing.push(fullKey);
    } else if (
      typeof source[key] === 'object' &&
      source[key] !== null &&
      typeof target[key] === 'object' &&
      target[key] !== null
    ) {
      missing.push(
        ...findMissingKeys(
          source[key] as Record<string, unknown>,
          target[key] as Record<string, unknown>,
          fullKey,
        ),
      );
    }
  }
  return missing;
}
