import type { ReactNode } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export type BadgeVariant = 'primary' | 'success' | 'error' | 'warning' | 'neutral' | 'inactive';

export interface BadgeProps {
  variant?: BadgeVariant;
  children: ReactNode;
  theme?: Theme;
  className?: string;
}

const variantClasses: Record<Theme, Record<BadgeVariant, string>> = {
  dark: {
    primary: 'bg-px-primary text-px-white shadow-nm-btn-d border border-white/[0.08]',
    success: 'bg-px-success text-px-dark shadow-nm-btn-d border border-white/[0.08]',
    error: 'bg-px-error text-px-white shadow-nm-btn-d border border-white/[0.08]',
    warning: 'bg-px-warning text-px-dark shadow-nm-btn-d border border-white/[0.08]',
    neutral: 'bg-nm-dark text-px-white shadow-nm-raised-d border border-white/[0.08]',
    inactive: 'bg-nm-dark-s text-px-label shadow-nm-flat-d border border-white/[0.08]',
  },
  light: {
    primary: 'bg-px-primary text-px-white shadow-nm-btn-l border border-black/[0.08]',
    success: 'bg-px-success text-px-white shadow-nm-btn-l border border-black/[0.08]',
    error: 'bg-px-error text-px-white shadow-nm-btn-l border border-black/[0.08]',
    warning: 'bg-px-warning text-px-dark shadow-nm-btn-l border border-black/[0.08]',
    neutral: 'bg-nm-light text-px-offblack shadow-nm-raised-l border border-black/[0.08]',
    inactive: 'bg-nm-light-s text-px-label shadow-nm-flat-l border border-black/[0.08]',
  },
};

export function Badge({ variant = 'primary', children, theme: themeProp, className }: BadgeProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;

  return (
    <span
      className={cn(
        'inline-flex items-center font-body text-xs font-semibold px-3 py-1 uppercase tracking-wide-body',
        variantClasses[theme][variant],
        className,
      )}
    >
      {children}
    </span>
  );
}
