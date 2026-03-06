import { useId, type ReactNode } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';
import { type FieldState, getFieldTone } from '@/tokens/neumorphic';

export interface FormFieldProps {
  label?: ReactNode;
  helperText?: ReactNode;
  state?: FieldState;
  htmlFor?: string;
  messageId?: string;
  required?: boolean;
  disabled?: boolean;
  theme?: Theme;
  className?: string;
  children: ReactNode;
}

function getLabelTone(state: FieldState, theme: Theme): string {
  if (state === 'error') {
    return 'text-px-error';
  }

  if (state === 'success') {
    return 'text-px-success';
  }

  return getFieldTone(theme).textMuted;
}

function getMessageTone(state: FieldState): string {
  if (state === 'error') {
    return 'text-px-error';
  }

  if (state === 'success') {
    return 'text-px-success';
  }

  return 'text-px-label';
}

export function FormField({
  label,
  helperText,
  state = 'default',
  htmlFor,
  messageId,
  required,
  disabled,
  theme: themeProp,
  className,
  children,
}: FormFieldProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const generatedMessageId = useId();
  const resolvedMessageId = messageId ?? generatedMessageId;

  return (
    <div className={cn('flex flex-col gap-1.5', disabled && 'opacity-75', className)}>
      {label ? (
        <label
          htmlFor={htmlFor}
          className={cn(
            'font-body text-sm font-medium tracking-wide-body',
            getLabelTone(state, theme),
          )}
        >
          {label}
          {required ? <span className="ml-1 text-px-error">*</span> : null}
        </label>
      ) : null}
      {children}
      {helperText ? (
        <p id={resolvedMessageId} className={cn('font-body text-xs', getMessageTone(state))}>
          {helperText}
        </p>
      ) : null}
    </div>
  );
}
