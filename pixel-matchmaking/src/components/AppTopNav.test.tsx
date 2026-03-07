import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ThemeProvider } from '@pixel-series/design-system-neumorphic';
import { SessionProvider } from 'next-auth/react';
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
    <SessionProvider session={null}>
      <ThemeProvider defaultTheme="dark">
        {ui}
      </ThemeProvider>
    </SessionProvider>,
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

  it('shows "Se connecter" button when not logged in', () => {
    renderWithProviders(<AppTopNav />);
    expect(screen.getByText('Se connecter')).toBeInTheDocument();
  });

  it('shows user nickname when logged in', () => {
    const mockSession = {
      user: {
        name: 'TestPlayer',
        login: 'testplayer',
        nickname: 'TestPlayer',
        path: 'World|Europe|France',
        role: 'player',
      },
      expires: new Date(Date.now() + 86400000).toISOString(),
    };

    render(
      <SessionProvider session={mockSession}>
        <ThemeProvider defaultTheme="dark">
          <AppTopNav />
        </ThemeProvider>
      </SessionProvider>,
    );

    expect(screen.getByText('TestPlayer')).toBeInTheDocument();
    expect(screen.getByText('Deconnexion')).toBeInTheDocument();
  });
});
