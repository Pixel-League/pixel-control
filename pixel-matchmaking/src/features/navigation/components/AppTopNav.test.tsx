import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { NextIntlClientProvider } from 'next-intl';
import { ThemeProvider } from '@pixel-series/design-system-neumorphic';
import { SessionProvider } from 'next-auth/react';
import { AppTopNav } from './AppTopNav';
import messages from '../../../../messages/fr.json';

// Mock Next.js navigation
vi.mock('next/navigation', () => ({
  usePathname: () => '/',
  useSearchParams: () => ({ get: () => null }),
  useRouter: () => ({
    push: vi.fn(),
    replace: vi.fn(),
    prefetch: vi.fn(),
    refresh: vi.fn(),
  }),
}));

// Mock UserMenu to isolate AppTopNav from dropdown internals
vi.mock('@/features/navigation/components/UserMenu', () => ({
  UserMenu: ({ nickname, login }: { nickname: string; login: string }) => (
    <div data-testid="user-menu" data-nickname={nickname} data-login={login} />
  ),
}));

// Mock useDevMode to control dev/production mode in tests
vi.mock('@/shared/hooks/useDevMode', () => ({
  useDevMode: vi.fn(() => false),
}));

import { useDevMode } from '@/shared/hooks/useDevMode';

function renderWithProviders(ui: React.ReactElement, session: Parameters<typeof SessionProvider>[0]['session'] = null) {
  return render(
    <NextIntlClientProvider locale="fr" messages={messages}>
      <SessionProvider session={session}>
        <ThemeProvider defaultTheme="dark">
          {ui}
        </ThemeProvider>
      </SessionProvider>
    </NextIntlClientProvider>,
  );
}

describe('AppTopNav', () => {
  it('renders the brand text', () => {
    renderWithProviders(<AppTopNav />);
    expect(screen.getByText('PIXEL MATCHMAKING')).toBeInTheDocument();
  });

  it('renders only "Jouer" link in production mode (devMode=false)', () => {
    vi.mocked(useDevMode).mockReturnValue(false);
    renderWithProviders(<AppTopNav />);
    expect(screen.getByText('Jouer')).toBeInTheDocument();
    expect(screen.queryByText('Classement')).not.toBeInTheDocument();
    expect(screen.queryByText('Profil')).not.toBeInTheDocument();
    expect(screen.queryByText('Admin')).not.toBeInTheDocument();
  });

  it('renders dev nav items when devMode=true', () => {
    vi.mocked(useDevMode).mockReturnValue(true);
    renderWithProviders(<AppTopNav />);
    expect(screen.getByText('Jouer')).toBeInTheDocument();
    expect(screen.getByText('Classement')).toBeInTheDocument();
    expect(screen.getByText('Profil')).toBeInTheDocument();
    expect(screen.getByText('Admin')).toBeInTheDocument();
  });

  it('marks the play/home link as active when pathname is /', () => {
    vi.mocked(useDevMode).mockReturnValue(false);
    renderWithProviders(<AppTopNav />);
    const playLink = screen.getByText('Jouer');
    expect(playLink).toHaveAttribute('aria-current', 'page');
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

  it('renders UserMenu with correct props when logged in', () => {
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

    renderWithProviders(<AppTopNav />, mockSession);

    const userMenu = screen.getByTestId('user-menu');
    expect(userMenu).toBeInTheDocument();
    expect(userMenu).toHaveAttribute('data-nickname', 'TestPlayer');
    expect(userMenu).toHaveAttribute('data-login', 'testplayer');
  });

  it('renders UserMenu with formatted nickname props when ManiaPlanet codes present', () => {
    const mockSession = {
      user: {
        name: '$fffTestPlayer',
        login: 'testplayer',
        nickname: '$fffTestPlayer',
        path: 'World|Europe|France',
        role: 'player',
      },
      expires: new Date(Date.now() + 86400000).toISOString(),
    };

    renderWithProviders(<AppTopNav />, mockSession);

    const userMenu = screen.getByTestId('user-menu');
    expect(userMenu).toBeInTheDocument();
    expect(userMenu).toHaveAttribute('data-nickname', '$fffTestPlayer');
  });
});
