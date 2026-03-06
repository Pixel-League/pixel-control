import { forwardRef, useRef, type ChangeEvent, type DragEvent, type ReactNode } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export interface FileInputProps {
  accept?: string;
  multiple?: boolean;
  disabled?: boolean;
  label?: string;
  hint?: string;
  icon?: ReactNode;
  onChange?: (files: FileList | null) => void;
  theme?: Theme;
  className?: string;
}

export const FileInput = forwardRef<HTMLInputElement, FileInputProps>(function FileInput(
  {
    accept,
    multiple,
    disabled,
    label,
    hint,
    icon,
    onChange,
    theme: themeProp,
    className,
  },
  ref,
) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';
  const inputRef = useRef<HTMLInputElement | null>(null);

  function handleClick() {
    inputRef.current?.click();
  }

  function handleChange(e: ChangeEvent<HTMLInputElement>) {
    onChange?.(e.target.files);
  }

  function handleDragOver(e: DragEvent) {
    e.preventDefault();
  }

  function handleDrop(e: DragEvent) {
    e.preventDefault();
    if (disabled) return;
    onChange?.(e.dataTransfer.files);
  }

  function setRefs(el: HTMLInputElement | null) {
    inputRef.current = el;
    if (typeof ref === 'function') ref(el);
    else if (ref) (ref as React.MutableRefObject<HTMLInputElement | null>).current = el;
  }

  const defaultIcon = (
    <svg
      className={cn('w-8 h-8 mx-auto text-px-label mb-2')}
      fill="none"
      stroke="currentColor"
      viewBox="0 0 24 24"
      aria-hidden="true"
    >
      <path
        strokeLinecap="square"
        strokeWidth="2"
        d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12M8 8l4-4 4 4"
      />
    </svg>
  );

  return (
    <div className={className}>
      {label ? (
        <span className={cn(
          'block font-body text-sm font-medium mb-2 tracking-wide-body',
          isDark ? 'text-px-label' : 'text-px-offblack',
        )}>
          {label}
        </span>
      ) : null}

      <button
        type="button"
        disabled={disabled}
        onClick={handleClick}
        onDragOver={handleDragOver}
        onDrop={handleDrop}
        aria-label={label ?? 'Upload file'}
        className={cn(
          'w-full p-8 text-center cursor-pointer transition-shadow',
          isDark
            ? 'bg-nm-dark shadow-nm-inset-d border border-white/[0.08] hover:shadow-[inset_5px_5px_10px_#000000,inset_-5px_-5px_10px_#2A2A2A,0_0_0_3px_#2C12D9]'
            : 'bg-nm-light shadow-nm-inset-l border border-black/[0.08] hover:shadow-[inset_5px_5px_10px_#CDD5E0,inset_-5px_-5px_10px_#FFFFFF,0_0_0_3px_#2C12D9]',
          disabled && 'opacity-60 cursor-not-allowed pointer-events-none',
        )}
      >
        {icon ?? defaultIcon}
        <p className="font-body text-sm text-px-label">
          Drop files or{' '}
          <span className="text-px-primary font-semibold">browse</span>
        </p>
        {hint ? (
          <p className="font-body text-xs text-px-label/50 mt-1">{hint}</p>
        ) : null}
      </button>

      <input
        ref={setRefs}
        type="file"
        accept={accept}
        multiple={multiple}
        disabled={disabled}
        onChange={handleChange}
        className="sr-only"
        tabIndex={-1}
      />
    </div>
  );
});
