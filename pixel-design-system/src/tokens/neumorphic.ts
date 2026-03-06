import type { Theme } from '@/context/ThemeContext';

export type FieldState = 'default' | 'error' | 'success';

export const baseThemeClasses = {
  dark: {
    page: 'bg-nm-dark text-px-white',
    surface: 'bg-nm-dark shadow-nm-raised-d border border-white/[0.08]',
    elevatedSurface: 'bg-nm-dark-s shadow-nm-raised-d border border-white/[0.08]',
    insetSurface: 'bg-nm-dark shadow-nm-inset-d border border-white/[0.08]',
    flatSurface: 'bg-nm-dark-s shadow-nm-flat-d border border-white/[0.08]',
    textStrong: 'text-px-white',
    textMuted: 'text-px-label',
    border: 'border border-white/[0.08]',
  },
  light: {
    page: 'bg-nm-light text-px-offblack',
    surface: 'bg-nm-light shadow-nm-raised-l border border-black/[0.08]',
    elevatedSurface: 'bg-nm-light-s shadow-nm-raised-l border border-black/[0.08]',
    insetSurface: 'bg-nm-light shadow-nm-inset-l border border-black/[0.08]',
    flatSurface: 'bg-nm-light-s shadow-nm-flat-l border border-black/[0.08]',
    textStrong: 'text-px-offblack',
    textMuted: 'text-px-label',
    border: 'border border-black/[0.08]',
  },
} as const;

const focusShadowByTheme: Record<Theme, Record<FieldState, string>> = {
  dark: {
    default:
      'focus-visible:shadow-[inset_5px_5px_10px_#000000,inset_-5px_-5px_10px_#2A2A2A,0_0_0_3px_#2C12D9]',
    error:
      'shadow-[inset_5px_5px_10px_#000000,inset_-5px_-5px_10px_#2A2A2A,0_0_0_3px_#E02020]',
    success:
      'shadow-[inset_5px_5px_10px_#000000,inset_-5px_-5px_10px_#2A2A2A,0_0_0_3px_#00C853]',
  },
  light: {
    default:
      'focus-visible:shadow-[inset_5px_5px_10px_#CDD5E0,inset_-5px_-5px_10px_#FFFFFF,0_0_0_3px_#2C12D9]',
    error:
      'shadow-[inset_5px_5px_10px_#CDD5E0,inset_-5px_-5px_10px_#FFFFFF,0_0_0_3px_#E02020]',
    success:
      'shadow-[inset_5px_5px_10px_#CDD5E0,inset_-5px_-5px_10px_#FFFFFF,0_0_0_3px_#00C853]',
  },
};

export function getFieldTone(theme: Theme) {
  return baseThemeClasses[theme];
}

export function getFieldShadowState(theme: Theme, state: FieldState): string {
  return focusShadowByTheme[theme][state];
}

export function getIconChevron(theme: Theme): string {
  const color = theme === 'dark' ? '%237B7FA0' : '%237B7FA0';
  return `url("data:image/svg+xml,%3Csvg fill='${color}' viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E")`;
}
