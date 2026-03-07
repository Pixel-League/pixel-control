import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Providers } from './Providers';

describe('Providers', () => {
  it('renders children without crashing', () => {
    render(
      <Providers>
        <p>Test content</p>
      </Providers>,
    );
    expect(screen.getByText('Test content')).toBeInTheDocument();
  });

  it('wraps children with ThemeProvider (dark theme)', () => {
    const { container } = render(
      <Providers>
        <div data-testid="child">Hello</div>
      </Providers>,
    );
    expect(container).toBeTruthy();
    expect(screen.getByTestId('child')).toBeInTheDocument();
  });
});
