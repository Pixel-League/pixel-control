import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export type DividerOrientation = 'horizontal' | 'vertical';

export interface DividerProps {
  orientation?: DividerOrientation;
  label?: string;
  theme?: Theme;
  className?: string;
}

export function Divider({
  orientation = 'horizontal',
  label,
  theme: themeProp,
  className,
}: DividerProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';

  const lineClass = isDark ? 'bg-white/[0.08]' : 'bg-black/[0.08]';

  if (orientation === 'vertical') {
    return (
      <div
        role="separator"
        aria-orientation="vertical"
        className={cn('inline-block w-px self-stretch', lineClass, className)}
      />
    );
  }

  if (label) {
    return (
      <div
        role="separator"
        className={cn('flex items-center gap-4', className)}
      >
        <div className={cn('flex-1 h-px', lineClass)} />
        <span className="font-body text-xs text-px-label uppercase tracking-wide-body shrink-0">
          {label}
        </span>
        <div className={cn('flex-1 h-px', lineClass)} />
      </div>
    );
  }

  return (
    <hr
      className={cn('border-0 h-px', lineClass, className)}
    />
  );
}
