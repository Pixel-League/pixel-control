import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Progress } from './Progress';

describe('Progress', () => {
  it('renders a bar with progressbar role', () => {
    render(<Progress value={50} />);
    const el = screen.getByRole('progressbar');
    expect(el).toBeInTheDocument();
    expect(el).toHaveAttribute('aria-valuenow', '50');
  });

  it('clamps value between 0 and 100', () => {
    render(<Progress value={150} />);
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
  });

  it('renders label text', () => {
    render(<Progress value={60} label="Upload progress" />);
    expect(screen.getByText('Upload progress')).toBeInTheDocument();
    expect(screen.getByText('60%')).toBeInTheDocument();
  });

  it('renders indeterminate bar without aria-valuenow', () => {
    render(<Progress label="Processing" />);
    const el = screen.getByRole('progressbar');
    expect(el).not.toHaveAttribute('aria-valuenow');
  });

  it('renders spinner variant with status role', () => {
    render(<Progress variant="spinner" />);
    const el = screen.getByRole('status');
    expect(el).toBeInTheDocument();
  });

  it('renders spinner label', () => {
    render(<Progress variant="spinner" label="Loading" />);
    expect(screen.getByText('Loading')).toBeInTheDocument();
  });

  it('applies dark theme classes by default', () => {
    const { container } = render(<Progress value={30} />);
    expect(container.querySelector('.bg-nm-dark-s')).toBeInTheDocument();
  });

  it('applies light theme classes', () => {
    const { container } = render(<Progress value={30} theme="light" />);
    expect(container.querySelector('.bg-nm-light-s')).toBeInTheDocument();
  });
});
