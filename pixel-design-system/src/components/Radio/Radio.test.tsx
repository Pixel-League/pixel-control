import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Radio } from './Radio';

describe('Radio', () => {
  it('allows selecting one item in same group', async () => {
    const user = userEvent.setup();

    render(
      <div>
        <Radio name="team" label="Team A" />
        <Radio name="team" label="Team B" />
      </div>,
    );

    const teamA = screen.getByRole('radio', { name: 'Team A' });
    const teamB = screen.getByRole('radio', { name: 'Team B' });

    await user.click(teamA);
    expect(teamA).toBeChecked();
    expect(teamB).not.toBeChecked();

    await user.click(teamB);
    expect(teamB).toBeChecked();
    expect(teamA).not.toBeChecked();
  });

  it('respects disabled prop', () => {
    render(<Radio name="team" label="Disabled" disabled />);
    expect(screen.getByRole('radio', { name: 'Disabled' })).toBeDisabled();
  });

  it('centers selected indicator using inset and auto margins', () => {
    render(<Radio name="team" label="Team C" />);

    const radio = screen.getByRole('radio', { name: 'Team C' });
    expect(radio.className).toContain('after:inset-0');
    expect(radio.className).toContain('after:m-auto');
  });
});
