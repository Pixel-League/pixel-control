import { useMemo } from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

type PaginationItem = number | 'ellipsis';

export interface PaginationProps {
  totalPages: number;
  currentPage: number;
  onPageChange: (page: number) => void;
  siblingCount?: number;
  theme?: Theme;
  className?: string;
}

function getRange(currentPage: number, totalPages: number, siblingCount: number): PaginationItem[] {
  const totalNumbers = siblingCount * 2 + 5;

  if (totalNumbers >= totalPages) {
    return Array.from({ length: totalPages }, (_, index) => index + 1);
  }

  const leftSiblingIndex = Math.max(currentPage - siblingCount, 1);
  const rightSiblingIndex = Math.min(currentPage + siblingCount, totalPages);
  const showLeftDots = leftSiblingIndex > 2;
  const showRightDots = rightSiblingIndex < totalPages - 1;

  if (!showLeftDots && showRightDots) {
    const leftItemCount = 3 + 2 * siblingCount;
    const leftRange = Array.from({ length: leftItemCount }, (_, index) => index + 1);
    return [...leftRange, 'ellipsis', totalPages];
  }

  if (showLeftDots && !showRightDots) {
    const rightItemCount = 3 + 2 * siblingCount;
    const rightRange = Array.from(
      { length: rightItemCount },
      (_, index) => totalPages - rightItemCount + 1 + index,
    );
    return [1, 'ellipsis', ...rightRange];
  }

  const middleRange = Array.from(
    { length: rightSiblingIndex - leftSiblingIndex + 1 },
    (_, index) => leftSiblingIndex + index,
  );

  return [1, 'ellipsis', ...middleRange, 'ellipsis', totalPages];
}

export function Pagination({
  totalPages,
  currentPage,
  onPageChange,
  siblingCount = 1,
  theme: themeProp,
  className,
}: PaginationProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';

  const pages = useMemo(
    () => getRange(currentPage, totalPages, siblingCount),
    [currentPage, siblingCount, totalPages],
  );

  const itemBase = cn(
    'w-10 h-10 inline-flex items-center justify-center font-body text-sm',
    'transition-[box-shadow,color,background-color] duration-150',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-px-primary focus-visible:ring-offset-0',
  );

  return (
    <nav aria-label="Pagination" className={cn('inline-flex items-center gap-2', className)}>
      <button
        type="button"
        aria-label="Previous page"
        disabled={currentPage <= 1}
        onClick={() => onPageChange(currentPage - 1)}
        className={cn(
          itemBase,
          isDark
            ? 'text-px-label shadow-nm-flat-d border border-white/[0.08] hover:text-px-white hover:shadow-nm-raised-d'
            : 'text-px-label shadow-nm-flat-l border border-black/[0.08] hover:text-px-offblack hover:shadow-nm-raised-l',
          isDark ? 'active:shadow-nm-flat-d' : 'active:shadow-nm-flat-l',
          'disabled:opacity-50 disabled:cursor-not-allowed',
        )}
      >
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path strokeLinecap="square" strokeWidth="2" d="M15 19l-7-7 7-7" />
        </svg>
      </button>

      {pages.map((page, index) => {
        if (page === 'ellipsis') {
          return (
            <span key={`ellipsis-${index}`} className="w-10 h-10 inline-flex items-center justify-center text-px-label">
              ...
            </span>
          );
        }

        const isCurrent = page === currentPage;
        return (
          <button
            key={`page-${page}`}
            type="button"
            aria-label={`Page ${page}`}
            aria-current={isCurrent ? 'page' : undefined}
            onClick={() => onPageChange(page)}
            className={cn(
              itemBase,
              isCurrent
                ? isDark
                  ? 'bg-px-primary text-px-white shadow-nm-btn-d border border-white/[0.08]'
                  : 'bg-px-primary text-px-white shadow-nm-btn-l border border-black/[0.08]'
                : isDark
                  ? 'text-px-label shadow-nm-flat-d border border-white/[0.08] hover:text-px-white hover:shadow-nm-raised-d'
                  : 'text-px-label shadow-nm-flat-l border border-black/[0.08] hover:text-px-offblack hover:shadow-nm-raised-l',
              isDark ? 'active:shadow-nm-flat-d' : 'active:shadow-nm-flat-l',
            )}
          >
            {page}
          </button>
        );
      })}

      <button
        type="button"
        aria-label="Next page"
        disabled={currentPage >= totalPages}
        onClick={() => onPageChange(currentPage + 1)}
        className={cn(
          itemBase,
          isDark
            ? 'text-px-label shadow-nm-flat-d border border-white/[0.08] hover:text-px-white hover:shadow-nm-raised-d'
            : 'text-px-label shadow-nm-flat-l border border-black/[0.08] hover:text-px-offblack hover:shadow-nm-raised-l',
          isDark ? 'active:shadow-nm-flat-d' : 'active:shadow-nm-flat-l',
          'disabled:opacity-50 disabled:cursor-not-allowed',
        )}
      >
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path strokeLinecap="square" strokeWidth="2" d="M9 5l7 7-7 7" />
        </svg>
      </button>
    </nav>
  );
}
