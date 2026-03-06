import { forwardRef, useEffect, useRef, useState, type ReactNode } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export type ToastVariant = 'info' | 'success' | 'warning' | 'error';

export interface ToastProps {
  variant?: ToastVariant;
  title: string;
  description?: string;
  onDismiss?: () => void;
  icon?: ReactNode;
  theme?: Theme;
  className?: string;
}

const accentColors: Record<ToastVariant, string> = {
  info: 'bg-px-primary',
  success: 'bg-px-success',
  warning: 'bg-px-warning',
  error: 'bg-px-error',
};

const TOAST_DISMISS_ANIMATION_MS = 180;

export const Toast = forwardRef<HTMLDivElement, ToastProps>(function Toast(
  {
    variant = 'info',
    title,
    description,
    onDismiss,
    icon,
    theme: themeProp,
    className,
  },
  ref,
) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';
  const [isDismissing, setIsDismissing] = useState(false);
  const dismissTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    return () => {
      if (dismissTimerRef.current) {
        clearTimeout(dismissTimerRef.current);
      }
    };
  }, []);

  function handleDismiss() {
    if (!onDismiss || isDismissing) return;
    setIsDismissing(true);
    dismissTimerRef.current = setTimeout(() => {
      onDismiss();
      setIsDismissing(false);
      dismissTimerRef.current = null;
    }, TOAST_DISMISS_ANIMATION_MS);
  }

  return (
    <div
      ref={ref}
      role="status"
      aria-live="polite"
      className={cn(
        'px-5 py-4 flex items-center gap-3',
        'animate-fade-slide-up transition-[opacity,transform] duration-200 ease-out will-change-[opacity,transform]',
        isDismissing ? 'opacity-0 translate-y-1 scale-[0.98]' : 'opacity-100 translate-y-0 scale-100',
        isDark
          ? 'bg-nm-dark shadow-nm-raised-d border border-white/[0.08]'
          : 'bg-nm-light shadow-nm-raised-l border border-black/[0.08]',
        className,
      )}
    >
      {/* Accent bar */}
      <div className={cn('w-1 h-10 shrink-0', accentColors[variant])} />

      {icon ? <span className="shrink-0">{icon}</span> : null}

      <div className="flex-1 min-w-0">
        <p
          className={cn(
            'font-body text-sm font-semibold',
            isDark ? 'text-px-white' : 'text-px-offblack',
          )}
        >
          {title}
        </p>
        {description ? (
          <p className="font-body text-xs text-px-label">{description}</p>
        ) : null}
      </div>

      {onDismiss ? (
        <button
          type="button"
          aria-label="Dismiss"
          onClick={handleDismiss}
          disabled={isDismissing}
          className={cn(
            'transition-colors text-px-label shrink-0',
            isDismissing && 'opacity-60 cursor-wait',
            isDark ? 'hover:text-px-white' : 'hover:text-px-offblack',
          )}
        >
          ×
        </button>
      ) : null}
    </div>
  );
});
