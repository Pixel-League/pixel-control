import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Badge } from './Badge';

describe('Badge', () => {
  it('renders as span with text', () => {
    render(<Badge>Live</Badge>);
    const badge = screen.getByText('Live');

    expect(badge.tagName).toBe('SPAN');
    expect(badge).toBeInTheDocument();
  });

  it('applies custom class name', () => {
    render(<Badge className="custom">Tag</Badge>);
    expect(screen.getByText('Tag')).toHaveClass('custom');
  });
});
