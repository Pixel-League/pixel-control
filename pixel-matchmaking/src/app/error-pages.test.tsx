import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { TestProviders } from '@/test/intl-wrapper';
import NotFound from './not-found';
import ErrorPage from './error';
import Loading from './loading';

vi.mock('next/navigation', () => ({
  usePathname: () => '/',
  useRouter: () => ({
    push: vi.fn(),
    replace: vi.fn(),
    prefetch: vi.fn(),
    refresh: vi.fn(),
  }),
}));

describe('NotFound page', () => {
  it('renders 404 code', () => {
    render(<TestProviders><NotFound /></TestProviders>);
    expect(screen.getByText('404')).toBeInTheDocument();
  });

  it('renders "Page introuvable" title', () => {
    render(<TestProviders><NotFound /></TestProviders>);
    expect(screen.getByText('Page introuvable')).toBeInTheDocument();
  });

  it('renders "Back to home" button', () => {
    render(<TestProviders><NotFound /></TestProviders>);
    expect(screen.getByText("Retour à l'accueil")).toBeInTheDocument();
  });
});

describe('Error page', () => {
  const mockError = new Error('Test error message');
  const mockReset = vi.fn();

  it('renders 500 code', () => {
    render(
      <TestProviders>
        <ErrorPage error={mockError} reset={mockReset} />
      </TestProviders>,
    );
    expect(screen.getByText('500')).toBeInTheDocument();
  });

  it('renders error title', () => {
    render(
      <TestProviders>
        <ErrorPage error={mockError} reset={mockReset} />
      </TestProviders>,
    );
    expect(screen.getByText('Erreur serveur')).toBeInTheDocument();
  });

  it('renders retry and home buttons', () => {
    render(
      <TestProviders>
        <ErrorPage error={mockError} reset={mockReset} />
      </TestProviders>,
    );
    expect(screen.getByText('Réessayer')).toBeInTheDocument();
    expect(screen.getByText("Retour à l'accueil")).toBeInTheDocument();
  });
});

describe('Loading page', () => {
  it('renders skeleton elements', () => {
    const { container } = render(<Loading />);
    // Skeleton components render with the skeleton animation class
    const skeletons = container.querySelectorAll('[class*="animate"]');
    expect(skeletons.length).toBeGreaterThan(0);
  });
});
