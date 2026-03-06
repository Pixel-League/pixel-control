import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Skeleton } from './Skeleton';

describe('Skeleton', () => {
  it('renders with status role', () => {
    render(<Skeleton />);
    expect(screen.getByRole('status')).toBeInTheDocument();
  });

  it('has loading aria label', () => {
    render(<Skeleton />);
    expect(screen.getByLabelText('Loading')).toBeInTheDocument();
  });

  it('renders multiple lines for text variant', () => {
    const { container } = render(<Skeleton variant="text" lines={3} />);
    const divs = container.querySelectorAll('.animate-pulse');
    expect(divs.length).toBe(3);
  });

  it('applies circular class for circular variant', () => {
    render(<Skeleton variant="circular" />);
    const el = screen.getByRole('status');
    expect(el.className).toContain('rounded-full');
  });

  it('respects custom width and height', () => {
    render(<Skeleton width={200} height={40} />);
    const el = screen.getByRole('status');
    expect(el.style.width).toBe('200px');
    expect(el.style.height).toBe('40px');
  });

  it('applies dark theme classes by default', () => {
    render(<Skeleton />);
    const el = screen.getByRole('status');
    expect(el.className).toContain('bg-nm-dark-s');
  });

  it('applies light theme classes', () => {
    render(<Skeleton theme="light" />);
    const el = screen.getByRole('status');
    expect(el.className).toContain('bg-nm-light-s');
  });

  it('last line is narrower in multi-line mode', () => {
    const { container } = render(<Skeleton variant="text" lines={3} />);
    const divs = container.querySelectorAll('.animate-pulse');
    expect(divs[2]?.className).toContain('w-3/4');
  });
});
