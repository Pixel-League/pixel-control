import { useId, useState, type KeyboardEvent, type ReactNode } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export interface TabItem {
  label: string;
  content: ReactNode;
  disabled?: boolean;
}

export interface TabsProps {
  tabs: TabItem[];
  defaultIndex?: number;
  theme?: Theme;
  className?: string;
}

export function Tabs({ tabs, defaultIndex = 0, theme: themeProp, className }: TabsProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';
  const [activeIndex, setActiveIndex] = useState(defaultIndex);
  const tabsetId = useId();

  function moveFocus(event: KeyboardEvent<HTMLButtonElement>, currentIndex: number): void {
    let nextIndex = currentIndex;

    if (event.key === 'ArrowRight') {
      event.preventDefault();
      nextIndex = (currentIndex + 1) % tabs.length;
    } else if (event.key === 'ArrowLeft') {
      event.preventDefault();
      nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
    } else if (event.key === 'Home') {
      event.preventDefault();
      nextIndex = 0;
    } else if (event.key === 'End') {
      event.preventDefault();
      nextIndex = tabs.length - 1;
    } else {
      return;
    }

    setActiveIndex(nextIndex);
    const tabList = event.currentTarget.parentElement;
    const tabButtons = tabList?.querySelectorAll<HTMLButtonElement>('[role="tab"]');
    tabButtons?.[nextIndex]?.focus();
  }

  return (
    <div
      className={cn(
        isDark
          ? 'bg-nm-dark shadow-nm-raised-d border border-white/[0.08]'
          : 'bg-nm-light shadow-nm-raised-l border border-black/[0.08]',
        'p-6 flex flex-col gap-4',
        className,
      )}
    >
      <div role="tablist" aria-orientation="horizontal" className="flex gap-2">
        {tabs.map((tab, index) => {
          const isActive = index === activeIndex;

          return (
            <button
              key={`${tab.label}-${index}`}
              id={`${tabsetId}-tab-${index}`}
              type="button"
              role="tab"
              aria-selected={isActive}
              aria-controls={`${tabsetId}-panel-${index}`}
              tabIndex={isActive ? 0 : -1}
              disabled={tab.disabled}
              onClick={() => setActiveIndex(index)}
              onKeyDown={(event) => moveFocus(event, index)}
              className={cn(
                'font-body text-sm px-6 py-3 uppercase tracking-wide-body',
                'transition-[box-shadow,color] duration-150',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-px-primary focus-visible:ring-offset-0',
                isActive
                  ? isDark
                    ? 'bg-px-primary text-px-white shadow-nm-btn-d border border-white/[0.08]'
                    : 'bg-px-primary text-px-white shadow-nm-btn-l border border-black/[0.08]'
                  : isDark
                    ? 'text-px-label shadow-nm-flat-d border border-white/[0.08] hover:text-px-white'
                    : 'text-px-label shadow-nm-flat-l border border-black/[0.08] hover:text-px-offblack',
                isDark ? 'active:shadow-nm-flat-d' : 'active:shadow-nm-flat-l',
                tab.disabled && 'opacity-50 cursor-not-allowed',
              )}
            >
              {tab.label}
            </button>
          );
        })}
      </div>

      {tabs.map((tab, index) => (
        <div
          key={`${tab.label}-panel-${index}`}
          id={`${tabsetId}-panel-${index}`}
          role="tabpanel"
          tabIndex={0}
          aria-labelledby={`${tabsetId}-tab-${index}`}
          hidden={index !== activeIndex}
          className="animate-fade-slide-up"
        >
          {tab.content}
        </div>
      ))}
    </div>
  );
}
