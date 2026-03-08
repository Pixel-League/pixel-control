import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { NextIntlClientProvider } from 'next-intl';
import { ThemeProvider } from '@pixel-series/design-system-neumorphic';
import { SessionProvider } from 'next-auth/react';
import { UserMenu } from './UserMenu';
import messages from '../../../../messages/fr.json';

const mockPush = vi.fn();
const mockSignOut = vi.fn();

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: mockPush }),
  usePathname: () => '/',
  useSearchParams: () => ({ get: () => null }),
}));

vi.mock('next-auth/react', () => ({
  signOut: (opts: unknown) => mockSignOut(opts),
  useSession: () => ({ data: null, status: 'unauthenticated' }),
  SessionProvider: ({ children }: { children: React.ReactNode }) => children,
}));

function renderUserMenu(props: { nickname: string; login: string }) {
  return render(
    <NextIntlClientProvider locale="fr" messages={messages}>
      <SessionProvider session={null}>
        <ThemeProvider defaultTheme="light">
          {/* eslint-disable-next-line @typescript-eslint/no-explicit-any */}
          <UserMenu {...(props as any)} />
        </ThemeProvider>
      </SessionProvider>
    </NextIntlClientProvider>,
  );
}

describe('UserMenu', () => {
  it('renders avatar with initials from plain nickname', () => {
    renderUserMenu({ nickname: 'TestPlayer', login: 'testplayer' });
    // Avatar renders initials "TE" from "TestPlayer"
    expect(screen.getByLabelText('TestPlayer')).toBeInTheDocument();
  });

  it('renders avatar with initials from stripped MP-formatted nickname', () => {
    // $fffTestPlayer strips to "TestPlayer", initials = "TE"
    renderUserMenu({ nickname: '$fffTestPlayer', login: 'testplayer' });
    expect(screen.getByLabelText('TestPlayer')).toBeInTheDocument();
  });

  it('falls back to login initials when nickname strips to empty', () => {
    // A pure-style code with no text strips to ''
    renderUserMenu({ nickname: '$o$n', login: 'mylogin' });
    expect(screen.getByLabelText('mylogin')).toBeInTheDocument();
  });

  it('opens dropdown when avatar is clicked', async () => {
    const user = userEvent.setup();
    renderUserMenu({ nickname: 'TestPlayer', login: 'testplayer' });

    const trigger = screen.getByRole('button', { name: /TestPlayer/i });
    await user.click(trigger);

    expect(screen.getByRole('menu')).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Profil' })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Déconnexion' })).toBeInTheDocument();
  });

  it('navigates to /me when "Profil" is clicked', async () => {
    const user = userEvent.setup();
    renderUserMenu({ nickname: 'TestPlayer', login: 'testplayer' });

    await user.click(screen.getByRole('button', { name: /TestPlayer/i }));
    await user.click(screen.getByRole('menuitem', { name: 'Profil' }));

    expect(mockPush).toHaveBeenCalledWith('/me');
  });

  it('calls signOut when "Déconnexion" is clicked', async () => {
    const user = userEvent.setup();
    renderUserMenu({ nickname: 'TestPlayer', login: 'testplayer' });

    await user.click(screen.getByRole('button', { name: /TestPlayer/i }));
    await user.click(screen.getByRole('menuitem', { name: 'Déconnexion' }));

    expect(mockSignOut).toHaveBeenCalledWith({ callbackUrl: '/' });
  });
});
