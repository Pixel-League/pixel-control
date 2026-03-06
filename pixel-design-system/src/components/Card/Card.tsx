import type { ReactNode } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export type CardTone = 'primary' | 'dark' | 'surface';

export interface CardProps {
  badge?: ReactNode;
  title: string;
  description?: string;
  metadata?: ReactNode;
  tone?: CardTone;
  muted?: boolean;
  theme?: Theme;
  className?: string;
  children?: ReactNode;
}

function getHeroClass(theme: Theme, tone: CardTone): string {
  if (theme === 'dark') {
    if (tone === 'dark') {
      return 'bg-gradient-to-br from-px-primary-dark/40 to-nm-dark';
    }

    if (tone === 'surface') {
      return 'bg-nm-dark-s';
    }

    return 'bg-gradient-to-br from-px-primary/30 to-nm-dark';
  }

  if (tone === 'dark') {
    return 'bg-px-offblack';
  }

  if (tone === 'surface') {
    return 'bg-nm-light-s';
  }

  return 'bg-px-primary';
}

function getPatternStyle(theme: Theme) {
  return {
    background: `repeating-linear-gradient(-45deg, ${
      theme === 'dark' ? 'rgba(44,18,217,0.15)' : 'rgba(255,255,255,0.08)'
    } 0px, ${theme === 'dark' ? 'rgba(44,18,217,0.15)' : 'rgba(255,255,255,0.08)'} 2px, transparent 2px, transparent 12px)`,
  };
}

export function Card({
  badge,
  title,
  description,
  metadata,
  tone = 'primary',
  muted,
  theme: themeProp,
  className,
  children,
}: CardProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';

  return (
    <article
      className={cn(
        'overflow-hidden',
        isDark
          ? 'bg-nm-dark border border-white/[0.08] shadow-nm-raised-d'
          : 'bg-nm-light border border-black/[0.08] shadow-nm-raised-l',
        muted && (isDark ? 'shadow-nm-flat-d opacity-60' : 'shadow-nm-flat-l opacity-60'),
        className,
      )}
    >
      <div className={cn('h-32 relative overflow-hidden', getHeroClass(theme, tone))}>
        <div className="absolute inset-0" style={getPatternStyle(theme)} />
      </div>

      <div className="p-6 flex flex-col gap-2">
        {badge ? <div>{badge}</div> : null}
        <h3
          className={cn(
            'font-display text-2xl font-bold uppercase tracking-display',
            isDark ? 'text-px-white' : 'text-px-offblack',
          )}
        >
          {title}
        </h3>
        {description ? <p className="font-body text-sm text-px-label">{description}</p> : null}
        {metadata ? <div>{metadata}</div> : null}
        {children}
      </div>
    </article>
  );
}
