import { NextIntlClientProvider } from 'next-intl';
import { ThemeProvider } from '@pixel-series/design-system-neumorphic';
import { SessionProvider } from 'next-auth/react';
import messages from '../../../messages/fr.json';

/**
 * Wraps a component with all required providers for testing.
 * Includes: NextIntlClientProvider, SessionProvider, ThemeProvider.
 */
export function TestProviders({ children, session = null }: {
  children: React.ReactNode;
  session?: Parameters<typeof SessionProvider>[0]['session'];
}) {
  return (
    <NextIntlClientProvider locale="fr" messages={messages}>
      <SessionProvider session={session}>
        <ThemeProvider defaultTheme="dark">
          {/* eslint-disable-next-line @typescript-eslint/no-explicit-any */}
          {children as any}
        </ThemeProvider>
      </SessionProvider>
    </NextIntlClientProvider>
  );
}
