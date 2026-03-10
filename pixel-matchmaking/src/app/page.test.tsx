import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { configure } from 'mobx';
import { TestProviders } from '@/shared/test/intl-wrapper';

configure({ enforceActions: 'never' });

const mockPush = vi.fn();

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: mockPush }),
  usePathname: () => '/',
  useSearchParams: () => ({ get: () => null }),
}));

// Default: unauthenticated session
let mockSession: ReturnType<typeof vi.fn> = vi.fn(() => ({
  data: null,
  status: 'unauthenticated',
}));

vi.mock('next-auth/react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('next-auth/react')>();
  return {
    ...actual,
    useSession: () => mockSession(),
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
import Home from './page';

beforeEach(() => {
  vi.clearAllMocks();
  (matchmakingStore as { searching: boolean; queueCount: number | null }).searching = false;
  (matchmakingStore as { searching: boolean; queueCount: number | null }).queueCount = null;
  mockSession = vi.fn(() => ({ data: null, status: 'unauthenticated' }));
});

describe('Home page (matchmaking hub)', () => {
  it('renders the main heading', () => {
    render(<TestProviders><Home /></TestProviders>);
    expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument();
  });

  it('renders the search button', () => {
    render(<TestProviders><Home /></TestProviders>);
    expect(screen.getByRole('button', { name: /rechercher un match/i })).toBeInTheDocument();
  });

  it('renders the ongoing matches section heading', () => {
    render(<TestProviders><Home /></TestProviders>);
    expect(screen.getByText(/matchs en cours/i)).toBeInTheDocument();
  });

  it('renders 3 ongoing match cards', () => {
    render(<TestProviders><Home /></TestProviders>);
    // Each card has a map name as its title (h3)
    expect(screen.getByText('Stadium A1')).toBeInTheDocument();
    expect(screen.getByText('Canyon Rush')).toBeInTheDocument();
    expect(screen.getByText('Valley Core')).toBeInTheDocument();
  });

  it('shows team names in match cards', () => {
    render(<TestProviders><Home /></TestProviders>);
    expect(screen.getByText('Pixel Strikers')).toBeInTheDocument();
    expect(screen.getByText('Neon Wolves')).toBeInTheDocument();
  });

  it('unauthenticated: search button redirects to signin', () => {
    render(<TestProviders><Home /></TestProviders>);
    fireEvent.click(screen.getByRole('button', { name: /rechercher un match/i }));
    expect(mockPush).toHaveBeenCalledWith('/auth/signin');
    expect(matchmakingStore.startSearch).not.toHaveBeenCalled();
  });

  it('authenticated: search button calls startSearch', () => {
    mockSession = vi.fn(() => ({
      data: { user: { login: 'testlogin', nickname: 'TestPlayer' } },
      status: 'authenticated',
    }));

    render(<TestProviders><Home /></TestProviders>);
    fireEvent.click(screen.getByRole('button', { name: /rechercher un match/i }));
    expect(matchmakingStore.startSearch).toHaveBeenCalledWith('testlogin');
    expect(mockPush).not.toHaveBeenCalled();
  });

  it('shows searching state when store.searching is true', () => {
    (matchmakingStore as { searching: boolean }).searching = true;

    render(<TestProviders><Home /></TestProviders>);
    expect(screen.getByText(/recherche en cours/i)).toBeInTheDocument();
  });

  it('shows queue count when searching and queueCount is set', () => {
    (matchmakingStore as { searching: boolean; queueCount: number }).searching = true;
    (matchmakingStore as { searching: boolean; queueCount: number }).queueCount = 5;

    render(<TestProviders><Home /></TestProviders>);
    expect(screen.getByText(/joueurs en file/i)).toBeInTheDocument();
  });

  it('shows cancel button when searching', () => {
    (matchmakingStore as { searching: boolean }).searching = true;

    render(<TestProviders><Home /></TestProviders>);
    expect(screen.getByRole('button', { name: /annuler la recherche/i })).toBeInTheDocument();
  });

  it('authenticated + searching: cancel button calls cancelSearch', () => {
    mockSession = vi.fn(() => ({
      data: { user: { login: 'testlogin', nickname: 'TestPlayer' } },
      status: 'authenticated',
    }));
    (matchmakingStore as { searching: boolean }).searching = true;

    render(<TestProviders><Home /></TestProviders>);
    fireEvent.click(screen.getByRole('button', { name: /annuler la recherche/i }));
    expect(matchmakingStore.cancelSearch).toHaveBeenCalledWith('testlogin');
  });
});
