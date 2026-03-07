import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { NextIntlClientProvider } from 'next-intl';
import { ThemeProvider } from '@pixel-series/design-system-neumorphic';
import { LanguageSelector } from './LanguageSelector';
import messages from '../../messages/fr.json';

vi.mock('next/navigation', () => ({
  useRouter: () => ({
    push: vi.fn(),
    replace: vi.fn(),
    prefetch: vi.fn(),
    refresh: vi.fn(),
  }),
}));

vi.mock('@/i18n/actions', () => ({
  setLocale: vi.fn(),
}));

function renderSelector(locale = 'fr') {
  return render(
    <NextIntlClientProvider locale={locale} messages={messages}>
      <ThemeProvider defaultTheme="dark">
        <LanguageSelector />
      </ThemeProvider>
    </NextIntlClientProvider>,
  );
}

describe('LanguageSelector', () => {
  it('renders the toggle button', () => {
    renderSelector();
    // When current locale is FR, button shows EN (to switch to)
    expect(screen.getByText('EN')).toBeInTheDocument();
  });

  it('shows FR when current locale is EN', () => {
    renderSelector('en');
    expect(screen.getByText('FR')).toBeInTheDocument();
  });

  it('renders a button element', () => {
    renderSelector();
    const button = screen.getByRole('button');
    expect(button).toBeInTheDocument();
  });
});
