import {
  forwardRef,
  useEffect,
  useRef,
  useState,
  type ReactNode,
  type MouseEvent as ReactMouseEvent,
} from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export type ModalSize = 'sm' | 'md' | 'lg';

export interface ModalProps {
  open: boolean;
  onClose: () => void;
  title?: string;
  children?: ReactNode;
  footer?: ReactNode;
  size?: ModalSize;
  closeOnOverlayClick?: boolean;
  theme?: Theme;
  className?: string;
}

const sizeClasses: Record<ModalSize, string> = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
};

const MODAL_ANIMATION_MS = 220;

export const Modal = forwardRef<HTMLDivElement, ModalProps>(function Modal(
  {
    open,
    onClose,
    title,
    children,
    footer,
    size = 'md',
    closeOnOverlayClick = true,
    theme: themeProp,
    className,
  },
  ref,
) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';
  const dialogRef = useRef<HTMLDivElement | null>(null);
  const closeTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const rafRef = useRef<number | null>(null);
  const [isMounted, setIsMounted] = useState(open);
  const [isVisible, setIsVisible] = useState(open);

  useEffect(() => {
    if (closeTimerRef.current) {
      clearTimeout(closeTimerRef.current);
      closeTimerRef.current = null;
    }
    if (rafRef.current !== null) {
      cancelAnimationFrame(rafRef.current);
      rafRef.current = null;
    }

    if (open) {
      setIsMounted(true);
      rafRef.current = requestAnimationFrame(() => {
        setIsVisible(true);
      });
      return;
    }

    setIsVisible(false);
    closeTimerRef.current = setTimeout(() => {
      setIsMounted(false);
      closeTimerRef.current = null;
    }, MODAL_ANIMATION_MS);
  }, [open]);

  useEffect(() => {
    return () => {
      if (closeTimerRef.current) {
        clearTimeout(closeTimerRef.current);
      }
      if (rafRef.current !== null) {
        cancelAnimationFrame(rafRef.current);
      }
    };
  }, []);

  // Trap escape key
  useEffect(() => {
    if (!open) return;

    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        onClose();
      }
    }

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [open, onClose]);

  // Lock body scroll
  useEffect(() => {
    if (!open) return;
    const original = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = original;
    };
  }, [open]);

  // Focus trap on mount
  useEffect(() => {
    if (open) {
      dialogRef.current?.focus();
    }
  }, [open]);

  if (!isMounted) return null;

  function handleOverlayClick(e: ReactMouseEvent) {
    if (closeOnOverlayClick && e.target === e.currentTarget) {
      onClose();
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center"
      onClick={handleOverlayClick}
      aria-modal="true"
      role="dialog"
      aria-hidden={open ? undefined : true}
    >
      {/* Backdrop */}
      <div
        className={cn(
          'absolute inset-0 bg-black/60 transition-opacity duration-200 ease-out',
          isVisible ? 'opacity-100' : 'opacity-0',
        )}
      />

      {/* Panel */}
      <div
        ref={(el) => {
          dialogRef.current = el;
          if (typeof ref === 'function') ref(el);
          else if (ref) (ref as React.MutableRefObject<HTMLDivElement | null>).current = el;
        }}
        tabIndex={-1}
        className={cn(
          'relative w-full mx-4',
          'transition-[opacity,transform] duration-200 ease-out will-change-[opacity,transform]',
          isVisible ? 'opacity-100 translate-y-0 scale-100' : 'opacity-0 translate-y-2 scale-[0.98]',
          sizeClasses[size],
          isDark
            ? 'bg-nm-dark shadow-nm-raised-d border border-white/[0.08]'
            : 'bg-nm-light shadow-nm-raised-l border border-black/[0.08]',
          className,
        )}
      >
        {/* Header */}
        {title ? (
          <div className="flex items-center justify-between px-6 py-4 border-b border-white/[0.08]">
            <h2
              className={cn(
                'font-display text-xl font-bold uppercase tracking-display',
                isDark ? 'text-px-white' : 'text-px-offblack',
              )}
            >
              {title}
            </h2>
            <button
              type="button"
              onClick={onClose}
              aria-label="Close"
              className={cn(
                'text-px-label transition-colors',
                isDark ? 'hover:text-px-white' : 'hover:text-px-offblack',
              )}
            >
              ×
            </button>
          </div>
        ) : null}

        {/* Body */}
        <div className="px-6 py-4">{children}</div>

        {/* Footer */}
        {footer ? (
          <div className={cn(
            'flex items-center justify-end gap-3 px-6 py-4 border-t',
            isDark ? 'border-white/[0.08]' : 'border-black/[0.08]',
          )}>
            {footer}
          </div>
        ) : null}
      </div>
    </div>
  );
});
