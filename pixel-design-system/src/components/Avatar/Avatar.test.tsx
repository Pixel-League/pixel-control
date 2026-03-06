import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Avatar } from './Avatar';

describe('Avatar', () => {
  it('renders initials from alt text when no src', () => {
    render(<Avatar alt="Player One" />);
    expect(screen.getByText('PO')).toBeInTheDocument();
  });

  it('renders explicit initials prop', () => {
    render(<Avatar initials="PX" alt="Pixel" />);
    expect(screen.getByText('PX')).toBeInTheDocument();
  });

  it('shows fallback when no src or initials or alt', () => {
    render(<Avatar />);
    expect(screen.getByText('?')).toBeInTheDocument();
  });

  it('renders img element when src is provided', () => {
    render(<Avatar src="https://example.com/avatar.png" alt="Pixel" />);
    const img = screen.getByRole('img');
    expect(img).toHaveAttribute('src', 'https://example.com/avatar.png');
  });

  it('applies correct size class', () => {
    render(<Avatar size="lg" initials="LG" alt="Large" />);
    const el = screen.getByLabelText('Large');
    expect(el.className).toContain('w-14');
    expect(el.className).toContain('h-14');
  });

  it('applies dark theme classes by default', () => {
    render(<Avatar initials="D" alt="Dark" />);
    const el = screen.getByLabelText('Dark');
    expect(el.className).toContain('bg-nm-dark-s');
  });

  it('applies light theme classes', () => {
    render(<Avatar theme="light" initials="L" alt="Light" />);
    const el = screen.getByLabelText('Light');
    expect(el.className).toContain('bg-nm-light-s');
  });
});
