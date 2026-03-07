import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { TestProviders } from '@/test/intl-wrapper';
import Home from './page';

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
});
