import { useId, useMemo, useState, type KeyboardEvent } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export interface SwitchProps {
  checked?: boolean;
  defaultChecked?: boolean;
  onCheckedChange?: (checked: boolean) => void;
  label?: string;
  description?: string;
  disabled?: boolean;
  theme?: Theme;
  className?: string;
}

export function Switch({
  checked: controlledChecked,
  defaultChecked = false,
  onCheckedChange,
  label,
  description,
  disabled,
  theme: themeProp,
  className,
}: SwitchProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';
  const generatedId = useId();

  const isControlled = typeof controlledChecked === 'boolean';
  const [internalChecked, setInternalChecked] = useState(defaultChecked);
  const checked = isControlled ? controlledChecked : internalChecked;

  const labelId = useMemo(() => (label ? `${generatedId}-label` : undefined), [generatedId, label]);

  function updateChecked(nextValue: boolean) {
    if (!isControlled) {
      setInternalChecked(nextValue);
    }
    onCheckedChange?.(nextValue);
  }

  function handleToggle() {
    if (disabled) {
      return;
    }
    updateChecked(!checked);
  }

  function handleKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      handleToggle();
    }
  }

  return (
    <div className={cn('inline-flex items-start gap-3', className)}>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        aria-labelledby={labelId}
        disabled={disabled}
        onClick={handleToggle}
        onKeyDown={handleKeyDown}
        className={cn(
          'relative mt-0.5 w-11 h-6',
          'transition-[box-shadow,background-color] duration-150',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-px-primary focus-visible:ring-offset-0',
          checked
            ? cn(
                'bg-px-primary',
                isDark ? 'shadow-nm-inset-d border border-white/[0.08]' : 'shadow-nm-inset-l border border-black/[0.08]',
              )
            : cn(
                isDark ? 'bg-nm-dark-s shadow-nm-inset-d border border-white/[0.08]' : 'bg-nm-light-s shadow-nm-inset-l border border-black/[0.08]',
              ),
          isDark ? 'active:shadow-nm-flat-d' : 'active:shadow-nm-flat-l',
          disabled && 'opacity-60 cursor-not-allowed',
        )}
      >
        <span
          className={cn(
            'absolute top-0.5 left-0.5 w-5 h-5 bg-px-white transition-transform',
            checked ? 'translate-x-5' : 'translate-x-0',
            isDark ? 'shadow-nm-btn-d border border-white/[0.08]' : 'shadow-nm-btn-l border border-black/[0.08]',
          )}
        />
      </button>

      {(label || description) ? (
        <span className="flex flex-col gap-0.5">
          {label ? (
            <span
              id={labelId}
              className={cn('font-body text-sm', isDark ? 'text-px-white' : 'text-px-offblack')}
            >
              {label}
            </span>
          ) : null}
          {description ? <span className="font-body text-xs text-px-label">{description}</span> : null}
        </span>
      ) : null}
    </div>
  );
}
