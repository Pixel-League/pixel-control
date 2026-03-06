import type { ReactNode } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export type AlertVariant = 'info' | 'success' | 'warning' | 'error';

export interface AlertProps {
  variant?: AlertVariant;
  title?: string;
  children?: ReactNode;
  theme?: Theme;
  className?: string;
}

const variantStyles: Record<AlertVariant, { accent: string; text: string }> = {
  info: { accent: 'border-px-primary', text: 'text-px-primary' },
  success: { accent: 'border-px-success', text: 'text-px-success' },
  warning: { accent: 'border-px-warning', text: 'text-px-warning' },
  error: { accent: 'border-px-error', text: 'text-px-error' },
};

const iconPaths: Record<AlertVariant, string> = {
  info: 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z',
  success:
    'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z',
  warning: 'M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z',
  error:
    'M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z',
};

export function Alert({
  variant = 'info',
  title,
  children,
  theme: themeProp,
  className,
}: AlertProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';
  const state = variantStyles[variant];

  return (
    <div
      role="alert"
      className={cn(
        'flex items-start gap-3 px-6 py-4 border-l-4',
        isDark
          ? 'bg-nm-dark shadow-nm-raised-d border border-white/[0.08]'
          : 'bg-nm-light shadow-nm-raised-l border border-black/[0.08]',
        state.accent,
        className,
      )}
    >
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        className={cn('w-5 h-5 mt-0.5 shrink-0', state.text)}
        fill="currentColor"
      >
        <path d={iconPaths[variant]} />
      </svg>

      <div className="flex min-w-0 flex-col gap-1">
        {title ? <p className={cn('font-body text-sm font-semibold', state.text)}>{title}</p> : null}
        {children ? (
          <div className={cn('font-body text-sm', isDark ? 'text-px-white/80' : 'text-px-offblack/80')}>
            {children}
          </div>
        ) : null}
      </div>
    </div>
  );
}
