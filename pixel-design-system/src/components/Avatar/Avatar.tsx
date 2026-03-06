import { useState, type ImgHTMLAttributes } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export type AvatarSize = 'sm' | 'md' | 'lg' | 'xl';

export interface AvatarProps
  extends Omit<ImgHTMLAttributes<HTMLImageElement>, 'size'> {
  size?: AvatarSize;
  initials?: string;
  theme?: Theme;
  className?: string;
}

const sizeClasses: Record<AvatarSize, string> = {
  sm: 'w-8 h-8 text-xs',
  md: 'w-10 h-10 text-sm',
  lg: 'w-14 h-14 text-base',
  xl: 'w-20 h-20 text-lg',
};

export function Avatar({
  size = 'md',
  src,
  alt,
  initials,
  theme: themeProp,
  className,
  ...rest
}: AvatarProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';
  const [imgError, setImgError] = useState(false);

  const showImage = src && !imgError;

  const base = cn(
    'inline-flex items-center justify-center overflow-hidden shrink-0',
    isDark
      ? 'bg-nm-dark-s shadow-nm-raised-d border border-white/[0.08]'
      : 'bg-nm-light-s shadow-nm-raised-l border border-black/[0.08]',
    sizeClasses[size],
    className,
  );

  if (showImage) {
    return (
      <img
        src={src}
        alt={alt ?? ''}
        onError={() => setImgError(true)}
        className={cn(base, 'object-cover')}
        {...rest}
      />
    );
  }

  const displayInitials =
    initials ??
    (alt
      ? alt
          .split(' ')
          .slice(0, 2)
          .map((w) => w[0])
          .join('')
          .toUpperCase()
      : '?');

  return (
    <span className={base} aria-label={alt ?? 'Avatar'}>
      <span
        className={cn(
          'font-display font-bold uppercase tracking-display',
          isDark ? 'text-px-white' : 'text-px-offblack',
        )}
      >
        {displayInitials}
      </span>
    </span>
  );
}
