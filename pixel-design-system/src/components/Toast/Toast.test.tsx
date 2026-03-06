import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Toast } from './Toast';

describe('Toast', () => {
  it('renders title', () => {
    render(<Toast title="Match saved" />);
    expect(screen.getByText('Match saved')).toBeInTheDocument();
  });

  it('renders description when provided', () => {
    render(<Toast title="Match saved" description="OWNED 3-1 CRYSTAL" />);
    expect(screen.getByText('OWNED 3-1 CRYSTAL')).toBeInTheDocument();
  });

  it('does not render description when omitted', () => {
    render(<Toast title="Match saved" />);
    expect(screen.queryByText('OWNED 3-1 CRYSTAL')).not.toBeInTheDocument();
  });

  it('has status role with polite aria-live', () => {
    render(<Toast title="Hello" />);
    const el = screen.getByRole('status');
    expect(el).toHaveAttribute('aria-live', 'polite');
  });

  it('renders dismiss button when onDismiss provided', async () => {
    const user = userEvent.setup();
    const onDismiss = vi.fn();
    render(<Toast title="Closable" onDismiss={onDismiss} />);

    const btn = screen.getByRole('button', { name: 'Dismiss' });
    await user.click(btn);

    await waitFor(() => {
      expect(onDismiss).toHaveBeenCalledTimes(1);
    });
  });

  it('does not render dismiss button without onDismiss', () => {
    render(<Toast title="Static" />);
    expect(screen.queryByRole('button', { name: 'Dismiss' })).not.toBeInTheDocument();
  });

  it('shows accent bar with variant color', () => {
    const { container } = render(<Toast title="Error" variant="error" />);
    const bar = container.querySelector('.bg-px-error');
    expect(bar).toBeInTheDocument();
  });

  it('applies light theme classes', () => {
    render(<Toast title="Light" theme="light" />);
    const el = screen.getByRole('status');
    expect(el.className).toContain('bg-nm-light');
  });
});
