import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export type SkeletonVariant = 'text' | 'rectangular' | 'circular';

export interface SkeletonProps {
  variant?: SkeletonVariant;
  width?: string | number;
  height?: string | number;
  lines?: number;
  theme?: Theme;
  className?: string;
}

const variantClasses: Record<SkeletonVariant, string> = {
  text: 'h-4 w-full',
  rectangular: 'w-full h-24',
  circular: 'w-10 h-10 rounded-full',
};

export function Skeleton({
  variant = 'text',
  width,
  height,
  lines = 1,
  theme: themeProp,
  className,
}: SkeletonProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';

  const baseClass = cn(
    'animate-pulse',
    isDark
      ? 'bg-nm-dark-s shadow-nm-flat-d border border-white/[0.08]'
      : 'bg-nm-light-s shadow-nm-flat-l border border-black/[0.08]',
    variant === 'circular' ? 'rounded-full' : '',
    variantClasses[variant],
    className,
  );

  const style: React.CSSProperties = {};
  if (width !== undefined) style.width = typeof width === 'number' ? `${width}px` : width;
  if (height !== undefined) style.height = typeof height === 'number' ? `${height}px` : height;

  if (variant === 'text' && lines > 1) {
    return (
      <div className="space-y-2" role="status" aria-label="Loading">
        {Array.from({ length: lines }, (_, i) => (
          <div
            key={i}
            className={cn(baseClass, i === lines - 1 && 'w-3/4')}
            style={i === lines - 1 ? undefined : style}
          />
        ))}
      </div>
    );
  }

  return (
    <div
      role="status"
      aria-label="Loading"
      className={baseClass}
      style={style}
    />
  );
}
