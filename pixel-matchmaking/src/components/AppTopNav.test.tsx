import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ThemeProvider } from '@pixel-series/design-system-neumorphic';
import { AppTopNav } from './AppTopNav';

// Mock Next.js navigation
vi.mock('next/navigation', () => ({
  usePathname: () => '/',
  useRouter: () => ({
    push: vi.fn(),
    replace: vi.fn(),
    prefetch: vi.fn(),
  }),
}));

function renderWithProviders(ui: React.ReactElement) {
  return render(
    <ThemeProvider defaultTheme="dark">
      {ui}
    </ThemeProvider>,
  );
}

describe('AppTopNav', () => {
  it('renders the brand text', () => {
    renderWithProviders(<AppTopNav />);
    expect(screen.getByText('PIXEL MATCHMAKING')).toBeInTheDocument();
  });

  it('renders all navigation links', () => {
    renderWithProviders(<AppTopNav />);
    expect(screen.getByText('Accueil')).toBeInTheDocument();
    expect(screen.getByText('Jouer')).toBeInTheDocument();
    expect(screen.getByText('Classement')).toBeInTheDocument();
    expect(screen.getByText('Profil')).toBeInTheDocument();
    expect(screen.getByText('Admin')).toBeInTheDocument();
  });

  it('marks the home link as active when pathname is /', () => {
    renderWithProviders(<AppTopNav />);
    const homeLink = screen.getByText('Accueil');
    expect(homeLink).toHaveAttribute('aria-current', 'page');
  });

  it('renders the top navigation landmark', () => {
    renderWithProviders(<AppTopNav />);
    const nav = screen.getByRole('navigation', { name: /top navigation/i });
    expect(nav).toBeInTheDocument();
  });
});
