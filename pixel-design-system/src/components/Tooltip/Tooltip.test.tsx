import { render, screen, act, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { Tooltip } from './Tooltip';

const TOOLTIP_EXIT_ANIMATION_MS = 160;

describe('Tooltip', () => {
  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('does not show tooltip by default', () => {
    render(
      <Tooltip content="Help text">
        <button>Hover</button>
      </Tooltip>,
    );
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });

  it('shows tooltip on mouse enter after delay', async () => {
    const { container } = render(
      <Tooltip content="Help text" delay={100}>
        <button>Hover</button>
      </Tooltip>,
    );

    // Fire on the wrapper div (which holds onMouseEnter), not the child button
    const wrapper = container.firstElementChild!;

    await act(async () => {
      fireEvent.mouseEnter(wrapper);
    });

    await act(async () => {
      vi.advanceTimersByTime(150);
    });

    expect(screen.getByRole('tooltip')).toBeInTheDocument();
    expect(screen.getByText('Help text')).toBeInTheDocument();
  });

  it('hides tooltip on mouse leave', async () => {
    const { container } = render(
      <Tooltip content="Info" delay={0}>
        <button>Trigger</button>
      </Tooltip>,
    );

    const wrapper = container.firstElementChild!;

    await act(async () => {
      fireEvent.mouseEnter(wrapper);
      vi.advanceTimersByTime(10);
    });

    expect(screen.getByRole('tooltip')).toBeInTheDocument();

    await act(async () => {
      fireEvent.mouseLeave(wrapper);
    });

    await act(async () => {
      vi.advanceTimersByTime(TOOLTIP_EXIT_ANIMATION_MS + 20);
    });

    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });

  it('applies dark theme classes by default', async () => {
    const { container } = render(
      <Tooltip content="Dark" delay={0}>
        <button>T</button>
      </Tooltip>,
    );

    const wrapper = container.firstElementChild!;

    await act(async () => {
      fireEvent.mouseEnter(wrapper);
      vi.advanceTimersByTime(10);
    });

    expect(screen.getByRole('tooltip').className).toContain('bg-nm-dark-s');
  });
});
