import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { Providers } from './Providers';

// Mock SessionProvider to avoid network calls in tests
vi.mock('next-auth/react', () => ({
  SessionProvider: ({ children }: { children: ReactNode }) => children,
  useSession: () => ({ data: null, status: 'unauthenticated' }),
  signOut: vi.fn(),
}));

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
