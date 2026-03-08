import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MpNickname } from './MpNickname';

describe('MpNickname', () => {
  it('renders plain text without inline styles', () => {
    const { container } = render(<MpNickname nickname="TestPlayer" />);
    expect(screen.getByText('TestPlayer')).toBeInTheDocument();
    // No dangerouslySetInnerHTML — inner span has no style attribute
    const span = container.querySelector('span');
    expect(span?.getAttribute('style')).toBeNull();
  });

  it('renders text content visible for a formatted nickname', () => {
    render(<MpNickname nickname="$fffTestPlayer" />);
    // The text "TestPlayer" should be accessible in the DOM even when wrapped in colored spans
    expect(screen.getByText('TestPlayer')).toBeInTheDocument();
  });

  it('renders inline color style for a color-coded nickname', () => {
    const { container } = render(<MpNickname nickname="$f00R" />);
    const inner = container.querySelector('span > span');
    // tm-text produces an inner span with the color style
    expect(inner?.getAttribute('style')).toContain('color');
  });

  it('applies className to the outer span', () => {
    const { container } = render(<MpNickname nickname="Test" className="my-class" />);
    expect(container.querySelector('span.my-class')).toBeInTheDocument();
  });
});
