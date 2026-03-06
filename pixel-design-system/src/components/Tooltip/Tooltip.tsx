import {
  forwardRef,
  useState,
  useRef,
  useEffect,
  type ReactNode,
} from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export type TooltipPosition = 'top' | 'bottom' | 'left' | 'right';

export interface TooltipProps {
  content: ReactNode;
  position?: TooltipPosition;
  delay?: number;
  children: ReactNode;
  theme?: Theme;
  className?: string;
}

const positionClasses: Record<TooltipPosition, string> = {
  top: 'bottom-full left-1/2 -translate-x-1/2 mb-2',
  bottom: 'top-full left-1/2 -translate-x-1/2 mt-2',
  left: 'right-full top-1/2 -translate-y-1/2 mr-2',
  right: 'left-full top-1/2 -translate-y-1/2 ml-2',
};

const motionClasses: Record<TooltipPosition, { enter: string; exit: string }> = {
  top: { enter: 'translate-y-0', exit: 'translate-y-1' },
  bottom: { enter: 'translate-y-0', exit: '-translate-y-1' },
  left: { enter: 'translate-x-0', exit: 'translate-x-1' },
  right: { enter: 'translate-x-0', exit: '-translate-x-1' },
};

const TOOLTIP_ANIMATION_MS = 160;

export const Tooltip = forwardRef<HTMLDivElement, TooltipProps>(function Tooltip(
  {
    content,
    position = 'top',
    delay = 200,
    children,
    theme: themeProp,
    className,
  },
  ref,
) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';
  const [isVisible, setIsVisible] = useState(false);
  const [isRendered, setIsRendered] = useState(false);
  const enterTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const enterVisibilityTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const exitTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  function handleEnter() {
    if (exitTimerRef.current) {
      clearTimeout(exitTimerRef.current);
      exitTimerRef.current = null;
    }
    if (enterTimerRef.current) {
      clearTimeout(enterTimerRef.current);
      enterTimerRef.current = null;
    }
    if (enterVisibilityTimerRef.current) {
      clearTimeout(enterVisibilityTimerRef.current);
      enterVisibilityTimerRef.current = null;
    }

    enterTimerRef.current = setTimeout(() => {
      setIsRendered(true);
      setIsVisible(false);
      enterVisibilityTimerRef.current = setTimeout(() => {
        setIsVisible(true);
        enterVisibilityTimerRef.current = null;
      }, 0);
      enterTimerRef.current = null;
    }, delay);
  }

  function handleLeave() {
    if (enterTimerRef.current) {
      clearTimeout(enterTimerRef.current);
      enterTimerRef.current = null;
    }
    if (enterVisibilityTimerRef.current) {
      clearTimeout(enterVisibilityTimerRef.current);
      enterVisibilityTimerRef.current = null;
    }

    setIsVisible(false);
    exitTimerRef.current = setTimeout(() => {
      setIsRendered(false);
      exitTimerRef.current = null;
    }, TOOLTIP_ANIMATION_MS);
  }

  useEffect(() => {
    return () => {
      if (enterTimerRef.current) clearTimeout(enterTimerRef.current);
      if (enterVisibilityTimerRef.current) clearTimeout(enterVisibilityTimerRef.current);
      if (exitTimerRef.current) clearTimeout(exitTimerRef.current);
    };
  }, []);

  return (
    <div
      ref={ref}
      className={cn('relative inline-flex', className)}
      onMouseEnter={handleEnter}
      onMouseLeave={handleLeave}
      onFocus={handleEnter}
      onBlur={handleLeave}
    >
      {children}

      {isRendered ? (
        <div
          role="tooltip"
          className={cn(
            'absolute z-50 whitespace-nowrap px-3 py-1.5 font-body text-xs',
            'transition-[opacity,transform] duration-150 ease-out will-change-[opacity,transform]',
            isDark
              ? 'bg-nm-dark-s shadow-nm-raised-d border border-white/[0.08] text-px-white'
              : 'bg-nm-light-s shadow-nm-raised-l border border-black/[0.08] text-px-offblack',
            positionClasses[position],
            isVisible
              ? cn('opacity-100', motionClasses[position].enter)
              : cn('opacity-0 pointer-events-none', motionClasses[position].exit),
          )}
        >
          {content}
        </div>
      ) : null}
    </div>
  );
});
