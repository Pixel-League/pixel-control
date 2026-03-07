import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ThemeProvider } from '@pixel-series/design-system-neumorphic';
import Home from './page';

function renderWithProviders(ui: React.ReactElement) {
  return render(
    <ThemeProvider defaultTheme="dark">
      {ui}
    </ThemeProvider>,
  );
}

describe('Home page', () => {
  it('renders the main heading', () => {
    renderWithProviders(<Home />);
    expect(screen.getByText('Pixel MatchMaking')).toBeInTheDocument();
  });

  it('renders the description text', () => {
    renderWithProviders(<Home />);
    expect(
      screen.getByText(/Plateforme de matchmaking competitif/),
    ).toBeInTheDocument();
  });

  it('renders DS badges', () => {
    renderWithProviders(<Home />);
    expect(screen.getByText('Foundation')).toBeInTheDocument();
    expect(screen.getByText('Online')).toBeInTheDocument();
  });

  it('renders DS cards with titles', () => {
    renderWithProviders(<Home />);
    expect(screen.getByText('Matchmaking')).toBeInTheDocument();
    expect(screen.getByText('Classement')).toBeInTheDocument();
  });
});
