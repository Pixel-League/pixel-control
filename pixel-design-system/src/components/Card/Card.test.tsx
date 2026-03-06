import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Badge } from '@/components/Badge/Badge';
import { Card } from './Card';

describe('Card', () => {
  it('renders title and description', () => {
    render(<Card title="OWNED vs CRYSTAL" description="Semi-final" />);

    expect(screen.getByText('OWNED vs CRYSTAL')).toBeInTheDocument();
    expect(screen.getByText('Semi-final')).toBeInTheDocument();
  });

  it('renders badge and custom children', () => {
    render(
      <Card title="Match" badge={<Badge>Week 4</Badge>}>
        <button type="button">Open</button>
      </Card>,
    );

    expect(screen.getByText('Week 4')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Open' })).toBeInTheDocument();
  });
});
