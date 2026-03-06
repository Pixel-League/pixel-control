import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Textarea } from './Textarea';

describe('Textarea', () => {
  it('renders with label', () => {
    render(<Textarea label="Notes" />);
    expect(screen.getByLabelText('Notes')).toBeInTheDocument();
  });

  it('accepts text input', async () => {
    const user = userEvent.setup();
    render(<Textarea label="Notes" />);
    const field = screen.getByLabelText('Notes');

    await user.type(field, 'Match summary');
    expect(field).toHaveValue('Match summary');
  });

  it('supports disabled state', () => {
    render(<Textarea label="Notes" disabled />);
    expect(screen.getByLabelText('Notes')).toBeDisabled();
  });

  it('marks aria-invalid in error state', () => {
    render(<Textarea label="Notes" state="error" helperText="Required" />);
    expect(screen.getByLabelText('Notes')).toHaveAttribute('aria-invalid', 'true');
  });
});
