import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export type ButtonVariant =
  | 'primary'
  | 'secondary'
  | 'ghost'
  | 'ghost-primary'
  | 'destructive'
  | 'destructive-outline';

export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  leftIcon?: ReactNode;
  rightIcon?: ReactNode;
  theme?: Theme;
}

const sizeClasses: Record<ButtonSize, string> = {
  sm: 'text-xs px-4 py-2',
  md: 'text-sm px-5 py-2.5',
  lg: 'text-sm px-6 py-3',
};

const variantClasses: Record<Theme, Record<ButtonVariant, string>> = {
  dark: {
    primary:
      'bg-px-primary text-px-white shadow-nm-btn-d border border-white/[0.08] hover:bg-px-primary-light active:shadow-nm-flat-d',
    secondary:
      'bg-nm-dark text-px-primary shadow-nm-raised-d border border-white/[0.08] hover:text-px-primary-light active:shadow-nm-flat-d',
    ghost:
      'bg-nm-dark text-px-white shadow-nm-flat-d border border-white/[0.08] hover:shadow-nm-raised-d active:shadow-nm-flat-d',
    'ghost-primary':
      'bg-nm-dark text-px-primary shadow-nm-flat-d border border-white/[0.08] hover:text-px-primary-light hover:shadow-nm-raised-d active:shadow-nm-flat-d',
    destructive:
      'bg-px-error text-px-white shadow-nm-btn-d border border-white/[0.08] hover:bg-red-700 active:shadow-nm-flat-d',
    'destructive-outline':
      'bg-nm-dark text-px-error shadow-nm-raised-d border border-white/[0.08] hover:text-red-700 active:shadow-nm-flat-d',
  },
  light: {
    primary:
      'bg-px-primary text-px-white shadow-nm-btn-l border border-black/[0.08] hover:bg-px-primary-light active:shadow-nm-flat-l',
    secondary:
      'bg-nm-light text-px-primary shadow-nm-raised-l border border-black/[0.08] hover:text-px-primary-light active:shadow-nm-flat-l',
    ghost:
      'bg-nm-light text-px-offblack shadow-nm-flat-l border border-black/[0.08] hover:shadow-nm-raised-l active:shadow-nm-flat-l',
    'ghost-primary':
      'bg-nm-light text-px-primary shadow-nm-flat-l border border-black/[0.08] hover:text-px-primary-light hover:shadow-nm-raised-l active:shadow-nm-flat-l',
    destructive:
      'bg-px-error text-px-white shadow-nm-btn-l border border-black/[0.08] hover:bg-red-700 active:shadow-nm-flat-l',
    'destructive-outline':
      'bg-nm-light text-px-error shadow-nm-raised-l border border-black/[0.08] hover:text-red-700 active:shadow-nm-flat-l',
  },
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = 'primary',
    size = 'md',
    leftIcon,
    rightIcon,
    disabled,
    className,
    children,
    theme: themeProp,
    ...rest
  },
  ref,
) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;

  return (
    <button
      ref={ref}
      type="button"
      disabled={disabled}
      className={cn(
        'inline-flex items-center justify-center gap-2 font-body font-semibold uppercase tracking-wide-body',
        'transition-[box-shadow,background-color,color] duration-150',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-px-primary focus-visible:ring-offset-0',
        sizeClasses[size],
        variantClasses[theme][variant],
        disabled &&
          (theme === 'dark'
            ? 'opacity-60 cursor-not-allowed pointer-events-none shadow-nm-flat-d text-px-label'
            : 'opacity-60 cursor-not-allowed pointer-events-none shadow-nm-flat-l text-px-label'),
        className,
      )}
      {...rest}
    >
      {leftIcon ? <span className="inline-flex shrink-0">{leftIcon}</span> : null}
      {children}
      {rightIcon ? <span className="inline-flex shrink-0">{rightIcon}</span> : null}
    </button>
  );
});
