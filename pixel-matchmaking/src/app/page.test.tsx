import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { TestProviders } from '@/shared/test/intl-wrapper';
import Home from './page';

const mockPush = vi.fn();

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: mockPush }),
  usePathname: () => '/',
  useSearchParams: () => ({ get: () => null }),
}));

describe('Home page', () => {
  it('renders the main heading', () => {
    render(<TestProviders><Home /></TestProviders>);
    expect(screen.getByText('Pixel MatchMaking')).toBeInTheDocument();
  });

  it('renders the description text', () => {
    render(<TestProviders><Home /></TestProviders>);
    expect(
      screen.getByText(/Plateforme de matchmaking compétitif/),
    ).toBeInTheDocument();
  });

  it('renders DS badges', () => {
    render(<TestProviders><Home /></TestProviders>);
    expect(screen.getByText('Foundation')).toBeInTheDocument();
    expect(screen.getByText('Online')).toBeInTheDocument();
  });

  it('renders DS cards with titles', () => {
    render(<TestProviders><Home /></TestProviders>);
    expect(screen.getByText('Matchmaking')).toBeInTheDocument();
    expect(screen.getByText('Classement')).toBeInTheDocument();
  });

  it('Jouer button navigates to /play', () => {
    render(<TestProviders><Home /></TestProviders>);
    const playButton = screen.getByRole('button', { name: 'Jouer' });
    fireEvent.click(playButton);
    expect(mockPush).toHaveBeenCalledWith('/play');
  });

  it('Voir le classement button navigates to /leaderboard', () => {
    render(<TestProviders><Home /></TestProviders>);
    const leaderboardButton = screen.getByRole('button', { name: 'Voir le classement' });
    fireEvent.click(leaderboardButton);
    expect(mockPush).toHaveBeenCalledWith('/leaderboard');
  });
});
