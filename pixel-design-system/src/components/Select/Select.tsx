import { forwardRef, useId, type SelectHTMLAttributes } from 'react';
import { FormField } from '@/components/FormField/FormField';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { type FieldState, getFieldShadowState, getFieldTone, getIconChevron } from '@/tokens/neumorphic';
import { cn } from '@/utils/cn';

export interface SelectOption {
  value: string;
  label: string;
  disabled?: boolean;
}

export interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'size'> {
  label?: string;
  helperText?: string;
  state?: FieldState;
  options: SelectOption[];
  placeholder?: string;
  theme?: Theme;
}

function getSelectShadow(theme: Theme, state: FieldState, disabled?: boolean): string {
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

export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
  {
    label,
    helperText,
    state = 'default',
    options,
    placeholder,
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
  const selectId = id ?? generatedId;
  const helperId = `${selectId}-helper`;
  const tone = getFieldTone(theme);
  const hasPlaceholder = typeof placeholder === 'string' && placeholder.length > 0;
  const placeholderProps =
    hasPlaceholder && rest.value === undefined && rest.defaultValue === undefined
      ? { defaultValue: '' }
      : undefined;

  return (
    <FormField
      label={label}
      helperText={helperText}
      state={state}
      htmlFor={selectId}
      messageId={helperId}
      required={required}
      disabled={disabled}
      theme={theme}
    >
      <select
        ref={ref}
        id={selectId}
        disabled={disabled}
        required={required}
        aria-invalid={state === 'error' ? true : undefined}
        aria-describedby={helperText ? helperId : undefined}
        {...placeholderProps}
        className={cn(
          'w-full font-body text-sm px-4 py-3 pr-10 transition-shadow appearance-none bg-no-repeat',
          'focus-visible:outline-none',
          tone.border,
          theme === 'dark' ? 'bg-nm-dark text-px-white' : 'bg-nm-light text-px-offblack',
          getSelectShadow(theme, state, disabled),
          disabled && 'cursor-not-allowed text-px-label/80',
          className,
        )}
        style={{
          backgroundImage: getIconChevron(theme),
          backgroundPosition: 'right 12px center',
          backgroundSize: '20px',
        }}
        {...rest}
      >
        {hasPlaceholder ? (
          <option value="" disabled>
            {placeholder}
          </option>
        ) : null}
        {options.map((option) => (
          <option key={option.value} value={option.value} disabled={option.disabled}>
            {option.label}
          </option>
        ))}
      </select>
    </FormField>
  );
});
