import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Select } from './Select';

const options = [
  { value: 'owned', label: 'OWNED' },
  { value: 'crystal', label: 'CRYSTAL' },
];

describe('Select', () => {
  it('renders options', () => {
    render(<Select label="Team" options={options} placeholder="Select team" />);

    expect(screen.getByRole('option', { name: 'OWNED' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'CRYSTAL' })).toBeInTheDocument();
  });

  it('changes selection', async () => {
    const user = userEvent.setup();
    render(<Select label="Team" options={options} />);

    const select = screen.getByLabelText('Team');
    await user.selectOptions(select, 'crystal');

    expect(select).toHaveValue('crystal');
  });

  it('supports disabled state', () => {
    render(<Select label="Team" options={options} disabled />);
    expect(screen.getByLabelText('Team')).toBeDisabled();
  });
});
