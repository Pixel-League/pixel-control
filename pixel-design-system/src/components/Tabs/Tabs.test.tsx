import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Tabs } from './Tabs';

const tabs = [
  { label: 'Planning', content: <div>Planning content</div> },
  { label: 'Ranking', content: <div>Ranking content</div> },
  { label: 'Results', content: <div>Results content</div> },
];

describe('Tabs', () => {
  it('renders tab controls and default content', () => {
    render(<Tabs tabs={tabs} />);

    expect(screen.getAllByRole('tab')).toHaveLength(3);
    expect(screen.getByText('Planning content')).toBeVisible();
  });

  it('changes tab when clicked', async () => {
    const user = userEvent.setup();
    render(<Tabs tabs={tabs} />);

    await user.click(screen.getByRole('tab', { name: 'Ranking' }));
    expect(screen.getByText('Ranking content')).toBeVisible();
  });

  it('supports keyboard navigation', async () => {
    const user = userEvent.setup();
    render(<Tabs tabs={tabs} />);

    const firstTab = screen.getByRole('tab', { name: 'Planning' });
    firstTab.focus();
    await user.keyboard('{ArrowRight}');

    expect(screen.getByText('Ranking content')).toBeVisible();
  });

  it('uses pagination selected styling for active tab', () => {
    render(<Tabs tabs={tabs} />);

    const activeTab = screen.getByRole('tab', { name: 'Planning' });
    expect(activeTab.className).toContain('bg-px-primary');
    expect(activeTab.className).toContain('text-px-white');
  });
});
