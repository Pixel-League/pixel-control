import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Bracket } from './Bracket';
import { completedBracketFixture } from './Bracket.fixtures';

describe('Bracket', () => {
  it('renders winner and loser section headers', () => {
    render(<Bracket data={completedBracketFixture} />);

    expect(screen.getByRole('heading', { name: 'Winner Bracket' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Loser Bracket' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Grand Final' })).toBeInTheDocument();
  });

  it('renders team rows and score cells', () => {
    render(<Bracket data={completedBracketFixture} />);

    expect(screen.getAllByText('OWNED').length).toBeGreaterThan(0);
    expect(screen.getAllByText('ATLAS UNIT').length).toBeGreaterThan(0);
    expect(screen.getByTestId('bracket-score-w-r1-m1-0')).toHaveTextContent('2');
    expect(screen.getByTestId('bracket-score-w-r1-m1-1')).toHaveTextContent('0');
  });

  it('renders connectors for linked matches with deterministic linkage attributes', async () => {
    render(<Bracket data={completedBracketFixture} />);

    await waitFor(() => {
      expect(screen.getByTestId('bracket-connector-w-r1-m1-w-r2-m1')).toBeInTheDocument();
    });

    const connector = screen.getByTestId('bracket-connector-w-r1-m1-w-r2-m1');
    expect(connector).toHaveAttribute('data-source-match-id', 'w-r1-m1');
    expect(connector).toHaveAttribute('data-target-match-id', 'w-r2-m1');
  });

  it('does not render connectors when showConnectors is false', () => {
    render(<Bracket data={completedBracketFixture} showConnectors={false} />);
    expect(screen.queryByTestId('bracket-connector-w-r1-m1-w-r2-m1')).not.toBeInTheDocument();
  });

  it('renders first and second ranking badges when provided', () => {
    render(<Bracket data={completedBracketFixture} />);

    expect(screen.getByTestId('bracket-ranking-1st')).toHaveTextContent('1st');
    expect(screen.getByTestId('bracket-ranking-1st')).toHaveTextContent('OWNED');
    expect(screen.getByTestId('bracket-ranking-2nd')).toHaveTextContent('2nd');
    expect(screen.getByTestId('bracket-ranking-2nd')).toHaveTextContent('ATLAS UNIT');
  });

  it('applies default dark styling and explicit light styling', () => {
    const { rerender } = render(<Bracket data={completedBracketFixture} />);
    expect(screen.getByTestId('bracket-section-winner').className).toContain('bg-nm-dark-s');

    rerender(<Bracket data={completedBracketFixture} theme="light" />);
    expect(screen.getByTestId('bracket-section-winner').className).toContain('bg-nm-light-s');
  });

  it('keeps winner and loser sections horizontally scrollable', () => {
    render(<Bracket data={completedBracketFixture} />);

    expect(screen.getByTestId('bracket-scroll-winner').className).toContain('overflow-x-auto');
    expect(screen.getByTestId('bracket-scroll-loser').className).toContain('overflow-x-auto');
  });
});
