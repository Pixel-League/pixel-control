import { forwardRef, useId, type InputHTMLAttributes } from 'react';
import { FormField } from '@/components/FormField/FormField';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { type FieldState, getFieldShadowState, getFieldTone } from '@/tokens/neumorphic';
import { cn } from '@/utils/cn';

export interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'size'> {
  label?: string;
  helperText?: string;
  state?: FieldState;
  theme?: Theme;
}

function getInputShadow(theme: Theme, state: FieldState, disabled?: boolean): string {
  if (disabled) {
    return theme === 'dark' ? 'shadow-nm-flat-d' : 'shadow-nm-flat-l';
  }

  if (state === 'default') {
    return theme === 'dark'
      ? 'shadow-nm-inset-d focus-visible:shadow-[inset_5px_5px_10px_#000000,inset_-5px_-5px_10px_#2A2A2A,0_0_0_3px_#2C12D9]'
      : 'shadow-nm-inset-l focus-visible:shadow-[inset_5px_5px_10px_#CDD5E0,inset_-5px_-5px_10px_#FFFFFF,0_0_0_3px_#2C12D9]';
  }

  return getFieldShadowState(theme, state);
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  {
    label,
    helperText,
    state = 'default',
    id,
    disabled,
    className,
    theme: themeProp,
    required,
    ...rest
  },
  ref,
) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const generatedId = useId();
  const inputId = id ?? generatedId;
  const helperId = `${inputId}-helper`;
  const tone = getFieldTone(theme);

  return (
    <FormField
      label={label}
      helperText={helperText}
      state={state}
      htmlFor={inputId}
      messageId={helperId}
      required={required}
      disabled={disabled}
      theme={theme}
    >
      <input
        ref={ref}
        id={inputId}
        disabled={disabled}
        required={required}
        aria-invalid={state === 'error' ? true : undefined}
        aria-describedby={helperText ? helperId : undefined}
        className={cn(
          'w-full font-body text-sm px-4 py-3 placeholder:text-px-label/60 transition-shadow',
          'focus-visible:outline-none',
          tone.border,
          theme === 'dark' ? 'bg-nm-dark text-px-white' : 'bg-nm-light text-px-offblack',
          getInputShadow(theme, state, disabled),
          disabled && 'cursor-not-allowed text-px-label/80',
          className,
        )}
        {...rest}
      />
    </FormField>
  );
});
