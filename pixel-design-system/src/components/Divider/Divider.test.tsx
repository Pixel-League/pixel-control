import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Divider } from './Divider';

describe('Divider', () => {
  it('renders horizontal separator by default', () => {
    const { container } = render(<Divider />);
    const hr = container.querySelector('hr');
    expect(hr).toBeInTheDocument();
  });

  it('renders with label', () => {
    render(<Divider label="or" />);
    expect(screen.getByText('or')).toBeInTheDocument();
    expect(screen.getByRole('separator')).toBeInTheDocument();
  });

  it('renders vertical separator', () => {
    render(<Divider orientation="vertical" />);
    const sep = screen.getByRole('separator');
    expect(sep).toHaveAttribute('aria-orientation', 'vertical');
  });

  it('uses dark theme classes by default', () => {
    const { container } = render(<Divider />);
    const hr = container.querySelector('hr');
    expect(hr?.className).toContain('bg-white/[0.08]');
  });

  it('uses light theme classes', () => {
    const { container } = render(<Divider theme="light" />);
    const hr = container.querySelector('hr');
    expect(hr?.className).toContain('bg-black/[0.08]');
  });
});
