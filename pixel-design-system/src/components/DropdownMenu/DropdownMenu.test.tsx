import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { DropdownMenu } from './DropdownMenu';

describe('DropdownMenu', () => {
  const items = [
    { label: 'Edit' },
    { label: 'Delete', danger: true },
  ];

  it('does not show menu by default', () => {
    render(
      <DropdownMenu trigger={<button>Open</button>} items={items} />,
    );
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('opens menu on click', async () => {
    const user = userEvent.setup();
    render(
      <DropdownMenu trigger={<button>Open</button>} items={items} />,
    );

    await user.click(screen.getByText('Open'));
    expect(screen.getByRole('menu')).toBeInTheDocument();
    expect(screen.getByText('Edit')).toBeInTheDocument();
  });

  it('calls onClick and closes on item click', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    const menuItems = [{ label: 'Edit', onClick }];

    render(
      <DropdownMenu trigger={<button>Open</button>} items={menuItems} />,
    );

    await user.click(screen.getByText('Open'));
    await user.click(screen.getByText('Edit'));

    expect(onClick).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('renders dividers', async () => {
    const user = userEvent.setup();
    const menuItems = [
      { label: 'A' },
      { label: '', divider: true },
      { label: 'B' },
    ];

    render(
      <DropdownMenu trigger={<button>Open</button>} items={menuItems} />,
    );

    await user.click(screen.getByText('Open'));
    const menu = screen.getByRole('menu');
    const dividers = menu.querySelectorAll('.h-px');
    expect(dividers.length).toBe(1);
  });

  it('disables items with disabled flag', async () => {
    const user = userEvent.setup();
    const menuItems = [{ label: 'Locked', disabled: true }];

    render(
      <DropdownMenu trigger={<button>Open</button>} items={menuItems} />,
    );

    await user.click(screen.getByText('Open'));
    expect(screen.getByRole('menuitem', { name: 'Locked' })).toBeDisabled();
  });

  it('closes on Escape', async () => {
    const user = userEvent.setup();
    render(
      <DropdownMenu trigger={<button>Open</button>} items={items} />,
    );

    await user.click(screen.getByText('Open'));
    expect(screen.getByRole('menu')).toBeInTheDocument();

    await user.keyboard('{Escape}');
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('applies danger style to danger items', async () => {
    const user = userEvent.setup();
    render(
      <DropdownMenu trigger={<button>Open</button>} items={items} />,
    );

    await user.click(screen.getByText('Open'));
    expect(screen.getByText('Delete').className).toContain('text-px-error');
  });
});
