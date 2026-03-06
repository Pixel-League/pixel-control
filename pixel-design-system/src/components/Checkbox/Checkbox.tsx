import { forwardRef, useId, type InputHTMLAttributes } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'size'> {
  label?: string;
  description?: string;
  theme?: Theme;
}

export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox(
  { label, description, disabled, className, id, theme: themeProp, ...rest },
  ref,
) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const generatedId = useId();
  const checkboxId = id ?? generatedId;

  const isDark = theme === 'dark';

  return (
    <label
      htmlFor={checkboxId}
      className={cn('inline-flex items-start gap-3', disabled ? 'cursor-not-allowed' : 'cursor-pointer')}
    >
      <input
        ref={ref}
        id={checkboxId}
        type="checkbox"
        disabled={disabled}
        className={cn(
          'mt-0.5 w-5 h-5 shrink-0 appearance-none relative',
          'transition-[box-shadow,background-color] duration-150',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-px-primary focus-visible:ring-offset-0',
          'after:content-[""] after:absolute after:top-0.5 after:left-1.5 after:mt-[1px] after:ml-[1px] after:w-1.5 after:h-2.5 after:border-r-2 after:border-b-2 after:border-white after:rotate-45 after:hidden checked:after:block',
          isDark
            ? 'bg-nm-dark shadow-nm-inset-d border border-white/[0.08] checked:bg-px-primary checked:shadow-nm-btn-d checked:border-white/[0.08]'
            : 'bg-nm-light shadow-nm-inset-l border border-black/[0.08] checked:bg-px-primary checked:shadow-nm-btn-l checked:border-black/[0.08]',
          isDark ? 'active:shadow-nm-flat-d' : 'active:shadow-nm-flat-l',
          disabled && (isDark ? 'shadow-nm-flat-d opacity-60' : 'shadow-nm-flat-l opacity-60'),
          className,
        )}
        {...rest}
      />

      {(label || description) ? (
        <span className="flex flex-col gap-0.5">
          {label ? (
            <span className={cn('font-body text-sm', isDark ? 'text-px-white' : 'text-px-offblack')}>
              {label}
            </span>
          ) : null}
          {description ? (
            <span className="font-body text-xs text-px-label">{description}</span>
          ) : null}
        </span>
      ) : null}
    </label>
  );
});
