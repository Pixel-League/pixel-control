import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { configure } from 'mobx';
import { TestProviders } from '@/shared/test/intl-wrapper';

configure({ enforceActions: 'never' });

vi.mock('next-auth/react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('next-auth/react')>();
  return {
    ...actual,
    useSession: () => ({
      data: { user: { login: 'testlogin', nickname: 'TestPlayer' } },
      status: 'authenticated',
    }),
  };
});

vi.mock('@/features/matchmaking/store/matchmakingStore', () => ({
  matchmakingStore: {
    searching: false,
    queueCount: null,
    startSearch: vi.fn().mockResolvedValue(undefined),
    cancelSearch: vi.fn().mockResolvedValue(undefined),
    updateQueueCount: vi.fn(),
  },
}));

import { matchmakingStore } from '@/features/matchmaking/store/matchmakingStore';
import { QuickMatchCard } from './QuickMatchCard';

beforeEach(() => {
  vi.clearAllMocks();
  (matchmakingStore as { searching: boolean; queueCount: number | null }).searching = false;
  (matchmakingStore as { searching: boolean; queueCount: number | null }).queueCount = null;
});

describe('QuickMatchCard', () => {
  it('renders the search button when not searching', () => {
    render(
      <TestProviders>
        <QuickMatchCard />
      </TestProviders>,
    );

    expect(screen.getByRole('button')).toBeDefined();
  });

  it('shows searching state when store.searching is true', () => {
    (matchmakingStore as { searching: boolean }).searching = true;

    render(
      <TestProviders>
        <QuickMatchCard />
      </TestProviders>,
    );

    expect(screen.getByText(/recherche en cours/i)).toBeDefined();
  });

  it('shows queue count when searching and queueCount is set', () => {
    (matchmakingStore as { searching: boolean; queueCount: number }).searching = true;
    (matchmakingStore as { searching: boolean; queueCount: number }).queueCount = 5;

    render(
      <TestProviders>
        <QuickMatchCard />
      </TestProviders>,
    );

    expect(screen.getByText(/joueurs en file/i)).toBeDefined();
  });

  it('calls startSearch when button is clicked while not searching', async () => {
    render(
      <TestProviders>
        <QuickMatchCard />
      </TestProviders>,
    );

    const button = screen.getByRole('button');
    fireEvent.click(button);

    expect(matchmakingStore.startSearch).toHaveBeenCalledWith('testlogin');
  });

  it('calls cancelSearch when button is clicked while searching', async () => {
    (matchmakingStore as { searching: boolean }).searching = true;

    render(
      <TestProviders>
        <QuickMatchCard />
      </TestProviders>,
    );

    const button = screen.getByRole('button');
    fireEvent.click(button);

    expect(matchmakingStore.cancelSearch).toHaveBeenCalledWith('testlogin');
  });
});
