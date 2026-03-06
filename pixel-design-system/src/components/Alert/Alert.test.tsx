import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Alert } from './Alert';

describe('Alert', () => {
  it('renders with alert role', () => {
    render(<Alert>Body</Alert>);
    expect(screen.getByRole('alert')).toBeInTheDocument();
  });

  it('renders title and body', () => {
    render(<Alert title="Warning">Payload too large</Alert>);
    expect(screen.getByText('Warning')).toBeInTheDocument();
    expect(screen.getByText('Payload too large')).toBeInTheDocument();
  });

  it('supports custom class name', () => {
    render(<Alert className="custom">Body</Alert>);
    expect(screen.getByRole('alert')).toHaveClass('custom');
  });
});
