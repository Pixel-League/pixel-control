import type { Config } from 'tailwindcss';
import rawTailwindConfig from '../../tailwind.config';

export interface PaletteItem {
  name: string;
  value: string;
}

type ThemeWithColors = {
  colors?: Record<string, unknown>;
  extend?: {
    colors?: Record<string, unknown>;
  };
};

type ConfigWithTheme = Config & {
  theme?: ThemeWithColors;
};

function flattenColors(input: Record<string, unknown>, parentKey = ''): PaletteItem[] {
  const entries: PaletteItem[] = [];

  for (const [key, value] of Object.entries(input)) {
    const normalizedKey = key === 'DEFAULT' ? parentKey : [parentKey, key].filter(Boolean).join('-');

    if (typeof value === 'string') {
      entries.push({ name: normalizedKey, value });
      continue;
    }

    if (value && typeof value === 'object' && !Array.isArray(value)) {
      entries.push(...flattenColors(value as Record<string, unknown>, normalizedKey));
    }
  }

  return entries;
}

function getConfiguredColorMap(config: ConfigWithTheme): Record<string, unknown> {
  return {
    ...(config.theme?.colors ?? {}),
    ...(config.theme?.extend?.colors ?? {}),
  };
}

export function getTailwindPalette(): PaletteItem[] {
  const configuredColors = getConfiguredColorMap(rawTailwindConfig as ConfigWithTheme);
  return flattenColors(configuredColors).sort((a, b) => a.name.localeCompare(b.name));
}
