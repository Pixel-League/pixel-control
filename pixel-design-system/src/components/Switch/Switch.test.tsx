import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Switch } from './Switch';

describe('Switch', () => {
  it('toggles uncontrolled state on click', async () => {
    const user = userEvent.setup();
    render(<Switch label="Notifications" />);

    const toggle = screen.getByRole('switch', { name: 'Notifications' });
    expect(toggle).toHaveAttribute('aria-checked', 'false');

    await user.click(toggle);
    expect(toggle).toHaveAttribute('aria-checked', 'true');
  });

  it('calls onCheckedChange', async () => {
    const user = userEvent.setup();
    const onCheckedChange = vi.fn();

    render(<Switch label="Notifications" onCheckedChange={onCheckedChange} />);

    await user.click(screen.getByRole('switch', { name: 'Notifications' }));
    expect(onCheckedChange).toHaveBeenCalledWith(true);
  });

  it('supports keyboard toggle', async () => {
    const user = userEvent.setup();
    render(<Switch label="Keyboard" />);

    const toggle = screen.getByRole('switch', { name: 'Keyboard' });
    toggle.focus();
    await user.keyboard('{Enter}');

    expect(toggle).toHaveAttribute('aria-checked', 'true');
  });

  it('does not toggle when disabled', async () => {
    const user = userEvent.setup();
    render(<Switch label="Disabled" disabled defaultChecked={false} />);

    const toggle = screen.getByRole('switch', { name: 'Disabled' });
    await user.click(toggle);

    expect(toggle).toHaveAttribute('aria-checked', 'false');
  });
});
