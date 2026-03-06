import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Input } from './Input';

describe('Input', () => {
  it('renders label connected to input', () => {
    render(<Input label="Email" />);
    expect(screen.getByLabelText('Email')).toBeInTheDocument();
  });

  it('accepts typing', async () => {
    const user = userEvent.setup();
    render(<Input placeholder="Type" />);
    const input = screen.getByPlaceholderText('Type');

    await user.type(input, 'OWNED');
    expect(input).toHaveValue('OWNED');
  });

  it('applies aria-invalid in error state', () => {
    render(<Input label="Team" state="error" helperText="Required" />);
    expect(screen.getByLabelText('Team')).toHaveAttribute('aria-invalid', 'true');
  });

  it('is disabled when disabled prop is true', () => {
    render(<Input label="Alias" disabled />);
    expect(screen.getByLabelText('Alias')).toBeDisabled();
  });
});
