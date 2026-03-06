import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export type ProgressVariant = 'bar' | 'spinner';

export interface ProgressProps {
  variant?: ProgressVariant;
  value?: number;
  max?: number;
  size?: 'sm' | 'md' | 'lg';
  label?: string;
  theme?: Theme;
  className?: string;
}

const barHeights: Record<string, string> = {
  sm: 'h-1',
  md: 'h-2',
  lg: 'h-3',
};

const spinnerSizes: Record<string, string> = {
  sm: 'w-5 h-5 border-2',
  md: 'w-8 h-8 border-[3px]',
  lg: 'w-12 h-12 border-4',
};

export function Progress({
  variant = 'bar',
  value,
  max = 100,
  size = 'md',
  label,
  theme: themeProp,
  className,
}: ProgressProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';

  const isIndeterminate = value === undefined;
  const percentage = isIndeterminate ? 0 : Math.min(100, Math.max(0, (value / max) * 100));

  if (variant === 'spinner') {
    return (
      <div
        role="status"
        aria-label={label ?? 'Loading'}
        className={cn('inline-flex flex-col items-center gap-2', className)}
      >
        <div
          className={cn(
            'animate-spin rounded-full border-transparent',
            isDark
              ? 'border-t-px-primary border-r-px-primary border-b-nm-dark-s border-l-nm-dark-s'
              : 'border-t-px-primary border-r-px-primary border-b-nm-light-s border-l-nm-light-s',
            spinnerSizes[size],
          )}
        />
        {label ? (
          <span className="font-body text-xs text-px-label uppercase tracking-wide-body">
            {label}
          </span>
        ) : null}
      </div>
    );
  }

  return (
    <div
      role="progressbar"
      aria-valuenow={isIndeterminate ? undefined : percentage}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-label={label ?? 'Progress'}
      className={cn('w-full', className)}
    >
      {label ? (
        <div className="flex items-center justify-between mb-1">
          <span className="font-body text-xs text-px-label uppercase tracking-wide-body">
            {label}
          </span>
          {!isIndeterminate ? (
            <span className="font-body text-xs text-px-label">
              {Math.round(percentage)}%
            </span>
          ) : null}
        </div>
      ) : null}
      <div
        className={cn(
          barHeights[size],
          isDark
            ? 'bg-nm-dark-s shadow-nm-inset-d border border-white/[0.08]'
            : 'bg-nm-light-s shadow-nm-inset-l border border-black/[0.08]',
          'overflow-hidden',
        )}
      >
        <div
          className={cn(
            'h-full bg-px-primary transition-all duration-300',
            isIndeterminate && 'animate-pulse w-full',
          )}
          style={!isIndeterminate ? { width: `${percentage}%` } : undefined}
        />
      </div>
    </div>
  );
}
