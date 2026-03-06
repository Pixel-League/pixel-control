import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Breadcrumb } from './Breadcrumb';

const items = [
  { label: 'Home', href: '/' },
  { label: 'Season 3', href: '/season-3' },
  { label: 'Week 4' },
];

describe('Breadcrumb', () => {
  it('renders as navigation landmark', () => {
    render(<Breadcrumb items={items} />);
    expect(screen.getByRole('navigation', { name: 'Breadcrumb' })).toBeInTheDocument();
  });

  it('renders links and current page', () => {
    render(<Breadcrumb items={items} />);

    expect(screen.getByRole('link', { name: 'Home' })).toHaveAttribute('href', '/');
    expect(screen.getByText('Week 4')).toHaveAttribute('aria-current', 'page');
  });
});
