import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Checkbox } from './Checkbox';

describe('Checkbox', () => {
  it('toggles when clicked', async () => {
    const user = userEvent.setup();
    render(<Checkbox label="Active" />);

    const checkbox = screen.getByRole('checkbox', { name: 'Active' });
    expect(checkbox).not.toBeChecked();

    await user.click(checkbox);
    expect(checkbox).toBeChecked();
  });

  it('can be toggled by clicking label text', async () => {
    const user = userEvent.setup();
    render(<Checkbox label="Substitute" />);

    await user.click(screen.getByText('Substitute'));
    expect(screen.getByRole('checkbox', { name: 'Substitute' })).toBeChecked();
  });

  it('is disabled when disabled prop is true', () => {
    render(<Checkbox label="Banned" disabled />);
    expect(screen.getByRole('checkbox', { name: 'Banned' })).toBeDisabled();
  });

  it('offsets check mark by 1px on both axes', () => {
    render(<Checkbox label="Aligned" />);

    const checkbox = screen.getByRole('checkbox', { name: 'Aligned' });
    expect(checkbox.className).toContain('after:mt-[1px]');
    expect(checkbox.className).toContain('after:ml-[1px]');
  });
});
