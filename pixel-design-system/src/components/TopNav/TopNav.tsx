import type { ReactNode } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export interface TopNavLink {
  label: string;
  href?: string;
  active?: boolean;
  onClick?: () => void;
}

export interface TopNavProps {
  brand: ReactNode;
  links?: TopNavLink[];
  actions?: ReactNode;
  theme?: Theme;
  className?: string;
}

export function TopNav({
  brand,
  links = [],
  actions,
  theme: themeProp,
  className,
}: TopNavProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';

  return (
    <nav
      aria-label="Top navigation"
      className={cn(
        'relative flex items-center px-6 py-3',
        isDark
          ? 'bg-nm-dark shadow-nm-raised-d border border-white/[0.08]'
          : 'bg-nm-light shadow-nm-raised-l border border-black/[0.08]',
        className,
      )}
    >
      {/* Brand */}
      <div className="flex items-center gap-3 shrink-0">{brand}</div>

      {/* Links — absolutely centered relative to the full nav width */}
      {links.length > 0 ? (
        <div className="absolute left-1/2 -translate-x-1/2 flex items-center gap-4 font-body text-xs tracking-wide-body uppercase">
          {links.map((link) => (
            <a
              key={link.label}
              href={link.href ?? '#'}
              onClick={link.onClick}
              className={cn(
                'transition-colors',
                link.active
                  ? isDark
                    ? 'text-px-white shadow-nm-inset-d border border-white/[0.08] px-3 py-1'
                    : 'text-px-offblack shadow-nm-inset-l border border-black/[0.08] px-3 py-1'
                  : isDark
                    ? 'text-px-label hover:text-px-white'
                    : 'text-px-label hover:text-px-offblack',
              )}
              aria-current={link.active ? 'page' : undefined}
            >
              {link.label}
            </a>
          ))}
        </div>
      ) : null}

      {/* Actions */}
      {actions ? <div className="flex items-center gap-3 shrink-0 ml-auto">{actions}</div> : null}
    </nav>
  );
}
