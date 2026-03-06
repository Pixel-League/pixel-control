import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export interface BreadcrumbItem {
  label: string;
  href?: string;
}

export interface BreadcrumbProps {
  items: BreadcrumbItem[];
  separator?: string;
  theme?: Theme;
  className?: string;
}

export function Breadcrumb({
  items,
  separator = '/',
  theme: themeProp,
  className,
}: BreadcrumbProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';

  return (
    <nav aria-label="Breadcrumb" className={className}>
      <ol className="flex items-center gap-2 font-body text-sm">
        {items.map((item, index) => {
          const isLast = index === items.length - 1;

          return (
            <li key={`${item.label}-${index}`} className="inline-flex items-center gap-2">
              {isLast ? (
                <span
                  aria-current="page"
                  className={cn('font-semibold', isDark ? 'text-px-white' : 'text-px-offblack')}
                >
                  {item.label}
                </span>
              ) : item.href ? (
                <a href={item.href} className="text-px-label hover:text-px-primary transition-colors">
                  {item.label}
                </a>
              ) : (
                <span className="text-px-label">{item.label}</span>
              )}

              {!isLast ? (
                <span className="text-px-label/50" aria-hidden="true">
                  {separator}
                </span>
              ) : null}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
