import type { ReactNode } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export interface TableColumn<T> {
  key: string & keyof T;
  header: string;
  render?: (value: T[keyof T], row: T, index: number) => ReactNode;
  className?: string;
}

export interface TableProps<T extends object> {
  columns: TableColumn<T>[];
  data: T[];
  rowKey: keyof T | ((row: T, index: number) => string);
  striped?: boolean;
  hoverable?: boolean;
  theme?: Theme;
  className?: string;
  onRowClick?: (row: T, index: number) => void;
}

export function Table<T extends object>({
  columns,
  data,
  rowKey,
  striped = false,
  hoverable = true,
  theme: themeProp,
  className,
  onRowClick,
}: TableProps<T>) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';

  function getRowKey(row: T, index: number): string {
    if (typeof rowKey === 'function') return rowKey(row, index);
    return String(row[rowKey]);
  }

  return (
    <div
      className={cn(
        'overflow-hidden',
        isDark
          ? 'shadow-nm-raised-d border border-white/[0.08]'
          : 'shadow-nm-raised-l border border-black/[0.08]',
        className,
      )}
    >
      <table className="w-full" role="table">
        <thead>
          <tr className={isDark ? 'bg-px-primary' : 'bg-px-offblack'}>
            {columns.map((col) => (
              <th
                key={col.key}
                className={cn(
                  'font-display text-sm font-bold tracking-display uppercase text-px-white text-left px-6 py-3',
                  col.className,
                )}
              >
                {col.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className={isDark ? 'bg-nm-dark' : 'bg-nm-light'}>
          {data.map((row, rowIndex) => (
            <tr
              key={getRowKey(row, rowIndex)}
              onClick={onRowClick ? () => onRowClick(row, rowIndex) : undefined}
              className={cn(
                'transition-colors',
                hoverable &&
                  (isDark ? 'hover:bg-nm-dark-s' : 'hover:bg-nm-light-s'),
                striped &&
                  rowIndex % 2 === 1 &&
                  (isDark ? 'bg-nm-dark-s' : 'bg-nm-light-s'),
                onRowClick && 'cursor-pointer',
              )}
            >
              {columns.map((col) => (
                <td
                  key={col.key}
                  className={cn(
                    'font-body text-sm px-6 py-3',
                    isDark ? 'text-px-white' : 'text-px-offblack',
                    col.className,
                  )}
                >
                  {col.render
                    ? col.render(row[col.key], row, rowIndex)
                    : String(row[col.key] ?? '')}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
