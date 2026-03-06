import {
  useState,
  useRef,
  useEffect,
  type ReactNode,
} from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export interface DropdownMenuItem {
  label: string;
  onClick?: () => void;
  disabled?: boolean;
  danger?: boolean;
  divider?: boolean;
}

export interface DropdownMenuProps {
  trigger: ReactNode;
  items: DropdownMenuItem[];
  align?: 'left' | 'right';
  theme?: Theme;
  className?: string;
}

export function DropdownMenu({
  trigger,
  items,
  align = 'left',
  theme: themeProp,
  className,
}: DropdownMenuProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  // Close on click outside
  useEffect(() => {
    if (!open) return;

    function handleClick(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }

    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [open]);

  // Close on Escape
  useEffect(() => {
    if (!open) return;

    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setOpen(false);
    }

    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  }, [open]);

  return (
    <div ref={containerRef} className={cn('relative inline-block', className)}>
      <div
        onClick={() => setOpen((prev) => !prev)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            setOpen((prev) => !prev);
          }
        }}
        role="button"
        tabIndex={0}
        aria-haspopup="true"
        aria-expanded={open}
      >
        {trigger}
      </div>

      {open ? (
        <div
          role="menu"
          className={cn(
            'absolute z-50 mt-1 min-w-[180px] py-1',
            align === 'right' ? 'right-0' : 'left-0',
            isDark
              ? 'bg-nm-dark shadow-nm-raised-d border border-white/[0.08]'
              : 'bg-nm-light shadow-nm-raised-l border border-black/[0.08]',
          )}
        >
          {items.map((item, i) => {
            if (item.divider) {
              return (
                <div
                  key={`divider-${i}`}
                  className={cn(
                    'h-px my-1',
                    isDark ? 'bg-white/[0.08]' : 'bg-black/[0.08]',
                  )}
                />
              );
            }

            return (
              <button
                key={item.label}
                type="button"
                role="menuitem"
                disabled={item.disabled}
                onClick={() => {
                  item.onClick?.();
                  setOpen(false);
                }}
                className={cn(
                  'w-full text-left px-4 py-2 font-body text-sm transition-colors',
                  item.danger
                    ? 'text-px-error hover:bg-px-error/10'
                    : isDark
                      ? 'text-px-white hover:bg-nm-dark-s'
                      : 'text-px-offblack hover:bg-nm-light-s',
                  item.disabled && 'opacity-50 cursor-not-allowed pointer-events-none',
                )}
              >
                {item.label}
              </button>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}
